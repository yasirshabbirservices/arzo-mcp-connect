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
		// Only honor our tokens on requests to the MCP resource path.
		if ( ! $this->is_mcp_request() ) {
			return $user_id;
		}
		$token = $this->bearer_token();
		if ( '' === $token ) {
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
		if ( null !== $result ) {
			return $result;
		}
		$route  = (string) $request->get_route();
		$prefix = '/' . trim( (string) strtok( Settings::server_route(), '/' ), '/' ) . '/';
		if ( 0 !== strpos( $route, $prefix ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}

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
		return $response;
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
	 * Extract a bearer token from the Authorization header (with common
	 * server-variable fallbacks for hosts that strip it).
	 */
	private function bearer_token(): string {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			foreach ( (array) $headers as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					$header = (string) $value;
					break;
				}
			}
		}
		if ( '' === $header ) {
			return '';
		}
		if ( preg_match( '/Bearer\s+(.+)/i', $header, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}
}
