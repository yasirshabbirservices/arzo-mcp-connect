<h1 align="center">Arzo MCP Connect</h1>

<p align="center">
  <strong>Connect Claude.ai directly to your WordPress site as a Remote MCP custom connector — no external gateway, no local software.</strong>
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-1.1.0-00D68F.svg">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.8%2B-21759b.svg">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg">
  <img alt="License" src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg">
</p>

---

## What it does

Claude.ai can connect to remote **MCP (Model Context Protocol)** servers, but it requires a full **OAuth 2.1** handshake and refuses pasted credentials. The official WordPress **MCP Adapter** exposes your site's abilities as MCP tools — but it doesn't ship the OAuth authorization server that hosted clients like Claude need.

**Arzo MCP Connect is that missing piece.** It adds a complete, self-contained OAuth 2.1 authorization server on top of the MCP Adapter, so you can paste your site's URL into Claude and connect — the whole flow lives inside WordPress.

```
┌────────────┐   OAuth 2.1 (DCR, PKCE, refresh)    ┌────────────────────┐
│  Claude.ai │ ─────────────────────────────────► │  Arzo MCP Connect  │  ← this plugin
└────────────┘   Bearer access token (per-user)    └─────────┬──────────┘
       │                                                      │ maps token → WP user
       │            MCP tool calls (JSON-RPC)                 ▼
       └────────────────────────────────────────►  ┌────────────────────┐
                                                    │    MCP Adapter     │  exposes abilities as tools
                                                    └────────────────────┘
```

Because authorization uses your normal WordPress login, **Claude acts with exactly your account's capabilities**. Your password is never shared — Claude only receives a short-lived, audience-bound access token.

## Features

- **OAuth 2.1** — Authorization Code + **PKCE (S256)**, **Dynamic Client Registration** (RFC 7591), rotating refresh tokens.
- **Discovery** — RFC 8414 authorization-server metadata and RFC 9728 protected-resource metadata, served even when WordPress wouldn't normally route `/.well-known/` (including subdirectory installs).
- **Audience-bound HS256 tokens** (RFC 8707) — a token minted for this site can't be replayed elsewhere.
- **Designed settings page** — one-click connector URL, live status checks (MCP Adapter + `Authorization` header), and a manual Client ID fallback.
- **Built-in setup guides** — copy-paste fixes for Cloudflare, LiteSpeed Cache, WP Rocket / W3TC, Nginx / Apache, and ModSecurity, right on the settings screen.
- **Privacy-safe diagnostic log** — opt-in, records the OAuth/bearer flow with **no tokens or secrets stored** (only outcomes, reasons, and short fingerprints), with copy & clear.
- **Hosting-resilient** — automatically adds the `Authorization` header pass-through to `.htaccess`, and marks the auth challenge uncacheable so CDNs/caches don't break the retry.
- **Dependency-free** — no Composer libraries bundled; a tiny self-contained JWT and PKCE implementation.

## Requirements

