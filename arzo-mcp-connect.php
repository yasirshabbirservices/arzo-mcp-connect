<?php
/**
 * Plugin Name:       Arzo MCP Connect
 * Plugin URI:        https://github.com/yasirshabbirservices/arzo-remote-mcp
 * Description:       Connect Claude.ai (and other Remote MCP clients) directly to this WordPress site. Adds an OAuth 2.1 authorization server (Dynamic Client Registration + PKCE) on top of the WordPress MCP Adapter, so you can add your site as a Claude Custom Connector with no external gateway or local software.
 * Version:           1.0.6
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Yasir Shabbir
 * Author URI:        https://yasirshabbir.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       arzo-mcp-connect
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

define( 'ARZO_MCP_VERSION', '1.0.6' );
define( 'ARZO_MCP_FILE', __FILE__ );
define( 'ARZO_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARZO_MCP_URL', plugin_dir_url( __FILE__ ) );

// Simple explicit require list — no Composer needed, keeping the plugin
// self-contained and dependency-free.
require_once ARZO_MCP_DIR . 'includes/class-settings.php';
require_once ARZO_MCP_DIR . 'includes/class-debug.php';
require_once ARZO_MCP_DIR . 'includes/class-jwt.php';
require_once ARZO_MCP_DIR . 'includes/class-pkce.php';
require_once ARZO_MCP_DIR . 'includes/class-oauth-store.php';
require_once ARZO_MCP_DIR . 'includes/class-tokens.php';
require_once ARZO_MCP_DIR . 'includes/class-metadata.php';
require_once ARZO_MCP_DIR . 'includes/class-consent.php';
require_once ARZO_MCP_DIR . 'includes/class-oauth-rest.php';
require_once ARZO_MCP_DIR . 'includes/class-authorize.php';
require_once ARZO_MCP_DIR . 'includes/class-bearer-auth.php';
require_once ARZO_MCP_DIR . 'includes/class-htaccess.php';
require_once ARZO_MCP_DIR . 'includes/class-admin.php';
require_once ARZO_MCP_DIR . 'includes/class-plugin.php';

/**
 * Activation: generate a signing key and store default settings.
 */
function activate(): void {
	Settings::signing_key(); // generates + persists if missing
	if ( false === get_option( Settings::OPTION_ROUTE, false ) ) {
		update_option( Settings::OPTION_ROUTE, Settings::DEFAULT_ROUTE, false );
	}
	Htaccess::ensure_auth_rules();
	update_option( 'arzo_mcp_version', ARZO_MCP_VERSION, false );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Upgrade routine: plugin updates replace files without re-firing activation,
 * so refresh the .htaccess rules once whenever the version changes.
 */
function maybe_upgrade(): void {
	if ( get_option( 'arzo_mcp_version' ) !== ARZO_MCP_VERSION ) {
		Htaccess::ensure_auth_rules();
		update_option( 'arzo_mcp_version', ARZO_MCP_VERSION, false );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\maybe_upgrade' );

/**
 * Boot the plugin once all plugins are loaded (so we can detect the MCP Adapter).
 */
function boot(): void {
	Plugin::instance()->register();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
