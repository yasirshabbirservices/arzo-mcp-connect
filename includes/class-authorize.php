<?php
/**
 * Authorization endpoint + consent (admin-post based).
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the browser-facing `/authorize` step.
 *
 * We route it through `admin-post.php` (a stable, permalink-independent URL that
 * can render HTML and issue redirects) rather than the REST API. The user
 * authenticates with their normal WordPress login; the issued token then carries
 * that user's identity, so Claude acts with exactly their WordPress permissions.
 */
final class Authorize {

	/** @var OAuth_Store */
	private $store;

	public function __construct( OAuth_Store $store ) {
		$this->store = $store;
	}

	public function register(): void {
		add_action( 'admin_post_arzo_mcp_authorize', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_arzo_mcp_authorize', array( $this, 'handle_logged_out' ) );
	}

	/**
	 * Not logged in: bounce through the WordPress login, returning here after.
	 */
	public function handle_logged_out(): void {
		$return = $this->current_url();
		wp_safe_redirect( wp_login_url( $return ) );
		exit;
	}

	/**
	 * Logged in: validate the request, then either show consent (GET) or issue an
	 * authorization code (POST approve).
	 */
	public function handle(): void {
		$params = $this->collect_params();

		$client = $this->store->get_client( $params['client_id'] );
		if ( null === $client ) {
			wp_die( esc_html__( 'Unknown OAuth client.', 'arzo-mcp-connect' ), 'Authorization error', array( 'response' => 400 ) );
		}

		$redirect_uri = $params['redirect_uri'];
		$registered   = isset( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] ) ? $client['redirect_uris'] : array();
		if ( '' === $redirect_uri || ! in_array( $redirect_uri, $registered, true ) ) {
			wp_die( esc_html__( 'The redirect_uri is not registered for this client.', 'arzo-mcp-connect' ), 'Authorization error', array( 'response' => 400 ) );
		}

		// From here, errors can be safely redirected back to the client.
		if ( 'code' !== $params['response_type'] ) {
			$this->redirect_error( $redirect_uri, 'unsupported_response_type', 'Only response_type=code is supported', $params['state'] );
		}
		if ( '' === $params['code_challenge'] || 'S256' !== $params['code_challenge_method'] ) {
			$this->redirect_error( $redirect_uri, 'invalid_request', 'PKCE with S256 is required', $params['state'] );
		}

		$resource = '' !== $params['resource'] ? $params['resource'] : Settings::resource_url();
		if ( $resource !== Settings::resource_url() ) {
			$this->redirect_error( $redirect_uri, 'invalid_target', 'Unknown resource', $params['state'] );
		}

		// Approve (POST) → issue a single-use code bound to this user + PKCE.
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && 'approve' === $params['arzo_consent'] ) {
			check_admin_referer( 'arzo_mcp_consent', '_arzo_nonce' );

			$user = wp_get_current_user();
			$code = 'ac_' . bin2hex( random_bytes( 24 ) );
			$this->store->save_code(
				$code,
				array(
					'client_id'      => $params['client_id'],
					'redirect_uri'   => $redirect_uri,
					'code_challenge' => $params['code_challenge'],
					'scope'          => '' !== $params['scope'] ? $params['scope'] : Settings::SCOPE,
					'resource'       => $resource,
					'user_id'        => (int) $user->ID,
				),
				Settings::CODE_TTL
			);

			$target = add_query_arg(
				array_filter(
					array(
						'code'  => $code,
						'state' => '' !== $params['state'] ? $params['state'] : null,
					)
				),
				$redirect_uri
			);
			wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated against the client's registered allowlist.
			exit;
		}

		// Otherwise render the consent screen.
		$this->render_consent( $client, $params );
	}

	/**
	 * @param array<string,mixed>  $client
	 * @param array<string,string> $params
	 */
	private function render_consent( array $client, array $params ): void {
		$user   = wp_get_current_user();
		$hidden = array(
			'action'                => 'arzo_mcp_authorize',
			'response_type'         => 'code',
			'client_id'             => $params['client_id'],
			'redirect_uri'          => $params['redirect_uri'],
			'code_challenge'        => $params['code_challenge'],
			'code_challenge_method' => 'S256',
			'scope'                 => '' !== $params['scope'] ? $params['scope'] : Settings::SCOPE,
			'resource'              => '' !== $params['resource'] ? $params['resource'] : Settings::resource_url(),
		);
		if ( '' !== $params['state'] ) {
			$hidden['state'] = $params['state'];
		}

		$client_name = isset( $client['client_name'] ) ? (string) $client['client_name'] : 'MCP Client';
		$user_label  = $user->display_name ? $user->display_name : $user->user_login;

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo Consent::render( $hidden, admin_url( 'admin-post.php' ), $client_name, (string) $user_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Consent::render escapes all interpolated values.
		exit;
	}

	/**
	 * @return array<string,string>
	 */
	private function collect_params(): array {
		$get = static function ( string $key ): string {
			if ( ! isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- consent POST is nonce-checked in handle().
				return '';
			}
			return trim( (string) wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are validated/compared exactly below.
		};
		return array(
			'client_id'             => sanitize_text_field( $get( 'client_id' ) ),
			'redirect_uri'          => esc_url_raw( $get( 'redirect_uri' ) ),
			'response_type'         => sanitize_text_field( $get( 'response_type' ) ),
			'code_challenge'        => sanitize_text_field( $get( 'code_challenge' ) ),
			'code_challenge_method' => sanitize_text_field( $get( 'code_challenge_method' ) ),
			'scope'                 => sanitize_text_field( $get( 'scope' ) ),
			'state'                 => sanitize_text_field( $get( 'state' ) ),
			'resource'              => esc_url_raw( $get( 'resource' ) ),
			'arzo_consent'          => sanitize_text_field( $get( 'arzo_consent' ) ),
		);
	}

	private function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$target = add_query_arg(
			array_filter(
				array(
					'error'             => $error,
					'error_description' => rawurlencode( $description ),
					'state'             => '' !== $state ? $state : null,
				)
			),
			$redirect_uri
		);
		wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated client redirect URI.
		exit;
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return esc_url_raw( $scheme . $host . $uri );
	}
}
