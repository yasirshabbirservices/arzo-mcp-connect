# Arzo MCP Connect

Connect **Claude.ai directly to your WordPress site** as a Remote MCP custom connector — no external gateway, no local software, no `settings.json`.

Arzo MCP Connect adds the one thing the official WordPress **MCP Adapter** lacks for hosted AI clients: a complete **OAuth 2.1 authorization server** running inside WordPress. Claude Web requires OAuth (with Dynamic Client Registration + PKCE) and refuses pasted credentials, so without this layer a direct connection is impossible. This plugin closes that gap.

Built by [Yasir Shabbir](https://yasirshabbir.com) · GPL-2.0-or-later.

## How it fits together

```
Claude.ai  ──OAuth 2.1 (DCR, PKCE)──▶  Arzo MCP Connect  ──▶  MCP Adapter  ──▶  your abilities
(custom connector)                     (this plugin: OAuth)    (official)        (WooCommerce, ACF, …)
```

- **MCP Adapter** (official plugin) exposes your WordPress abilities as MCP tools.
- **Arzo MCP Connect** (this plugin) adds OAuth 2.1 and maps the issued token to a WordPress user.
- **Claude** connects to your site's MCP URL and authenticates with your normal WordPress login.

Authorization uses your WordPress login, so Claude acts with exactly your account's capabilities — and your credentials are never shared with Claude, which only receives a short-lived, audience-bound access token.

## Requirements

- WordPress **6.8+** (Abilities API is built into core on 6.9+; on 6.8 install the Abilities API plugin).
- The official **MCP Adapter** plugin, active.
- PHP **7.4+**.
- Your site reachable over **HTTPS** from the public internet.

## Install

1. Activate the **MCP Adapter** plugin (and **Abilities API** on WP 6.8).
2. Upload and activate **Arzo MCP Connect** (Plugins → Add New → Upload Plugin).
3. **Settings → Arzo MCP Connect** → copy the Connector URL.
4. In Claude: **Settings → Connectors → Add custom connector** → paste the URL → **Connect** → sign in and approve.

## What it implements

- **OAuth 2.1**: Authorization Code + PKCE (S256), refresh-token rotation.
- **Dynamic Client Registration** (RFC 7591) at `/wp-json/arzo-mcp/v1/register`.
- **Token endpoint** at `/wp-json/arzo-mcp/v1/token`.
- **Authorization endpoint** via `admin-post.php?action=arzo_mcp_authorize` (uses WordPress login).
- **Discovery**: RFC 8414 at `/.well-known/oauth-authorization-server`, RFC 9728 at `/.well-known/oauth-protected-resource…`.
- **Bearer auth**: verifies the token on the MCP endpoint and resolves it to a WordPress user via `determine_current_user`; unauthenticated MCP requests get a `401` with a `WWW-Authenticate` discovery pointer.

Tokens are audience-bound (RFC 8707) HS256 JWTs signed with a per-site key generated on activation; refresh tokens are opaque and rotated on use.

## Notes & limitations

- Assumes WordPress is served at the domain root (for the `/.well-known/` documents). Subdirectory installs may need a rewrite for those paths.
- If your host strips the `Authorization` header, add to root `.htaccess`:
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
- This plugin is an independent companion to the official MCP Adapter; it does not modify or bundle it.

## License

[GPL-2.0-or-later](./LICENSE) © Yasir Shabbir
