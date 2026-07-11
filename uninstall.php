<?php
/**
 * Uninstall cleanup.
 *
 * @package Arzo\MCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'arzo_mcp_signing_key' );
delete_option( 'arzo_mcp_clients' );
delete_option( 'arzo_mcp_server_route' );
delete_option( 'arzo_mcp_manual_client_id' );
delete_option( 'arzo_mcp_version' );
delete_option( 'arzo_mcp_debug' );
delete_option( 'arzo_mcp_debug_log' );

// Remove the Authorization pass-through block the plugin added to .htaccess.
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
if ( function_exists( 'insert_with_markers' ) && function_exists( 'get_home_path' ) ) {
	$arzo_mcp_htaccess = get_home_path() . '.htaccess';
	if ( file_exists( $arzo_mcp_htaccess ) && wp_is_writable( $arzo_mcp_htaccess ) ) {
		insert_with_markers( $arzo_mcp_htaccess, 'Arzo MCP Connect', array() );
	}
}

// Best-effort cleanup of transient auth codes / refresh tokens.
global $wpdb;
if ( isset( $wpdb ) ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_arzo\\_mcp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_arzo\\_mcp\\_%'"
	);
}
