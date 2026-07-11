<?php
/**
 * MCP Adapter dependency detection + one-click install/activate.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin is useless without the official WordPress "MCP Adapter"
 * (github.com/WordPress/mcp-adapter), which exposes abilities as MCP tools.
 * This helper detects whether the adapter is missing, installed-but-inactive,
 * or active, and can install it from the official GitHub release + activate it
 * in one click — the same thing `wp plugin install <release-zip> --activate`
 * does, gated behind the install_plugins capability and a nonce.
 */
final class Adapter {

	const STATE_ACTIVE    = 'active';
	const STATE_INACTIVE  = 'inactive';
	const STATE_MISSING   = 'missing';

	/** Official release asset — extracts to a clean `mcp-adapter/` folder. */
	const DOWNLOAD_URL = 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';
	const RELEASES_URL = 'https://github.com/WordPress/mcp-adapter/releases';

	/**
	 * Is the adapter active? (It defines WP_MCP_VERSION on load.)
	 */
	public static function is_active(): bool {
		return defined( 'WP_MCP_VERSION' );
	}

	/**
	 * One of the STATE_* constants.
	 */
	public static function state(): string {
		if ( self::is_active() ) {
			return self::STATE_ACTIVE;
		}
		return '' !== self::plugin_file() ? self::STATE_INACTIVE : self::STATE_MISSING;
	}

	/**
	 * The installed adapter's plugin file (e.g. "mcp-adapter/mcp-adapter.php"),
	 * or '' if it isn't installed. Matched by main-file name or text domain so
	 * an unusual folder name still resolves.
	 */
	public static function plugin_file(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 'mcp-adapter.php' === basename( $file ) ) {
				return $file;
			}
			if ( isset( $data['TextDomain'] ) && 'mcp-adapter' === $data['TextDomain'] ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Install (if needed) and activate the MCP Adapter.
	 *
	 * @return true|\WP_Error True on success, WP_Error describing the failure.
	 */
	public static function install_and_activate() {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to install plugins.', 'arzo-mcp-connect' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Already installed? Just activate it.
		$existing = self::plugin_file();
		if ( '' !== $existing ) {
			return self::activate( $existing );
		}

		// Not installed — download the official release and install it silently.
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( self::DOWNLOAD_URL );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			$messages = $skin->get_upgrade_messages();
			$detail   = ! empty( $messages ) ? ' ' . wp_strip_all_tags( implode( ' ', $messages ) ) : '';
			return new \WP_Error(
				'install_failed',
				/* translators: %s: extra failure detail from the installer. */
				sprintf( __( 'The MCP Adapter could not be installed automatically.%s', 'arzo-mcp-connect' ), $detail )
			);
		}

		$plugin_file = (string) $upgrader->plugin_info();
		if ( '' === $plugin_file ) {
			$plugin_file = self::plugin_file();
		}
		if ( '' === $plugin_file ) {
			return new \WP_Error( 'not_found', __( 'The MCP Adapter installed but its plugin file could not be located.', 'arzo-mcp-connect' ) );
		}

		return self::activate( $plugin_file );
	}

	/**
	 * Activate a plugin file, normalising the result to true|WP_Error.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins dir.
	 * @return true|\WP_Error
	 */
	private static function activate( string $plugin_file ) {
		$activated = activate_plugin( $plugin_file );
		return is_wp_error( $activated ) ? $activated : true;
	}
}
