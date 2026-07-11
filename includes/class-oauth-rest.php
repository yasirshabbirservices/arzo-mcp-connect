<?php
/**
 * OAuth REST endpoints: Dynamic Client Registration + token.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `/wp-json/arzo-mcp/v1/register` (RFC 7591) and `/token`
 * (authorization_code + refresh_token grants). Both are public endpoints; the
 * security comes from PKCE, redirect-URI allowlisting, and short-lived codes.
 */
final class OAuth_REST {

	/** @var OAuth_Store */
	private $store;

	/** @var Tokens */
	private $tokens;

	public function __construct( OAuth_Store $store, Tokens $tokens ) {
		$this->store  = $store;
		$this->tokens = $tokens;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'arzo-mcp/v1',
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'arzo-mcp/v1',
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'arzo-mcp/v1',
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_diagnostics' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Connectivity self-test: reports whether an Authorization header sent to
	 * this endpoint survived the web server. Never echoes the header value.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_diagnostics(): \WP_REST_Response {
		$auth     = Bearer_Auth::authorization_header();
		$response = new \WP_REST_Response(
			array(
				'version'                       => ARZO_MCP_VERSION,
				'authorization_header_received' => '' !== $auth['value'],
				'authorization_header_source'   => $auth['source'],
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Dynamic Client Registration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_register( \WP_REST_Request $request ): \WP_REST_Response {
		// Read from any source (JSON body, form body, or query) so registration
		// is robust to how the client encodes the request.
		$redirect_uris = $request->get_param( 'redirect_uris' );
		if ( ! is_array( $redirect_uris ) ) {
			$redirect_uris = array();
		}
		$redirect_uris = array_values( array_filter( $redirect_uris, 'is_string' ) );

		if ( empty( $redirect_uris ) ) {
			return $this->error( 'invalid_redirect_uri', 'At least one redirect_uri is required', 400 );
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! $this->is_redirect_allowed( $uri ) ) {
				return $this->error( 'invalid_redirect_uri', 'redirect_uri host not allowed: ' . $uri, 400 );
			}
		}

		$client_name = $request->get_param( 'client_name' );
		$client_name = is_string( $client_name ) && '' !== $client_name ? $client_name : 'MCP Client';

		$client_id = 'arzo_' . bin2hex( random_bytes( 16 ) );
		$client    = array(
			'client_id'                  => $client_id,
			'redirect_uris'              => $redirect_uris,
			'client_name'                => $client_name,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'scope'                      => Settings::SCOPE,
			'created_at'                 => time(),
		);
		$this->store->save_client( $client );
		Debug::log(
			'register',
			array(
				'client_id'     => $client_id,
				'redirect_uris' => $redirect_uris,
				'client_name'   => $client_name,
			)
		);

		$response = new \WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_id_issued_at'        => $client['created_at'],
				'redirect_uris'              => $redirect_uris,
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => $client['grant_types'],
				'response_types'             => $client['response_types'],
				'scope'                      => Settings::SCOPE,
				'client_name'                => $client['client_name'],
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Token endpoint (authorization_code + refresh_token).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_token( \WP_REST_Request $request ): \WP_REST_Response {
		$grant_type   = (string) $request->get_param( 'grant_type' );
		$content_type = $request->get_content_type();
		Debug::log(
			'token_request',
			array(
				'grant_type'   => '' !== $grant_type ? $grant_type : '(missing)',
				'content_type' => is_array( $content_type ) && isset( $content_type['value'] ) ? $content_type['value'] : '',
				'has_code'     => '' !== (string) $request->get_param( 'code' ),
				'has_verifier' => '' !== (string) $request->get_param( 'code_verifier' ),
				'client_id'    => (string) $request->get_param( 'client_id' ),
			)
		);

		if ( 'authorization_code' === $grant_type ) {
			return $this->grant_authorization_code( $request );
		}
		if ( 'refresh_token' === $grant_type ) {
			return $this->grant_refresh_token( $request );
		}
		return $this->error( 'unsupported_grant_type', 'Unsupported grant_type', 400 );
	}

	private function grant_authorization_code( \WP_REST_Request $request ): \WP_REST_Response {
		$code_value    = (string) $request->get_param( 'code' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );

		if ( '' === $code_value || '' === $code_verifier || '' === $client_id ) {
			return $this->error( 'invalid_request', 'Missing code, code_verifier, or client_id', 400 );
		}

		$code = $this->store->consume_code( $code_value );
		if ( null === $code ) {
			Debug::log( 'token_fail', array( 'reason' => 'code_invalid_or_expired', 'code_fp' => Debug::fingerprint( $code_value ) ) );
			return $this->error( 'invalid_grant', 'Authorization code is invalid or expired', 400 );
		}
		if ( (string) $code['client_id'] !== $client_id ) {
			Debug::log( 'token_fail', array( 'reason' => 'client_mismatch', 'code_client' => (string) $code['client_id'], 'sent_client' => $client_id ) );
			return $this->error( 'invalid_grant', 'Authorization code was issued to another client', 400 );
		}
		if ( '' !== $redirect_uri && (string) $code['redirect_uri'] !== $redirect_uri ) {
			Debug::log( 'token_fail', array( 'reason' => 'redirect_uri_mismatch', 'code_uri' => (string) $code['redirect_uri'], 'sent_uri' => $redirect_uri ) );
			return $this->error( 'invalid_grant', 'redirect_uri mismatch', 400 );
		}
		if ( ! PKCE::verify_s256( $code_verifier, (string) $code['code_challenge'] ) ) {
			Debug::log( 'token_fail', array( 'reason' => 'pkce_failed', 'verifier_len' => strlen( $code_verifier ) ) );
			return $this->error( 'invalid_grant', 'PKCE verification failed', 400 );
		}

		$token_set = $this->tokens->issue_token_set(
			(int) $code['user_id'],
			$client_id,
			(string) $code['scope'],
			(string) $code['resource']
		);
		Debug::log(
			'token_issued',
			array(
				'user_id'  => (int) $code['user_id'],
				'aud'      => (string) $code['resource'],
				'access_fp' => Debug::fingerprint( (string) $token_set['access_token'] ),
			)
		);
		return $this->token_response( $token_set );
	}

	private function grant_refresh_token( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		$client_id     = (string) $request->get_param( 'client_id' );
		if ( '' === $refresh_token || '' === $client_id ) {
			return $this->error( 'invalid_request', 'Missing refresh_token or client_id', 400 );
		}
		$token_set = $this->tokens->refresh( $refresh_token, $client_id );
		if ( null === $token_set ) {
			return $this->error( 'invalid_grant', 'The refresh token is invalid or expired', 400 );
		}
		return $this->token_response( $token_set );
	}

	/**
	 * @param array<string,mixed> $token_set
	 */
	private function token_response( array $token_set ): \WP_REST_Response {
		$response = new \WP_REST_Response( $token_set, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	private function error( string $code, string $description, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Redirect URIs are restricted to Claude's callback host (and loopback for
	 * native clients / local testing).
	 */
	private function is_redirect_allowed( string $uri ): bool {
		$host   = wp_parse_url( $uri, PHP_URL_HOST );
		$scheme = wp_parse_url( $uri, PHP_URL_SCHEME );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$loopback = in_array( $host, array( 'localhost', '127.0.0.1' ), true );
		$allowed  = array_merge( array( 'claude.ai', 'claude.com' ), self::extra_allowed_hosts() );
		if ( $loopback ) {
			return true;
		}
		return in_array( $host, $allowed, true ) && 'https' === $scheme;
	}

	/**
	 * @return string[]
	 */
	private static function extra_allowed_hosts(): array {
		/**
		 * Filter the list of additional allowed OAuth redirect hosts.
		 *
		 * @param string[] $hosts Extra hostnames permitted as redirect targets.
		 */
		$hosts = apply_filters( 'arzo_mcp_allowed_redirect_hosts', array() );
		return is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();
	}
}
