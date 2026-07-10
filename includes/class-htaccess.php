<?php
/**
 * Best-effort .htaccess rules so the Authorization header reaches PHP.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Many Apache/LiteSpeed FastCGI hosts strip the Authorization header before it
 * reaches PHP, which silently breaks every bearer-authenticated MCP request
 * after an otherwise successful OAuth flow. These rules re-export the header
 * as an environment variable PHP can see. Managed idempotently via
 * insert_with_markers(); if .htaccess is not writable we do nothing and the
 * admin diagnostics point the user at the manual fix.
 */
final class Htaccess {

	const MARKER = 'Arzo MCP Connect';

	/**
	 * Insert (or refresh) the Authorization pass-through rules.
	 */
	public static function ensure_auth_rules(): void {
		$htaccess = self::htaccess_path();
		if ( '' === $htaccess ) {
			return;
		}
		insert_with_markers(
			$htaccess,
			self::MARKER,
			array(
				'<IfModule mod_setenvif.c>',
				'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
				'</IfModule>',
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteCond %{HTTP:Authorization} .',
				'RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
				'</IfModule>',
			)
		);
	}

	/**
	 * Remove our marker block (uninstall cleanup).
	 */
	public static function remove_auth_rules(): void {
		$htaccess = self::htaccess_path();
		if ( '' !== $htaccess ) {
			insert_with_markers( $htaccess, self::MARKER, array() );
		}
	}

	/**
	 * Path to a writable .htaccess on an Apache/LiteSpeed server, or ''.
	 */
	private static function htaccess_path(): string {
		global $is_apache, $is_litespeed;
		if ( empty( $is_apache ) && empty( $is_litespeed ) ) {
			return '';
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = get_home_path() . '.htaccess';
		$dir  = dirname( $path );
		if ( file_exists( $path ) ? ! wp_is_writable( $path ) : ! wp_is_writable( $dir ) ) {
			return '';
		}
		return $path;
	}
}
