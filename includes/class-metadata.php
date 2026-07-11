<?php
/**
 * OAuth discovery metadata (RFC 8414 / RFC 9728) + well-known routing.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the two discovery documents Claude fetches to bootstrap the OAuth flow.
 *
 * WordPress does not route `/.well-known/...` through the REST API, so we
 * intercept these paths very early on `init` (a cheap string comparison) and
 * emit JSON directly. This works regardless of permalink settings.
 */
final class Metadata {

	/**
	 * Hook the well-known interceptor.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_serve_well_known' ), 0 );
	}

	/**
	 * Emit a discovery document if the request targets a well-known path.
	 */
	public function maybe_serve_well_known(): void {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$path        = untrailingslashit( $path );

		// Subdirectory installs: the well-known path arrives as
		// "/blog/.well-known/..." — strip the install prefix before matching.
		$home_path = Settings::home_path();
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		// Index permalinks route everything through "/index.php/...".
		if ( 0 === strpos( $path, '/index.php/' ) ) {
			$path = substr( $path, strlen( '/index.php' ) );
		}

		// Prefix matches: RFC 8414 / RFC 9728 allow the resource path to be
		// appended to the well-known path, so match both bare and suffixed forms.
		if (
			0 === strpos( $path, '/.well-known/oauth-authorization-server' ) ||
			0 === strpos( $path, '/.well-known/openid-configuration' )
		) {
			$this->send_json( $this->authorization_server_metadata() );
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			$this->send_json( $this->protected_resource_metadata() );
		}
	}

	/**
	 * RFC 8414 Authorization Server Metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function authorization_server_metadata(): array {
		return array(
			'issuer'                                => Settings::issuer(),
			'authorization_endpoint'                => $this->authorize_endpoint(),
			'token_endpoint'                        => rest_url( 'arzo-mcp/v1/token' ),
			'registration_endpoint'                 => rest_url( 'arzo-mcp/v1/register' ),
			'scopes_supported'                      => array( Settings::SCOPE ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'service_documentation'                 => 'https://github.com/yasirshabbirservices/arzo-mcp-connect',
		);
	}

	/**
	 * RFC 9728 Protected Resource Metadata for this site's MCP endpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function protected_resource_metadata(): array {
		return array(
			'resource'              => Settings::resource_url(),
			'authorization_servers' => array( Settings::issuer() ),
			'scopes_supported'      => array( Settings::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'         => get_bloginfo( 'name' ) . ' — MCP',
		);
	}

	/**
	 * Absolute URL Claude should fetch for protected-resource metadata.
	 */
	public function protected_resource_metadata_url(): string {
		$suffix    = Settings::resource_path();
		$home_path = Settings::home_path();
		if ( '' !== $home_path && 0 === strpos( $suffix, $home_path . '/' ) ) {
			$suffix = substr( $suffix, strlen( $home_path ) );
		}
		return home_url( '/.well-known/oauth-protected-resource' . $suffix );
	}

	/**
	 * The authorization endpoint (admin-post based — permalink-independent).
	 */
	public function authorize_endpoint(): string {
		return admin_url( 'admin-post.php' ) . '?action=arzo_mcp_authorize';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function send_json( array $data ): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'Access-Control-Allow-Origin: *' );
		}
		echo wp_json_encode( $data );
		exit;
	}
}
