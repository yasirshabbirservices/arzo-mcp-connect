<?php
/**
 * Admin settings page.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * A simple Settings → Arzo MCP Connect page: shows the connector URL to paste
 * into Claude, whether the MCP Adapter dependency is active, and controls to set
 * the server route and rotate the signing key.
 */
final class Admin {

	const OPTION_MANUAL_CLIENT = 'arzo_mcp_manual_client_id';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_arzo_mcp_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_arzo_mcp_create_client', array( $this, 'create_client' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Create a pre-registered ("manual") OAuth client for clients that cannot do
	 * Dynamic Client Registration. The generated client_id is shown on the
	 * settings page for pasting into Claude's "OAuth Client ID" field.
	 */
	public function create_client(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'arzo-mcp-connect' ) );
		}
		check_admin_referer( 'arzo_mcp_create_client' );

		$client_id = 'arzo_' . bin2hex( random_bytes( 16 ) );
		$store     = new OAuth_Store();
		$store->save_client(
			array(
				'client_id'                  => $client_id,
				'redirect_uris'              => array(
					'https://claude.ai/api/mcp/auth_callback',
					'https://claude.com/api/mcp/auth_callback',
				),
				'client_name'                => 'Claude (manual)',
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'scope'                      => Settings::SCOPE,
				'created_at'                 => time(),
			)
		);
		update_option( self::OPTION_MANUAL_CLIENT, $client_id, false );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=arzo-mcp-connect' ) ) );
		exit;
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Arzo MCP Connect', 'arzo-mcp-connect' ),
			__( 'Arzo MCP Connect', 'arzo-mcp-connect' ),
			'manage_options',
			'arzo-mcp-connect',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Admin warnings: pretty permalinks are required, and the MCP Adapter must be
	 * active. Shown on the plugins screen and our own settings screen.
	 */
	public function dependency_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'settings_page_arzo-mcp-connect' ), true ) ) {
			return;
		}

		// Pretty permalinks are required for /wp-json/ and /.well-known/ to route.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: URL to the Permalinks settings screen. */
				wp_kses_post( __( 'Arzo MCP Connect requires “pretty” permalinks. Go to <a href="%s">Settings → Permalinks</a>, choose <strong>Post name</strong>, and Save Changes — otherwise the MCP endpoint and OAuth discovery URLs return 404.', 'arzo-mcp-connect' ) ),
				esc_url( admin_url( 'options-permalink.php' ) )
			);
			echo '</p></div>';
		}

		if ( ! self::adapter_active() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Arzo MCP Connect: the WordPress “MCP Adapter” plugin is not active. Install and activate it so your abilities are exposed as MCP tools.', 'arzo-mcp-connect' );
			echo '</p></div>';
		}
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'arzo-mcp-connect' ) );
		}
		check_admin_referer( 'arzo_mcp_settings' );

		$route = isset( $_POST['arzo_mcp_route'] ) ? sanitize_text_field( wp_unslash( $_POST['arzo_mcp_route'] ) ) : '';
		if ( '' !== $route ) {
			update_option( Settings::OPTION_ROUTE, $route, false );
		}
		if ( isset( $_POST['arzo_mcp_regenerate'] ) ) {
			Settings::regenerate_key();
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=arzo-mcp-connect' ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$connector = Settings::resource_url();
		$route     = Settings::server_route();
		$active    = self::adapter_active();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Arzo MCP Connect', 'arzo-mcp-connect' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'arzo-mcp-connect' ); ?></p></div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Connector URL', 'arzo-mcp-connect' ); ?></h2>
			<p><?php echo esc_html__( 'In Claude, open Settings → Connectors → Add custom connector and paste this URL:', 'arzo-mcp-connect' ); ?></p>
			<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $connector ); ?>" onclick="this.select()" /></p>

			<h2><?php echo esc_html__( 'If registration fails: manual Client ID', 'arzo-mcp-connect' ); ?></h2>
			<p><?php echo esc_html__( 'If Claude shows “Couldn’t register with the sign-in service”, generate a Client ID here and paste it into Claude’s connector settings (the “OAuth Client ID” field), then connect again.', 'arzo-mcp-connect' ); ?></p>
			<?php $manual = get_option( self::OPTION_MANUAL_CLIENT ); ?>
			<?php if ( is_string( $manual ) && '' !== $manual ) : ?>
				<p><strong><?php echo esc_html__( 'OAuth Client ID:', 'arzo-mcp-connect' ); ?></strong></p>
				<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $manual ); ?>" onclick="this.select()" /></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
				<input type="hidden" name="action" value="arzo_mcp_create_client" />
				<?php wp_nonce_field( 'arzo_mcp_create_client' ); ?>
				<?php submit_button( is_string( $manual ) && '' !== $manual ? __( 'Regenerate Client ID', 'arzo-mcp-connect' ) : __( 'Generate Client ID', 'arzo-mcp-connect' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php echo esc_html__( 'Diagnostics', 'arzo-mcp-connect' ); ?></h2>
			<p><?php echo esc_html__( 'These discovery URLs must each return JSON (not your homepage or a 404):', 'arzo-mcp-connect' ); ?></p>
			<ul style="list-style:disc;margin-left:2em;">
				<li><a href="<?php echo esc_url( home_url( '/.well-known/oauth-authorization-server' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/.well-known/oauth-protected-resource' . Settings::resource_path() ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' . Settings::resource_path() ) ); ?></a></li>
			</ul>

			<h2><?php echo esc_html__( 'Status', 'arzo-mcp-connect' ); ?></h2>
			<p>
				<?php echo esc_html__( 'MCP Adapter plugin:', 'arzo-mcp-connect' ); ?>
				<strong style="color:<?php echo $active ? '#008a20' : '#b32d2e'; ?>">
					<?php echo $active ? esc_html__( 'active', 'arzo-mcp-connect' ) : esc_html__( 'not detected', 'arzo-mcp-connect' ); ?>
				</strong>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="arzo_mcp_save_settings" />
				<?php wp_nonce_field( 'arzo_mcp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="arzo_mcp_route"><?php echo esc_html__( 'MCP server route', 'arzo-mcp-connect' ); ?></label></th>
						<td>
							<input name="arzo_mcp_route" id="arzo_mcp_route" type="text" class="regular-text code" value="<?php echo esc_attr( $route ); ?>" />
							<p class="description"><?php echo esc_html__( 'REST route of the MCP Adapter server. Default: mcp/mcp-adapter-default-server', 'arzo-mcp-connect' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Signing key', 'arzo-mcp-connect' ); ?></th>
						<td>
							<label><input type="checkbox" name="arzo_mcp_regenerate" value="1" /> <?php echo esc_html__( 'Regenerate (invalidates all existing tokens; connected clients must re-authenticate)', 'arzo-mcp-connect' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'arzo-mcp-connect' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Detect the WordPress MCP Adapter (it defines WP_MCP_VERSION).
	 */
	public static function adapter_active(): bool {
		return defined( 'WP_MCP_VERSION' );
	}
}
