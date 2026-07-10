<?php
/**
 * Server-rendered OAuth consent screen (emerald "Arzo" theme).
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the approval screen shown to a logged-in WordPress user after Claude
 * sends them to the authorize endpoint. Styled with the yasirshabbir.com design
 * language: emerald on near-black, glassmorphism, glow.
 */
final class Consent {

	/**
	 * @param array<string,string> $hidden      Hidden OAuth params echoed on submit.
	 * @param string               $action_url  Where the approve form posts.
	 * @param string               $client_name Registered client name (Claude).
	 * @param string               $user_label  The WP user granting access.
	 */
	public static function render( array $hidden, string $action_url, string $client_name, string $user_label ): string {
		$fields = '';
		foreach ( $hidden as $name => $value ) {
			$fields .= sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}
		$nonce = wp_nonce_field( 'arzo_mcp_consent', '_arzo_nonce', true, false );

		$site   = esc_html( get_bloginfo( 'name' ) );
		$client = esc_html( $client_name );
		$user   = esc_html( $user_label );
		$logout = esc_url( wp_logout_url( $action_url ) );

		return '<!doctype html><html lang="en"><head><meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="robots" content="noindex, nofollow" />
<title>Authorize · ' . $site . '</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet" />
<style>
:root{--bg:160 25% 5%;--card:160 20% 8%;--border:160 15% 18%;--mut:160 10% 55%;--fg:60 10% 95%;--pri:160 100% 42%;--acc:160 100% 50%}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;font-family:"Space Grotesk",system-ui,sans-serif;color:hsl(var(--fg));background:hsl(var(--bg));display:flex;align-items:center;justify-content:center;padding:1.5rem;position:relative;overflow:hidden}
.blob{position:absolute;width:520px;height:520px;border-radius:9999px;background:hsl(var(--pri)/.18);filter:blur(96px);top:-180px;right:-120px}
main{position:relative;z-index:1;width:100%;max-width:430px}
.card{background:linear-gradient(135deg,hsl(0 0% 100%/.08),hsl(0 0% 100%/.02)),hsl(var(--card)/.6);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid hsl(var(--border)/.5);border-radius:1.5rem;padding:2rem;box-shadow:0 0 60px hsl(var(--pri)/.12)}
.eyebrow{text-transform:uppercase;letter-spacing:.08em;font-weight:600;font-size:.8rem;color:hsl(var(--pri));margin:0 0 .5rem}
h1{font-family:"Syne",sans-serif;font-weight:700;font-size:1.6rem;line-height:1.1;margin:0 0 .35rem;letter-spacing:-.01em}
.grad{background:linear-gradient(135deg,hsl(var(--pri)),hsl(var(--acc)));-webkit-background-clip:text;background-clip:text;color:transparent}
.subtitle{color:hsl(var(--mut));font-size:.95rem;margin:0 0 1.25rem;line-height:1.6}
.subtitle strong{color:hsl(var(--fg))}
.who{background:hsl(var(--bg)/.5);border:1px solid hsl(var(--border)/.6);border-radius:.75rem;padding:.75rem 1rem;font-size:.9rem;margin:0 0 1.25rem}
.who span{color:hsl(var(--mut))}
button{width:100%;min-height:48px;font-family:inherit;font-weight:600;font-size:1rem;color:hsl(var(--bg));background:hsl(var(--pri));border:none;border-radius:.75rem;cursor:pointer;box-shadow:0 0 30px hsl(var(--pri)/.45);transition:transform .2s,box-shadow .2s}
button:hover{transform:scale(1.02);box-shadow:0 0 50px hsl(var(--pri)/.65)}
.foot{color:hsl(var(--mut));font-size:.78rem;margin:1.4rem 0 0;text-align:center;line-height:1.6}
.foot a{color:hsl(var(--pri));text-decoration:none}
@media (prefers-reduced-motion:reduce){button{transition:none}button:hover{transform:none}}
</style></head>
<body><div class="blob" aria-hidden="true"></div>
<main><div class="card">
<p class="eyebrow">Arzo MCP Connect</p>
<h1>Authorize <span class="grad">' . $client . '</span></h1>
<p class="subtitle">' . $client . ' is requesting access to <strong>' . $site . '</strong> via the Model Context Protocol. Approving grants it access with your WordPress permissions.</p>
<div class="who"><span>Signed in as</span> <strong>' . $user . '</strong></div>
<form method="post" action="' . esc_url( $action_url ) . '">' . $fields . $nonce . '
<input type="hidden" name="arzo_consent" value="approve" />
<button type="submit">Approve &amp; Connect</button>
</form>
<p class="foot">Not you? <a href="' . $logout . '">Sign out</a><br />Powered by <a href="https://yasirshabbir.com" rel="noopener">Arzo MCP Connect</a></p>
</div></main></body></html>';
	}
}
