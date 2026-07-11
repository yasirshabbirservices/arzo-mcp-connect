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
 * Settings → Arzo MCP Connect: the connector URL to paste into Claude, a
 * manual Client ID fallback, live status (MCP Adapter + Authorization header),
 * copy-paste setup instructions for common CDNs/WAFs, and an opt-in diagnostic
 * log. Styled in the yasirshabbir.com emerald design language.
 */
final class Admin {

	const OPTION_MANUAL_CLIENT = 'arzo_mcp_manual_client_id';
	const HOOK                 = 'settings_page_arzo-mcp-connect';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_arzo_mcp_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_arzo_mcp_create_client', array( $this, 'create_client' ) );
		add_action( 'admin_post_arzo_mcp_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Enqueue the page's stylesheet, script, and fonts — only on our screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( self::HOOK !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'arzo-mcp-fonts',
			'https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Space+Grotesk:wght@400;500;600&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts is versionless.
		);
		wp_enqueue_style( 'arzo-mcp-admin', ARZO_MCP_URL . 'assets/css/admin.css', array(), ARZO_MCP_VERSION );
		wp_enqueue_script( 'arzo-mcp-admin', ARZO_MCP_URL . 'assets/js/admin.js', array(), ARZO_MCP_VERSION, true );
		wp_localize_script(
			'arzo-mcp-admin',
			'arzoMcp',
			array(
				'diagnosticsUrl' => rest_url( 'arzo-mcp/v1/diagnostics' ),
				'i18n'           => array(
					'copied'          => __( 'Copied ✓', 'arzo-mcp-connect' ),
					'authOk'          => __( 'reaches WordPress ✓', 'arzo-mcp-connect' ),
					'authStripped'    => __( 'stripped by the server ✗', 'arzo-mcp-connect' ),
					'authUnreachable' => __( 'check failed (REST unreachable)', 'arzo-mcp-connect' ),
				),
			)
		);
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
		$manual    = get_option( self::OPTION_MANUAL_CLIENT );
		$manual    = is_string( $manual ) ? $manual : '';
		$entries   = Debug::entries();
		$as_url    = home_url( '/.well-known/oauth-authorization-server' );
		$prm_url   = ( new Metadata() )->protected_resource_metadata_url();
		?>
		<div class="wrap arzo-page">
			<header class="arzo-hero">
				<div class="arzo-hero__brand">
					<span class="arzo-hero__eyebrow"><?php echo esc_html__( 'WordPress × Claude', 'arzo-mcp-connect' ); ?></span>
					<h1 class="arzo-hero__title"><?php echo esc_html__( 'Arzo MCP', 'arzo-mcp-connect' ); ?> <span class="arzo-grad"><?php echo esc_html__( 'Connect', 'arzo-mcp-connect' ); ?></span></h1>
				</div>
				<span class="arzo-hero__version">v<?php echo esc_html( ARZO_MCP_VERSION ); ?></span>
			</header>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="arzo-notice arzo-notice--ok" role="status">
					<div class="arzo-notice__body"><strong><?php echo esc_html__( 'Saved.', 'arzo-mcp-connect' ); ?></strong></div>
				</div>
			<?php endif; ?>

			<?php $this->maybe_waf_warning( $entries ); ?>

			<div class="arzo-u-grid">
				<section class="arzo-card">
					<div class="arzo-card__head">
						<h2 class="arzo-card__title"><?php echo esc_html__( 'Connector URL', 'arzo-mcp-connect' ); ?></h2>
					</div>
					<p class="arzo-card__desc"><?php echo esc_html__( 'In Claude: Settings → Connectors → Add custom connector, and paste this URL.', 'arzo-mcp-connect' ); ?></p>
					<div class="arzo-field">
						<input id="arzo-connector-url" class="arzo-field__input" type="text" readonly value="<?php echo esc_attr( $connector ); ?>" onclick="this.select()" />
						<button type="button" class="arzo-btn arzo-btn--primary" data-arzo-copy="#arzo-connector-url"><?php echo esc_html__( 'Copy', 'arzo-mcp-connect' ); ?></button>
					</div>
				</section>

				<section class="arzo-card">
					<div class="arzo-card__head">
						<h2 class="arzo-card__title"><?php echo esc_html__( 'Status', 'arzo-mcp-connect' ); ?></h2>
					</div>
					<div class="arzo-status">
						<div class="arzo-status__item">
							<span class="arzo-status__label"><?php echo esc_html__( 'MCP Adapter plugin', 'arzo-mcp-connect' ); ?></span>
							<?php if ( $active ) : ?>
								<span class="arzo-badge arzo-badge--ok"><?php echo esc_html__( 'active', 'arzo-mcp-connect' ); ?></span>
							<?php else : ?>
								<span class="arzo-badge arzo-badge--bad"><?php echo esc_html__( 'not detected', 'arzo-mcp-connect' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="arzo-status__item">
							<span class="arzo-status__label"><?php echo esc_html__( 'Authorization header', 'arzo-mcp-connect' ); ?></span>
							<span id="arzo-auth-check" class="arzo-badge arzo-badge--idle"><?php echo esc_html__( 'checking…', 'arzo-mcp-connect' ); ?></span>
						</div>
					</div>
					<p id="arzo-auth-fix" class="arzo-hint" hidden>
						<?php
						echo wp_kses(
							__( 'Your server strips the <code>Authorization</code> header, so Claude cannot authenticate. Add to the top of your root <code>.htaccess</code>: <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code> — or on Nginx add <code>fastcgi_pass_header Authorization;</code>.', 'arzo-mcp-connect' ),
							array( 'code' => array() )
						);
						?>
					</p>
				</section>
			</div>

			<section class="arzo-card">
				<div class="arzo-card__head">
					<h2 class="arzo-card__title"><?php echo esc_html__( 'Manual Client ID', 'arzo-mcp-connect' ); ?></h2>
				</div>
				<p class="arzo-card__desc"><?php echo esc_html__( 'Only needed if Claude shows “Couldn’t register with the sign-in service”. Generate an ID here and paste it into Claude’s “OAuth Client ID” field, then connect again.', 'arzo-mcp-connect' ); ?></p>
				<?php if ( '' !== $manual ) : ?>
					<div class="arzo-field">
						<input id="arzo-client-id" class="arzo-field__input" type="text" readonly value="<?php echo esc_attr( $manual ); ?>" onclick="this.select()" />
						<button type="button" class="arzo-btn arzo-btn--ghost" data-arzo-copy="#arzo-client-id"><?php echo esc_html__( 'Copy', 'arzo-mcp-connect' ); ?></button>
					</div>
				<?php endif; ?>
				<form class="arzo-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="arzo_mcp_create_client" />
					<?php wp_nonce_field( 'arzo_mcp_create_client' ); ?>
					<button type="submit" class="arzo-btn arzo-btn--ghost">
						<?php echo '' !== $manual ? esc_html__( 'Regenerate Client ID', 'arzo-mcp-connect' ) : esc_html__( 'Generate Client ID', 'arzo-mcp-connect' ); ?>
					</button>
				</form>
			</section>

			<section class="arzo-card">
				<div class="arzo-card__head">
					<h2 class="arzo-card__title"><?php echo esc_html__( 'Discovery check', 'arzo-mcp-connect' ); ?></h2>
				</div>
				<p class="arzo-card__desc"><?php echo esc_html__( 'Each link must return JSON — not your homepage or a 404. If either fails, enable “pretty” permalinks (Settings → Permalinks → Post name).', 'arzo-mcp-connect' ); ?></p>
				<div class="arzo-u-stack" style="--arzo-stack-gap:var(--arzo-space-2xs);">
					<a class="arzo-u-mono" href="<?php echo esc_url( $as_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $as_url ); ?></a>
					<a class="arzo-u-mono" href="<?php echo esc_url( $prm_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $prm_url ); ?></a>
				</div>
			</section>

			<?php $this->render_setup(); ?>

			<section class="arzo-card">
				<div class="arzo-card__head">
					<h2 class="arzo-card__title"><?php echo esc_html__( 'Advanced', 'arzo-mcp-connect' ); ?></h2>
				</div>
				<form class="arzo-u-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="--arzo-stack-gap:var(--arzo-space-md);">
					<input type="hidden" name="action" value="arzo_mcp_save_settings" />
					<?php wp_nonce_field( 'arzo_mcp_settings' ); ?>

					<div class="arzo-control">
						<label class="arzo-label" for="arzo_mcp_route"><?php echo esc_html__( 'MCP server route', 'arzo-mcp-connect' ); ?></label>
						<input class="arzo-input" name="arzo_mcp_route" id="arzo_mcp_route" type="text" value="<?php echo esc_attr( $route ); ?>" />
						<span class="arzo-hint"><?php echo esc_html__( 'REST route of the MCP Adapter server. Default: mcp/mcp-adapter-default-server', 'arzo-mcp-connect' ); ?></span>
					</div>

					<label class="arzo-check">
						<input class="arzo-check__box" type="checkbox" name="arzo_mcp_regenerate" value="1" />
						<span class="arzo-check__text"><strong><?php echo esc_html__( 'Regenerate signing key', 'arzo-mcp-connect' ); ?></strong><br /><?php echo esc_html__( 'Invalidates all existing tokens; connected clients must re-authenticate.', 'arzo-mcp-connect' ); ?></span>
					</label>

					<label class="arzo-check">
						<input class="arzo-check__box" type="checkbox" name="arzo_mcp_debug" value="1" <?php checked( Debug::enabled() ); ?> />
						<span class="arzo-check__text"><strong><?php echo esc_html__( 'Diagnostic log', 'arzo-mcp-connect' ); ?></strong><br /><?php echo esc_html__( 'Record OAuth / bearer flow events (no tokens or secrets are stored). Turn on, retry the Claude connection, then read the log below.', 'arzo-mcp-connect' ); ?></span>
					</label>

					<div class="arzo-actions">
						<button type="submit" class="arzo-btn arzo-btn--primary"><?php echo esc_html__( 'Save changes', 'arzo-mcp-connect' ); ?></button>
					</div>
				</form>
			</section>

			<?php $this->render_log( $entries ); ?>
		</div>
		<?php
	}

	/**
	 * Copy-paste setup instructions for the CDN/WAF/cache layers that most often
	 * break the flow, as accessible tabs.
	 */
	private function render_setup(): void {
		$paths = '/wp-json/mcp/  ·  /wp-json/arzo-mcp/  ·  /.well-known/';
		$tabs  = array(
			'cloudflare' => __( 'Cloudflare', 'arzo-mcp-connect' ),
			'litespeed'  => __( 'LiteSpeed', 'arzo-mcp-connect' ),
			'caches'     => __( 'Other caches', 'arzo-mcp-connect' ),
			'servers'    => __( 'Nginx / Apache', 'arzo-mcp-connect' ),
			'modsec'     => __( 'ModSecurity', 'arzo-mcp-connect' ),
		);
		?>
		<section class="arzo-card">
			<div class="arzo-card__head">
				<h2 class="arzo-card__title"><?php echo esc_html__( 'Setup &amp; troubleshooting', 'arzo-mcp-connect' ); ?></h2>
			</div>
			<p class="arzo-card__desc">
				<?php echo esc_html__( 'If login succeeds but Claude still says “Authorization failed”, a CDN, cache, or firewall is interfering with these paths. Exclude them from caching and firewall rules:', 'arzo-mcp-connect' ); ?>
				<br /><span class="arzo-code"><?php echo esc_html( $paths ); ?></span>
			</p>

			<div class="arzo-tabs__list" role="tablist" data-arzo-tabs aria-label="<?php echo esc_attr__( 'Setup guides', 'arzo-mcp-connect' ); ?>">
				<?php
				$first = true;
				foreach ( $tabs as $key => $label ) :
					?>
					<button type="button" class="arzo-tab" role="tab"
						id="arzo-tab-<?php echo esc_attr( $key ); ?>"
						aria-controls="arzo-panel-<?php echo esc_attr( $key ); ?>"
						aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
						tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></button>
					<?php
					$first = false;
				endforeach;
				?>
			</div>

			<div class="arzo-panel" role="tabpanel" id="arzo-panel-cloudflare" aria-labelledby="arzo-tab-cloudflare">
				<ol class="arzo-steps">
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'Cloudflare dashboard → your site → <strong>Security → WAF → Custom rules → Create rule</strong>.', 'arzo-mcp-connect' ), array( 'strong' => array() ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'Field <span class="arzo-code">URI Path</span>, operator <span class="arzo-code">starts with</span>, value <span class="arzo-code">/wp-json/mcp/</span>. Click <strong>Or</strong> and repeat for <span class="arzo-code">/wp-json/arzo-mcp/</span> and <span class="arzo-code">/.well-known/</span>.', 'arzo-mcp-connect' ), array( 'strong' => array(), 'span' => array( 'class' => array() ) ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'Action <strong>Skip</strong>, then tick <strong>Managed Rules</strong>, <strong>Super Bot Fight Mode</strong> (and Bot Fight Mode), and <strong>Rate Limiting</strong>. Deploy.', 'arzo-mcp-connect' ), array( 'strong' => array() ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'Also turn <strong>off</strong> “Browser Integrity Check” for these paths if enabled. Then re-add the connector in Claude.', 'arzo-mcp-connect' ), array( 'strong' => array() ) ); ?></span></li>
				</ol>
			</div>

