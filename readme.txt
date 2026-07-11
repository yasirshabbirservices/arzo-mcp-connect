=== Arzo MCP Connect ===
Contributors: yasirshabbir
Tags: mcp, ai, claude, oauth, model-context-protocol
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude.ai directly to your WordPress site as a Remote MCP custom connector — no external server or local software required.

== Description ==

Arzo MCP Connect turns your WordPress site into a **Remote MCP server** that Claude.ai can add directly under Settings → Connectors → Add custom connector.

It adds the piece the official WordPress **MCP Adapter** is missing for hosted AI clients: a complete **OAuth 2.1 authorization server** (Dynamic Client Registration, PKCE, refresh tokens, and the RFC 8414 / RFC 9728 discovery documents). Claude requires OAuth and refuses pasted credentials, so this plugin bridges that gap — entirely inside WordPress, with no separate gateway to deploy.

**How it fits together**

* **MCP Adapter** (official plugin) exposes your WordPress abilities as MCP tools.
* **Arzo MCP Connect** (this plugin) adds the OAuth layer Claude needs and maps the token to a WordPress user.
* **Claude** connects to your site's MCP URL and authenticates with your normal WordPress login.

Because authorization uses your WordPress login, Claude acts with exactly your account's capabilities. Your credentials are never shared with Claude — it only receives a short-lived, audience-bound access token.

**Features**

* OAuth 2.1: Authorization Code + PKCE (S256), Dynamic Client Registration (RFC 7591), refresh-token rotation.
* Discovery: RFC 8414 authorization server metadata and RFC 9728 protected resource metadata.
* Emerald-themed consent screen; sign in with your existing WordPress account.
* Dependency-free (no Composer libraries bundled).
* Admin screen showing the exact connector URL to paste into Claude.

== Installation ==

1. Install and activate the official **MCP Adapter** plugin (and, on WordPress 6.8, the **Abilities API** plugin; on 6.9+ it is built into core).
2. Install and activate **Arzo MCP Connect**.
3. Go to **Settings → Arzo MCP Connect** and copy the Connector URL.
4. In Claude: **Settings → Connectors → Add custom connector**, paste the URL, and click **Connect**.
5. Sign in with your WordPress account and approve. Claude can now use your site's MCP tools.

Your site must be reachable over HTTPS from the public internet.

== Frequently Asked Questions ==

= Do I still need the MCP Adapter plugin? =
Yes. This plugin only adds OAuth. The MCP Adapter is what actually exposes your abilities as MCP tools.

= Does Claude get my WordPress password? =
No. You log in on your own site; Claude only receives an OAuth access token bound to this site.

= My host strips the Authorization header. =
Add this to your site's root .htaccess: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`

== Changelog ==

= 1.0.6 =
* Diagnostic log now also records what happens on the authenticated MCP request itself: whether it was short-circuited by another plugin (maintenance mode, security/WAF, cache), whether a 401 challenge was issued and if the bearer header was present, whether the token verified, and whether the request URL failed to match the configured resource path. This isolates failures that occur after a successful token exchange.

= 1.0.5 =
* Added an opt-in diagnostic log (Settings → Arzo MCP Connect) that records each step of the OAuth and bearer-auth flow — registration, code issuance, token exchange, and token verification — so a failing Claude connection can be pinpointed without server access. No tokens, codes, verifiers, or keys are ever stored; only outcomes, reasons, and short fingerprints.

= 1.0.4 =
* Fixed "Authorization failed" after a successful consent on hosts that strip the Authorization header (common on Apache/LiteSpeed FastCGI): the plugin now installs .htaccess pass-through rules automatically on activation and update.
* New public diagnostics endpoint (/wp-json/arzo-mcp/v1/diagnostics) reporting whether the Authorization header reaches WordPress — no header contents are ever echoed.
* The settings page now live-tests the Authorization header and shows the manual .htaccess/Nginx fix when the server strips it.
* Bearer token extraction gained an apache_request_headers() fallback.
* Uninstall removes the .htaccess block and version option.

= 1.0.3 =
* Fixed a fatal error ("Call to a member function on null") on sites where another plugin checks the current user during plugins_loaded — token/resource URLs are now built without rest_url(), which is unsafe that early.
* Fixed OAuth discovery returning the 404 page on subdirectory installs (example.com/blog): the .well-known interceptor now strips the install path prefix before matching.
* Well-known matching now also tolerates "/index.php" permalinks and serves /.well-known/openid-configuration for clients that try OIDC discovery.
* Bearer authentication now recognises MCP requests addressed via "?rest_route=" (plain permalinks) and no longer matches unrelated requests on plain-permalink sites.
* The authorize endpoint accepts trailing-slash and ?rest_route= variants of the resource URL instead of failing with invalid_target.
* JWT verification now rejects tokens whose header does not declare HS256.

= 1.0.1 =
* Registration endpoint reads parameters from any content type (more robust DCR).
* Added a manual Client ID generator and discovery diagnostics on the settings page for clients that cannot use automatic registration.

= 1.0.0 =
* Initial release: OAuth 2.1 (DCR, PKCE, refresh), discovery metadata, consent screen, bearer auth, admin page.
