<?php
/**
 * Minimal HS256 JWT encode/decode (no external dependency).
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * A tiny, self-contained JWT implementation supporting only HS256 — which is all
 * this plugin needs (it is both the issuer and the verifier of its own tokens).
 * Keeping it dependency-free avoids bundling a Composer library into the plugin.
 */
final class JWT {

	/**
	 * Encode a claim set into a signed HS256 JWT.
	 *
	 * @param array<string,mixed> $claims Token claims.
	 * @param string              $key    HMAC signing key.
	 */
	public static function encode( array $claims, string $key ): string {
		$header  = array(
			'alg' => 'HS256',
			'typ' => 'JWT',
		);
		$segments = array(
			Settings::base64url( (string) wp_json_encode( $header ) ),
			Settings::base64url( (string) wp_json_encode( $claims ) ),
		);
		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, $key, true );
		$segments[]    = Settings::base64url( $signature );
		return implode( '.', $segments );
	}

	/**
	 * Verify a JWT's signature and expiry and return its claims, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function decode( string $jwt, string $key ): ?array {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

		$expected  = hash_hmac( 'sha256', $header_b64 . '.' . $payload_b64, $key, true );
		$signature = Settings::base64url_decode( $signature_b64 );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$payload = json_decode( Settings::base64url_decode( $payload_b64 ), true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		if ( isset( $payload['exp'] ) && time() >= (int) $payload['exp'] ) {
			return null;
		}
		return $payload;
	}
}
