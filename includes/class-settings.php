<?php
/**
 * Settings & signing-key management.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Central accessor for plugin options and derived URLs.
 */
final class Settings {

	const OPTION_KEY   = 'arzo_mcp_signing_key';
	const OPTION_ROUTE = 'arzo_mcp_server_route';

	/** Default MCP Adapter REST route (namespace/server). */
	const DEFAULT_ROUTE = 'mcp/mcp-adapter-default-server';

	const ACCESS_TTL  = 3600;      // 1 hour
	const REFRESH_TTL = 2592000;   // 30 days
	const CODE_TTL    = 600;       // 10 minutes
	const SCOPE       = 'mcp';

	/**
	 * The HMAC signing key for access tokens, generated on first use.
	 */
	public static function signing_key(): string {
		$key = get_option( self::OPTION_KEY );
		if ( ! is_string( $key ) || '' === $key ) {
			$key = self::regenerate_key();
		}
		return $key;
	}

	/**
	 * Generate (and persist) a fresh signing key. Invalidates existing tokens.
	 */
	public static function regenerate_key(): string {
		$key = self::base64url( random_bytes( 48 ) );
		update_option( self::OPTION_KEY, $key, false );
		return $key;
	}

	/**
	 * The configured MCP Adapter server route (e.g. "mcp/mcp-adapter-default-server").
	 */
	public static function server_route(): string {
		$route = get_option( self::OPTION_ROUTE, self::DEFAULT_ROUTE );
		return is_string( $route ) && '' !== $route ? $route : self::DEFAULT_ROUTE;
	}

	/**
	 * Absolute URL of the MCP resource — the value pasted into Claude and the
	 * audience bound into access tokens.
	 *
	 * Built from home_url() + rest_get_url_prefix() instead of rest_url() on
	 * purpose: this runs inside `determine_current_user`, which other plugins can
	 * trigger during `plugins_loaded`, before the global $wp_rewrite object that
	 * rest_url() depends on exists (calling rest_url() there fatals). It also
	 * keeps the token audience stable across permalink-structure changes.
	 */
	public static function resource_url(): string {
		return home_url( '/' . rest_get_url_prefix() . '/' . self::server_route() );
	}

	/**
	 * Path portion of the MCP resource URL (e.g. "/wp-json/mcp/...").
	 */
	public static function resource_path(): string {
		$path = wp_parse_url( self::resource_url(), PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Path prefix of the WordPress install (e.g. "/blog" for subdirectory
	 * installs), without a trailing slash. Empty string for root installs.
	 */
	public static function home_path(): string {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/**
	 * The OAuth issuer — the site's home URL (no trailing slash).
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/** URL-safe base64 (RFC 4648 §5, no padding). */
	public static function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** Decode URL-safe base64. */
	public static function base64url_decode( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