			<div class="arzo-panel" role="tabpanel" id="arzo-panel-litespeed" aria-labelledby="arzo-tab-litespeed" hidden>
				<ol class="arzo-steps">
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'WP admin → <strong>LiteSpeed Cache → Cache → Excludes</strong>.', 'arzo-mcp-connect' ), array( 'strong' => array() ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo esc_html__( 'In “Do Not Cache URIs”, add one per line:', 'arzo-mcp-connect' ); ?><pre class="arzo-pre">/wp-json/mcp/
/wp-json/arzo-mcp/
/.well-known/</pre></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'Save, then <strong>LiteSpeed Cache → Toolbox → Purge All</strong>. The plugin already sends no-cache headers on the auth challenge, so this is belt-and-braces.', 'arzo-mcp-connect' ), array( 'strong' => array() ) ); ?></span></li>
				</ol>
			</div>

			<div class="arzo-panel" role="tabpanel" id="arzo-panel-caches" aria-labelledby="arzo-tab-caches" hidden>
				<ol class="arzo-steps">
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( '<strong>WP Rocket:</strong> Settings → Advanced Rules → “Never Cache URL(s)”, add <span class="arzo-code">/wp-json/mcp/(.*)</span> and <span class="arzo-code">/.well-known/(.*)</span>.', 'arzo-mcp-connect' ), array( 'strong' => array(), 'span' => array( 'class' => array() ) ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( '<strong>W3 Total Cache:</strong> Performance → Page Cache → “Never cache the following pages”, add the same paths.', 'arzo-mcp-connect' ), array( 'strong' => array() ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( '<strong>WP Super Cache / others:</strong> add <span class="arzo-code">/wp-json/</span> and <span class="arzo-code">/.well-known/</span> to the “Rejected URI” / do-not-cache list. REST is usually excluded already — verify.', 'arzo-mcp-connect' ), array( 'strong' => array(), 'span' => array( 'class' => array() ) ) ); ?></span></li>
				</ol>
			</div>

			<div class="arzo-panel" role="tabpanel" id="arzo-panel-servers" aria-labelledby="arzo-tab-servers" hidden>
				<ol class="arzo-steps">
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( '<strong>Apache / LiteSpeed:</strong> the plugin writes this to <span class="arzo-code">.htaccess</span> automatically. If your host blocks that, add it manually at the top:', 'arzo-mcp-connect' ), array( 'strong' => array(), 'span' => array( 'class' => array() ) ) ); ?><pre class="arzo-pre">SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</pre></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( '<strong>Nginx (php-fpm):</strong> ensure the Authorization header is forwarded in your <span class="arzo-code">location ~ \.php$</span> block:', 'arzo-mcp-connect' ), array( 'strong' => array(), 'span' => array( 'class' => array() ) ) ); ?><pre class="arzo-pre">fastcgi_pass_header Authorization;</pre></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo esc_html__( 'The “Authorization header” status above turns green once this works.', 'arzo-mcp-connect' ); ?></span></li>
				</ol>
			</div>

			<div class="arzo-panel" role="tabpanel" id="arzo-panel-modsec" aria-labelledby="arzo-tab-modsec" hidden>
				<ol class="arzo-steps">
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo esc_html__( 'The OWASP Core Rule Set sometimes flags a JWT bearer token as an attack, returning an intermittent 403 “Your request was blocked.” (works for some logins, fails for others).', 'arzo-mcp-connect' ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo wp_kses( __( 'In cPanel, toggle <strong>Security → ModSecurity</strong> off for the domain, or ask your host to whitelist requests to <span class="arzo-code">/wp-json/mcp/</span>.', 'arzo-mcp-connect' ), array( 'strong' => array(), 'span' => array( 'class' => array() ) ) ); ?></span></li>
					<li class="arzo-steps__item"><span class="arzo-steps__body"><?php echo esc_html__( 'Wordfence / Sucuri users: allowlist the same paths in the plugin’s firewall.', 'arzo-mcp-connect' ); ?></span></li>
				</ol>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the captured diagnostic events (newest first).
	 *
	 * @param array<int,array<string,mixed>> $entries Log entries (newest first).
	 */
	private function render_log( array $entries ): void {
		?>
		<section class="arzo-card">
			<div class="arzo-card__head">
				<h2 class="arzo-card__title"><?php echo esc_html__( 'Diagnostic log', 'arzo-mcp-connect' ); ?></h2>
				<?php if ( ! empty( $entries ) ) : ?>
					<div class="arzo-u-row">
						<button type="button" class="arzo-btn arzo-btn--ghost arzo-btn--sm" data-arzo-copy="#arzo-log-text"><?php echo esc_html__( 'Copy log', 'arzo-mcp-connect' ); ?></button>
						<button type="submit" form="arzo-clear-log" class="arzo-btn arzo-btn--danger arzo-btn--sm"><?php echo esc_html__( 'Clear', 'arzo-mcp-connect' ); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! Debug::enabled() ) : ?>
				<p class="arzo-hint"><?php echo esc_html__( 'Logging is off. Enable it under Advanced, Save, retry the Claude connection, then refresh this page.', 'arzo-mcp-connect' ); ?></p>
			<?php endif; ?>

			<?php if ( empty( $entries ) ) : ?>
				<p class="arzo-hint"><em><?php echo esc_html__( 'No events recorded yet.', 'arzo-mcp-connect' ); ?></em></p>
			<?php else : ?>
				<?php
				$plain = '';
				foreach ( $entries as $entry ) {
					$plain .= (string) ( $entry['time'] ?? '' ) . '  ' . (string) ( $entry['event'] ?? '' ) . '  '
						. wp_json_encode( $entry['context'] ?? array(), JSON_UNESCAPED_SLASHES ) . '  '
						. trim( (string) ( $entry['ip'] ?? '' ) . ' · ' . (string) ( $entry['ua'] ?? '' ), ' ·' ) . "\n";
				}
				?>
				<form id="arzo-clear-log" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arzo-u-hidden">
					<input type="hidden" name="action" value="arzo_mcp_clear_log" />
					<?php wp_nonce_field( 'arzo_mcp_clear_log' ); ?>
				</form>
				<textarea id="arzo-log-text" class="arzo-u-hidden" readonly aria-hidden="true"><?php echo esc_textarea( $plain ); ?></textarea>

				<div class="arzo-log__scroll">
					<table class="arzo-log__table">
						<thead><tr>
							<th><?php echo esc_html__( 'Time', 'arzo-mcp-connect' ); ?></th>
							<th><?php echo esc_html__( 'Event', 'arzo-mcp-connect' ); ?></th>
							<th><?php echo esc_html__( 'Details', 'arzo-mcp-connect' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php
							$event = (string) ( $entry['event'] ?? '' );
							$mod   = self::event_modifier( $event );
							$meta  = trim( (string) ( $entry['ip'] ?? '' ) . ' · ' . (string) ( $entry['ua'] ?? '' ), ' ·' );
							?>
							<tr>
								<td class="arzo-log__time"><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
								<td><span class="arzo-log__event<?php echo esc_attr( $mod ); ?>"><?php echo esc_html( $event ); ?></span></td>
								<td>
									<span class="arzo-log__ctx"><?php echo esc_html( wp_json_encode( $entry['context'] ?? array(), JSON_UNESCAPED_SLASHES ) ); ?></span>
									<?php if ( '' !== $meta ) : ?>
										<span class="arzo-log__meta"><?php echo esc_html( $meta ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Map an event slug to a BEM modifier for colour-coding the log.
	 */
	private static function event_modifier( string $event ): string {
		$ok  = array( 'verify_ok', 'mcp_authorized', 'token_issued', 'code_issued', 'register' );
		$bad = array( 'verify_fail', 'token_fail', 'challenge_issued', 'mcp_short_circuited', 'auth_path_mismatch' );
		if ( in_array( $event, $ok, true ) ) {
			return ' arzo-log__event--ok';
		}
		if ( in_array( $event, $bad, true ) ) {
			return ' arzo-log__event--bad';
		}
		return '';
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
		<div class="arzo-notice arzo-notice--error" role="alert">
			<div class="arzo-notice__body">
				<span class="arzo-notice__title"><?php echo esc_html__( 'A firewall is blocking Claude after login', 'arzo-mcp-connect' ); ?></span>
				<p><?php echo esc_html__( 'An access token was issued successfully, but Claude’s follow-up request never reached WordPress — a WAF/firewall (Cloudflare, or ModSecurity/LiteSpeed on your host) is blocking the bearer-token request, often with a 403 “Your request was blocked.” This is a hosting/CDN setting. See the Cloudflare and ModSecurity tabs above; the short version:', 'arzo-mcp-connect' ); ?></p>
				<ul>
					<li><?php echo wp_kses( __( '<strong>Cloudflare:</strong> WAF custom rule → Skip Managed Rules, Bot Fight Mode &amp; Rate Limiting for <code>/wp-json/mcp/</code>, <code>/wp-json/arzo-mcp/</code>, <code>/.well-known/</code>.', 'arzo-mcp-connect' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
					<li><?php echo wp_kses( __( '<strong>ModSecurity / host:</strong> disable ModSecurity for <code>/wp-json/mcp/</code> (OWASP rules false-positive on JWTs).', 'arzo-mcp-connect' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
				</ul>
			</div>
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
