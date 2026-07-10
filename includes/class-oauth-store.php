<?php
/**
 * Persistence for OAuth clients, authorization codes, and refresh tokens.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Clients are stored in an option (low volume); single-use authorization codes
 * and refresh tokens are stored as transients keyed by a hash of the secret, so
 * the raw secret never becomes a database key and expiry is handled natively.
 */
final class OAuth_Store {

	const OPTION_CLIENTS = 'arzo_mcp_clients';

	/**
	 * Persist (or replace) a registered client.
	 *
	 * @param array<string,mixed> $client Client record including 'client_id'.
	 */
	public function save_client( array $client ): void {
		$clients = $this->clients();
		$clients[ (string) $client['client_id'] ] = $client;
		update_option( self::OPTION_CLIENTS, $clients, false );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_client( string $client_id ): ?array {
		$clients = $this->clients();
		return isset( $clients[ $client_id ] ) && is_array( $clients[ $client_id ] )
			? $clients[ $client_id ]
			: null;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function save_code( string $code, array $data, int $ttl ): void {
		set_transient( $this->code_key( $code ), $data, $ttl );
	}

	/**
	 * Fetch and immediately invalidate an authorization code (single use).
	 *
	 * @return array<string,mixed>|null
	 */
	public function consume_code( string $code ): ?array {
		$key  = $this->code_key( $code );
		$data = get_transient( $key );
		if ( false !== $data ) {
			delete_transient( $key );
			return is_array( $data ) ? $data : null;
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function save_refresh( string $token, array $data, int $ttl ): void {
		set_transient( $this->refresh_key( $token ), $data, $ttl );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function consume_refresh( string $token ): ?array {
		$key  = $this->refresh_key( $token );
		$data = get_transient( $key );
		if ( false !== $data ) {
			delete_transient( $key );
			return is_array( $data ) ? $data : null;
		}
		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function clients(): array {
		$clients = get_option( self::OPTION_CLIENTS, array() );
		return is_array( $clients ) ? $clients : array();
	}

	private function code_key( string $code ): string {
		return 'arzo_mcp_ac_' . hash( 'sha256', $code );
	}

	private function refresh_key( string $token ): string {
		return 'arzo_mcp_rt_' . hash( 'sha256', $token );
	}
}
