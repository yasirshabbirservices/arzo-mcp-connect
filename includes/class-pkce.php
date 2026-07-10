<?php
/**
 * PKCE (RFC 7636) S256 verification.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP authorization spec mandates PKCE with the S256 method; `plain` is not
 * accepted.
 */
final class PKCE {

	/**
	 * Verify that BASE64URL(SHA256(verifier)) equals the challenge.
	 */
	public static function verify_s256( string $verifier, string $challenge ): bool {
		$len = strlen( $verifier );
		if ( $len < 43 || $len > 128 ) {
			return false;
		}
		$computed = Settings::base64url( hash( 'sha256', $verifier, true ) );
		return hash_equals( $challenge, $computed );
	}
}
