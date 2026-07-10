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

// Best-effort cleanup of transient auth codes / refresh tokens.
global $wpdb;
if ( isset( $wpdb ) ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_arzo\\_mcp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_arzo\\_mcp\\_%'"
	);
}
