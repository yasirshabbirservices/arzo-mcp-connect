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
		add_action( 'admin_post_arzo_mcp_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Clear the diagnostic log.
	 */
	public function clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'arzo-mcp-connect' ) );
		}
		check_admin_referer( 'arzo_mcp_clear_log' );
		Debug::clear();
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=arzo-mcp-connect' ) ) );
		exit;
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
		update_option( Debug::OPTION_ENABLED, isset( $_POST['arzo_mcp_debug'] ) ? '1' : '', false );

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
				<?php $prm_url = ( new Metadata() )->protected_resource_metadata_url(); ?>
				<li><a href="<?php echo esc_url( $prm_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $prm_url ); ?></a></li>
			</ul>

			<h2><?php echo esc_html__( 'Status', 'arzo-mcp-connect' ); ?></h2>
			<p>
				<?php echo esc_html__( 'MCP Adapter plugin:', 'arzo-mcp-connect' ); ?>
				<strong style="color:<?php echo $active ? '#008a20' : '#b32d2e'; ?>">
					<?php echo $active ? esc_html__( 'active', 'arzo-mcp-connect' ) : esc_html__( 'not detected', 'arzo-mcp-connect' ); ?>
				</strong>
			</p>
			<p>
				<?php echo esc_html__( 'Authorization header:', 'arzo-mcp-connect' ); ?>
				<strong id="arzo-mcp-auth-check"><?php echo esc_html__( 'checking…', 'arzo-mcp-connect' ); ?></strong>
			</p>
			<p class="description" id="arzo-mcp-auth-fix" style="display:none;">
				<?php
				echo wp_kses_post(
					__( 'Your server strips the <code>Authorization</code> header, so Claude cannot authenticate even after a successful connection. The plugin tried to fix this via <code>.htaccess</code> automatically; if this stays red, add this line to the top of your site&#8217;s root <code>.htaccess</code>: <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code> (on Nginx, ensure <code>fastcgi_pass_header Authorization;</code>).', 'arzo-mcp-connect' )
				);
				?>
			</p>
			<script>
			( function () {
				var el = document.getElementById( 'arzo-mcp-auth-check' );
				var fix = document.getElementById( 'arzo-mcp-auth-fix' );
				fetch( <?php echo wp_json_encode( rest_url( 'arzo-mcp/v1/diagnostics' ) ); ?>, {
					headers: { Authorization: 'Bearer arzo-mcp-diagnostic-probe' },
					credentials: 'omit'
				} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
					if ( d && d.authorization_header_received ) {
						el.textContent = <?php echo wp_json_encode( __( 'reaches WordPress ✓', 'arzo-mcp-connect' ) ); ?> + ' (' + d.authorization_header_source + ')';
						el.style.color = '#008a20';
					} else {
						el.textContent = <?php echo wp_json_encode( __( 'stripped by the server ✗', 'arzo-mcp-connect' ) ); ?>;
						el.style.color = '#b32d2e';
						fix.style.display = 'block';
					}
				} ).catch( function () {
					el.textContent = <?php echo wp_json_encode( __( 'check failed (REST unreachable)', 'arzo-mcp-connect' ) ); ?>;
					el.style.color = '#b32d2e';
				} );
			} )();
			</script>

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
					<tr>
						<th scope="row"><?php echo esc_html__( 'Diagnostic log', 'arzo-mcp-connect' ); ?></th>
						<td>
							<label><input type="checkbox" name="arzo_mcp_debug" value="1" <?php checked( Debug::enabled() ); ?> /> <?php echo esc_html__( 'Record OAuth / bearer flow events (no tokens or secrets are stored). Turn on, retry the Claude connection, then read the log below.', 'arzo-mcp-connect' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'arzo-mcp-connect' ) ); ?>
			</form>

			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	/**
	 * Render the captured diagnostic events (newest first).
	 */
	private function render_log(): void {
		$entries = Debug::entries();
		?>
		<h2><?php echo esc_html__( 'Diagnostic log', 'arzo-mcp-connect' ); ?></h2>
		<?php if ( ! Debug::enabled() ) : ?>
			<p class="description"><?php echo esc_html__( 'Logging is off. Enable it above, Save, retry the Claude connection, then refresh this page.', 'arzo-mcp-connect' ); ?></p>
		<?php endif; ?>
		<?php if ( empty( $entries ) ) : ?>
			<p><em><?php echo esc_html__( 'No events recorded yet.', 'arzo-mcp-connect' ); ?></em></p>
		<?php else : ?>
			<?php $this->maybe_waf_warning( $entries ); ?>
			<?php
			// Plain-text export for the Copy button.
			$plain = '';
			foreach ( $entries as $entry ) {
				$plain .= (string) ( $entry['time'] ?? '' ) . '  ' . (string) ( $entry['event'] ?? '' ) . '  '
					. wp_json_encode( $entry['context'] ?? array() ) . '  '
					. trim( (string) ( $entry['ip'] ?? '' ) . ' · ' . (string) ( $entry['ua'] ?? '' ), ' ·' ) . "\n";
			}
			?>
			<p style="margin:.5em 0;">
				<button type="button" class="button" id="arzo-mcp-copy-log"><?php echo esc_html__( 'Copy log', 'arzo-mcp-connect' ); ?></button>
				<a href="#" class="button" id="arzo-mcp-clear-log-link"
					onclick="event.preventDefault();document.getElementById('arzo-mcp-clear-log-form').submit();"><?php echo esc_html__( 'Clear log', 'arzo-mcp-connect' ); ?></a>
			</p>
			<form method="post" id="arzo-mcp-clear-log-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
				<input type="hidden" name="action" value="arzo_mcp_clear_log" />
				<?php wp_nonce_field( 'arzo_mcp_clear_log' ); ?>
			</form>
			<textarea id="arzo-mcp-log-text" readonly style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true"><?php echo esc_textarea( $plain ); ?></textarea>
			<script>
			( function () {
				var btn = document.getElementById( 'arzo-mcp-copy-log' );
				btn && btn.addEventListener( 'click', function () {
					var ta = document.getElementById( 'arzo-mcp-log-text' );
					var done = function () {
						var t = btn.textContent;
						btn.textContent = <?php echo wp_json_encode( __( 'Copied ✓', 'arzo-mcp-connect' ) ); ?>;
						setTimeout( function () { btn.textContent = t; }, 1500 );
					};
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( ta.value ).then( done, function () { ta.style.position = 'static'; ta.style.left = 'auto'; ta.select(); document.execCommand( 'copy' ); done(); } );
					} else {
						ta.style.position = 'static'; ta.style.left = 'auto'; ta.select(); document.execCommand( 'copy' ); done();
					}
				} );
			} )();
			</script>
			<table class="widefat striped" style="max-width:960px;">
				<thead><tr>
					<th><?php echo esc_html__( 'Time', 'arzo-mcp-connect' ); ?></th>
					<th><?php echo esc_html__( 'Event', 'arzo-mcp-connect' ); ?></th>
					<th><?php echo esc_html__( 'Details', 'arzo-mcp-connect' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td style="white-space:nowrap;"><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $entry['event'] ?? '' ) ); ?></code></td>
						<td><code style="word-break:break-all;"><?php echo esc_html( wp_json_encode( $entry['context'] ?? array() ) ); ?></code><br /><small style="color:#787c82;"><?php echo esc_html( trim( (string) ( $entry['ip'] ?? '' ) . ' · ' . (string) ( $entry['ua'] ?? '' ), ' ·' ) ); ?></small></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * If the log shows a token was issued but no authenticated MCP request ever
	 * reached WordPress afterward, a firewall/WAF (Cloudflare, ModSecurity,
	 * LiteSpeed) is almost certainly blocking Claude's bearer-token requests
	 * upstream. Surface the fix.
	 *
	 * @param array<int,array<string,mixed>> $entries Newest-first log entries.
	 */
	private function maybe_waf_warning( array $entries ): void {
		$issued   = false;
		$verified = false;
		// $entries is newest-first; walk oldest→newest to see what followed issuance.
		foreach ( array_reverse( $entries ) as $entry ) {
			$event = (string) ( $entry['event'] ?? '' );
			if ( 'token_issued' === $event ) {
				$issued   = true;
				$verified = false; // reset: look for a verify after THIS issuance.
			}
			if ( $issued && in_array( $event, array( 'verify_ok', 'verify_fail', 'mcp_authorized', 'challenge_issued', 'mcp_short_circuited' ), true ) ) {
				$verified = true;
			}
		}
		if ( ! $issued || $verified ) {
			return;
		}
		?>
		<div class="notice notice-error" style="max-width:960px;">
			<p><strong><?php echo esc_html__( 'A firewall is blocking Claude after login.', 'arzo-mcp-connect' ); ?></strong></p>
			<p><?php echo esc_html__( 'The log shows an access token was issued successfully, but Claude’s follow-up request to the MCP endpoint never reached WordPress. That means a WAF / firewall in front of your site (Cloudflare, or ModSecurity/LiteSpeed on your host) is blocking requests that carry the bearer token — often with a 403 “Your request was blocked.” page. This is a hosting/CDN setting, not a plugin problem, and must be fixed there:', 'arzo-mcp-connect' ); ?></p>
			<ul style="list-style:disc;margin-left:2em;">
				<li><?php echo wp_kses_post( __( '<strong>Cloudflare:</strong> Security → WAF → Custom rules → create a rule that <em>Skips</em> Managed Rules, Bot Fight Mode, and Rate Limiting when <code>URI Path</code> starts with <code>/wp-json/mcp/</code>, <code>/wp-json/arzo-mcp/</code>, or <code>/.well-known/</code>.', 'arzo-mcp-connect' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>ModSecurity / LiteSpeed (host):</strong> ask your host to disable ModSecurity for the <code>/wp-json/mcp/</code> path, or whitelist requests to it. The OWASP rule set frequently flags JWT bearer tokens as false positives.', 'arzo-mcp-connect' ) ); ?></li>
			</ul>
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
