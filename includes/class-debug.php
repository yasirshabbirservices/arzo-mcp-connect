<?php
/**
 * Opt-in, privacy-safe diagnostic log for the OAuth + bearer flow.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Records a small ring buffer of flow events (registration, code issuance, token
 * exchange, bearer verification) so a failing Claude connection can be diagnosed
 * without server access. Off by default; secrets (tokens, codes, verifiers, keys)
 * are never stored — only booleans, reasons, client ids, and short fingerprints.
 */
final class Debug {

	const OPTION_ENABLED = 'arzo_mcp_debug';
	const OPTION_LOG     = 'arzo_mcp_debug_log';
	const MAX_ENTRIES    = 60;

	/**
	 * Is diagnostic logging switched on?
	 */
	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '' );
	}

	/**
	 * Append one event. No-op unless logging is enabled.
	 *
	 * @param string              $event   Short event slug.
	 * @param array<string,mixed> $context Redacted, non-secret context.
	 */
	public static function log( string $event, array $context = array() ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$log   = get_option( self::OPTION_LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'event'   => $event,
			'ip'      => self::client_ip(),
			'ua'      => self::short_ua(),
			'context' => $context,
		);
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries(): array {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION_LOG );
	}

	/**
	 * A non-reversible short fingerprint of a secret, safe to store, so two
	 * events referring to the same token/code can be correlated.
	 */
	public static function fingerprint( string $secret ): string {
		if ( '' === $secret ) {
			return '(empty)';
		}
		return substr( hash( 'sha256', $secret ), 0, 8 );
	}

	private static function client_ip(): string {
		// Prefer Cloudflare's real-visitor header when present.
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		return '';
	}

	private static function short_ua(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 60 );
	}
}
