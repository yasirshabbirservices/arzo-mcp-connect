<?php
/**
 * Bearer authentication + 401 discovery challenge for the MCP endpoint.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges OAuth access tokens to WordPress identity.
 *
 * On a request to the MCP endpoint carrying a valid bearer token, we resolve the
 * WordPress user via `determine_current_user`, so the MCP Adapter's own
 * capability checks (`current_user_can`) authorize the request naturally. When
 * the MCP endpoint is hit without a valid token, we return `401` with an
 * RFC 9728 `WWW-Authenticate` header so Claude can discover the OAuth server.
 */
final class Bearer_Auth {

	/** @var Tokens */
	private $tokens;

	/** @var Metadata */
	private $metadata;

	public function __construct( Tokens $tokens, Metadata $metadata ) {
		$this->tokens   = $tokens;
		$this->metadata = $metadata;
	}

	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 20 );
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_challenge' ), 10, 3 );
	}

	/**
	 * Resolve the WordPress user from a bearer token on MCP requests.
	 *
	 * @param int|false $user_id Current determination.
	 * @return int|false
	 */
	public function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		$token = $this->bearer_token();
		// Only honor our tokens on requests to the MCP resource path.
		if ( ! $this->is_mcp_request() ) {
			// A JWT bearer token on a request we did NOT classify as MCP means our
			// path matcher and the client's URL disagree — log it so we can see.
			// Ignore non-JWT tokens (a JWT has three dot-separated segments); this
			// filters out our own settings-page diagnostics probe, which sends a
			// plain, dot-free token to /wp-json/arzo-mcp/v1/diagnostics on purpose.
			if ( '' !== $token && 2 === substr_count( $token, '.' ) ) {
				Debug::log(
					'auth_path_mismatch',
					array(
						'path'          => (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ),
						'resource_path' => Settings::resource_path(),
					)
				);
			}
			return $user_id;
		}
		$auth = self::authorization_header();
		if ( '' === $token ) {
			Debug::log(
				'mcp_no_token',
				array(
					'header_source' => $auth['source'],
					'header_present' => '' !== $auth['value'],
				)
			);
			return $user_id;
		}
		$resolved = $this->tokens->verify( $token, Settings::resource_url() );
		return null !== $resolved ? $resolved : $user_id;
	}

	/**
	 * Emit a 401 discovery challenge for unauthenticated MCP requests.
	 *
	 * @param mixed            $result  Existing short-circuit result.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function maybe_challenge( $result, $server, $request ) {
		unset( $server );
		$route  = (string) $request->get_route();
		$prefix = '/' . trim( (string) strtok( Settings::server_route(), '/' ), '/' ) . '/';
		$on_mcp = 0 === strpos( $route, $prefix );

		if ( null !== $result ) {
			// Another plugin (maintenance mode, a security/WAF shim, a cache)
			// already short-circuited this request. If it happened on the MCP
			// route, that is very likely why authenticated calls never reach us.
			if ( $on_mcp ) {
				$status = $result instanceof \WP_REST_Response ? $result->get_status()
					: ( is_wp_error( $result ) ? (string) $result->get_error_code() : 'unknown' );
				Debug::log( 'mcp_short_circuited', array( 'route' => $route, 'result' => $status ) );
			}
			return $result;
		}
		if ( ! $on_mcp ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			Debug::log( 'mcp_authorized', array( 'route' => $route, 'user_id' => get_current_user_id() ) );
			return $result;
		}

		$auth = self::authorization_header();
		Debug::log(
			'challenge_issued',
			array(
				'route'          => $route,
				'header_present' => '' !== $auth['value'],
				'header_source'  => $auth['source'],
				'is_bearer'      => '' !== $this->bearer_token(),
			)
		);

		$response = new \WP_REST_Response(
			array(
				'error'             => 'unauthorized',
				'error_description' => 'Authentication required',
			),
			401
		);
		$response->header(
			'WWW-Authenticate',
			'Bearer resource_metadata="' . esc_url_raw( $this->metadata->protected_resource_metadata_url() ) . '"'
		);
		// Never let a proxy or page cache (Cloudflare, LiteSpeed, Varnish) store
		// this 401 for the MCP URL — a cached challenge would be replayed to the
		// authenticated retry, so it would never reach PHP and the connection
		// would fail even with a valid token.
		self::no_cache_headers( $response );
		return $response;
	}

	/**
	 * Mark a REST response uncacheable across the common WordPress cache layers.
	 *
	 * @param \WP_REST_Response $response Response to annotate.
	 */
	private static function no_cache_headers( \WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Is the current request targeting the MCP resource path?
	 *
	 * Must not touch rest_url()/$wp_rewrite: `determine_current_user` can fire
	 * during `plugins_loaded`, before rewrites are initialized.
	 */
	private function is_mcp_request(): bool {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		// Pretty permalinks: match the REST path, tolerating "/index.php" installs.
		$resource = Settings::resource_path();
		if ( '' !== $resource && 0 === strpos( $path, $resource ) ) {
			return true;
		}
		$home_path = Settings::home_path();
		$index_form = $home_path . '/index.php' . substr( $resource, strlen( $home_path ) );
		if ( '' !== $resource && 0 === strpos( $path, $index_form ) ) {
			return true;
		}

		// Plain permalinks: the REST API is addressed via "?rest_route=/...".
		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $query_vars );
			$rest_route = isset( $query_vars['rest_route'] ) && is_string( $query_vars['rest_route'] )
				? $query_vars['rest_route']
				: '';
			if ( '' !== $rest_route && 0 === strpos( '/' . ltrim( $rest_route, '/' ), '/' . Settings::server_route() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract a bearer token from the Authorization header.
	 */
	private function bearer_token(): string {
		$header = self::authorization_header()['value'];
		if ( '' === $header ) {
			return '';
		}
		if ( preg_match( '/Bearer\s+(.+)/i', $header, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * The raw Authorization header and where it was found (with common
	 * server-variable fallbacks for hosts that strip it). Also used by the
	 * diagnostics endpoint to report whether the header survives the server.
	 *
	 * @return array{value:string,source:string}
	 */
	public static function authorization_header(): array {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return array(
				'value'  => (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ),
				'source' => 'server',
			);
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return array(
				'value'  => (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
				'source' => 'redirect',
			);
		}
		foreach ( array( 'getallheaders', 'apache_request_headers' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				foreach ( (array) $fn() as $name => $value ) {
					if ( 'authorization' === strtolower( (string) $name ) && '' !== (string) $value ) {
						return array(
							'value'  => (string) $value,
							'source' => $fn,
						);
					}
				}
			}
		}
		return array(
			'value'  => '',
			'source' => 'none',
		);
	}
}
