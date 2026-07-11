<?php
/**
 * Inline SVG icon set (no external assets, no icon font).
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * A tiny library of stroke icons (Lucide/Feather-style geometric primitives),
 * rendered inline so nothing is fetched from a CDN. Every icon inherits
 * `currentColor` and is sized via CSS (.arzo-icon), so callers only pick a name.
 */
final class Icons {

	/**
	 * The icon path bodies, keyed by name. Each value is the inner markup of a
	 * `0 0 24 24` stroke SVG.
	 *
	 * @return array<string,string>
	 */
	private static function paths(): array {
		return array(
			'link'         => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
			'activity'     => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
			'key'          => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/>',
			'search'       => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
			'lifebuoy'     => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="M4.93 4.93l4.24 4.24"/><path d="M14.83 14.83l4.24 4.24"/><path d="M14.83 9.17l4.24-4.24"/><path d="M4.93 19.07l4.24-4.24"/>',
			'sliders'      => '<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>',
			'terminal'     => '<path d="M4 17l6-6-6-6"/><line x1="12" x2="20" y1="19" y2="19"/>',
			'copy'         => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
			'check'        => '<path d="M20 6L9 17l-5-5"/>',
			'refresh'      => '<path d="M21 12a9 9 0 0 0-9-9 9 9 0 0 0-6.36 2.64L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9 9 0 0 0 6.36-2.64L21 16"/><path d="M21 21v-5h-5"/>',
			'trash'        => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
			'external'     => '<path d="M15 3h6v6"/><path d="M10 14L21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
			'save'         => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
			'cloud'        => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/>',
			'zap'          => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
			'database'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
			'server'       => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
			'shield-alert' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
			'alert'        => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
			'plug'         => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
			'download'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
			'power'        => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
			'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
		);
	}

	/**
	 * Render an inline SVG icon. Unknown names render nothing.
	 *
	 * @param string $name        Icon key.
	 * @param string $extra_class Extra CSS class(es) appended to `arzo-icon`.
	 */
	public static function svg( string $name, string $extra_class = '' ): string {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		$class = 'arzo-icon' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" width="24" height="24" fill="none" '
			. 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
			. 'aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