| | |
|---|---|
| WordPress | 6.8+ |
| PHP | 7.4+ |
| Dependency | [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin (and, on WP 6.8, the Abilities API plugin; built into core on 6.9+) |
| Permalinks | "Pretty" permalinks enabled (Settings → Permalinks → **Post name**) |
| Reachability | Site reachable over **HTTPS** from the public internet |

## Installation

1. Install and activate the official **MCP Adapter** plugin.
2. Install and activate **Arzo MCP Connect** (Plugins → Add New → Upload Plugin).
3. Go to **Settings → Arzo MCP Connect** and copy the **Connector URL**.
4. In Claude: **Settings → Connectors → Add custom connector**, paste the URL, and click **Connect**.
5. Sign in with your WordPress account and approve. Claude can now use your site's MCP tools.

## Troubleshooting

The settings page has a **Setup & troubleshooting** section with tabbed, copy-paste guides. The common ones:

### Login works, but Claude says "Authorization failed"

Almost always a **CDN / cache / WAF** in front of WordPress dropping Claude's bearer-token request. Enable the diagnostic log (Advanced → Diagnostic log), retry, and read the events — if you see `token_issued` with nothing after it, the request is being blocked upstream. Exclude these paths everywhere:

```
/wp-json/mcp/
/wp-json/arzo-mcp/
/.well-known/
```

- **Cloudflare** — Security → WAF → Custom rules → create a rule that **Skips** Managed Rules, Bot Fight Mode, and Rate Limiting when `URI Path` starts with the paths above. (Bot protection and managed rules frequently flag JWT bearer tokens.)
- **LiteSpeed Cache** — Cache → Excludes → *Do Not Cache URIs* → add the paths.
- **WP Rocket / W3TC / WP Super Cache** — add the paths to the never-cache / rejected-URI list.
- **ModSecurity** — ask your host to disable it for `/wp-json/mcp/` (or toggle it off for the domain in cPanel); its rules false-positive on JWTs and return an intermittent `403 "Your request was blocked."`.

### "The MCP endpoint / discovery URL returns a 404"

Enable **pretty permalinks** (Settings → Permalinks → Post name → Save). The plugin's discovery-URL check on the settings page confirms both documents return JSON.

### "My host strips the Authorization header"

The plugin writes the pass-through rule to `.htaccess` automatically. If your host blocks that, the **Authorization header** status turns red with the exact manual fix — for Apache/LiteSpeed:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

…or for Nginx (php-fpm), in your `location ~ \.php$` block:

```nginx
fastcgi_pass_header Authorization;
```

## Security model

- **PKCE S256 is mandatory** — `plain` is rejected; verifiers are length-checked and compared in constant time.
- **Redirect URIs are allowlisted** to Claude's callback hosts (and loopback for local testing); extend via the `arzo_mcp_allowed_redirect_hosts` filter.
- **Authorization codes are single-use**, hashed before becoming a storage key, and expire in 10 minutes; refresh tokens rotate on every use.
- **Access tokens are audience-bound** and signed HS256 with a 48-byte site key; the JWT header must declare HS256 or verification fails.
- **The token carries the approving user's ID** — every MCP action runs through WordPress's own `current_user_can()` checks with that user's capabilities.
- **The diagnostic log never stores secrets** — tokens, codes, and verifiers are reduced to 8-character SHA-256 fingerprints.

## Configuration reference

| Setting | Default | Notes |
|---|---|---|
| MCP server route | `mcp/mcp-adapter-default-server` | REST route of your MCP Adapter server |
| Access token TTL | 1 hour | |
| Refresh token TTL | 30 days | |
| Authorization code TTL | 10 minutes | |
| `arzo_mcp_allowed_redirect_hosts` | `[]` | Filter to allow extra OAuth redirect hosts |

## Endpoints

| Purpose | Path |
|---|---|
| Authorization-server metadata | `/.well-known/oauth-authorization-server` |
| Protected-resource metadata | `/.well-known/oauth-protected-resource/…` |
| Dynamic Client Registration | `/wp-json/arzo-mcp/v1/register` |
| Token | `/wp-json/arzo-mcp/v1/token` |
| Header diagnostics | `/wp-json/arzo-mcp/v1/diagnostics` |
| Authorization (browser) | `/wp-admin/admin-post.php?action=arzo_mcp_authorize` |

## Development

Plain, dependency-free PHP — no build step.

```
arzo-mcp-connect.php        Bootstrap: constants, requires, activation/upgrade hooks
includes/
  class-plugin.php          Composition root — wires and registers subsystems
  class-settings.php        Options, signing key, derived URLs
  class-metadata.php        RFC 8414 / 9728 discovery + /.well-known routing
  class-oauth-rest.php      /register, /token, /diagnostics endpoints
  class-authorize.php       Browser authorize step (admin-post based)
  class-consent.php         Server-rendered consent screen
  class-tokens.php          Token issuance / verification
  class-jwt.php             Minimal HS256 JWT
  class-pkce.php            PKCE S256 verification
  class-oauth-store.php     Clients (option) + codes/refresh (transients)
  class-bearer-auth.php     Bearer → WP identity + 401 discovery challenge
  class-htaccess.php        Authorization header pass-through rules
  class-debug.php           Opt-in privacy-safe diagnostic log
  class-admin.php           Settings page (enqueues assets/)
assets/
  css/admin.css             Tokenised (BEM + CSS vars + clamp/calc) admin styles
  js/admin.js               Copy buttons, live auth check, setup tabs
```

## License

[GPL-2.0-or-later](LICENSE) © [Yasir Shabbir](https://yasirshabbir.com).
