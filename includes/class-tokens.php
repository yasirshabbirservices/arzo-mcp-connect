<?php
/**
 * Token issuance & verification.
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Issues audience-bound HS256 access tokens (RFC 8707) and rotating opaque
 * refresh tokens. A token minted for this site's MCP resource cannot be used
 * against a different audience.
 */
final class Tokens {

	/** @var OAuth_Store */
	private $store;

	public function __construct( OAuth_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Issue an access + refresh token pair for a WordPress user.
	 *
	 * @return array<string,mixed> OAuth token response.
	 */
	public function issue_token_set( int $user_id, string $client_id, string $scope, string $resource ): array {
		$now    = time();
		$claims = array(
			'iss'       => Settings::issuer(),
			'sub'       => (string) $user_id,
			'aud'       => $resource,
			'scope'     => $scope,
			'client_id' => $client_id,
			'iat'       => $now,
			'exp'       => $now + Settings::ACCESS_TTL,
			'jti'       => bin2hex( random_bytes( 8 ) ),
		);
		$access  = JWT::encode( $claims, Settings::signing_key() );
		$refresh = bin2hex( random_bytes( 32 ) );

		$this->store->save_refresh(
			$refresh,
			array(
				'user_id'   => $user_id,
				'client_id' => $client_id,
				'scope'     => $scope,
				'resource'  => $resource,
			),
			Settings::REFRESH_TTL
		);

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => Settings::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	/**
	 * Exchange (and rotate) a refresh token for a new token set, or null if invalid.
	 *
	 * @return array<string,mixed>|null
	 */
	public function refresh( string $refresh_token, string $client_id ): ?array {
		$record = $this->store->consume_refresh( $refresh_token );
		if ( null === $record ) {
			return null;
		}
		if ( (string) $record['client_id'] !== $client_id ) {
			return null;
		}
		return $this->issue_token_set(
			(int) $record['user_id'],
			(string) $record['client_id'],
			(string) $record['scope'],
			(string) $record['resource']
		);
	}

	/**
	 * Verify a bearer access token bound to the given resource; returns the
	 * WordPress user id, or null.
	 */
	public function verify( string $token, string $resource ): ?int {
		$claims = JWT::decode( $token, Settings::signing_key() );
		if ( null === $claims ) {
			return null;
		}
		if ( ( $claims['iss'] ?? '' ) !== Settings::issuer() ) {
			return null;
		}
		if ( ( $claims['aud'] ?? '' ) !== $resource ) {
			return null;
		}
		$user_id = (int) ( $claims['sub'] ?? 0 );
		return $user_id > 0 ? $user_id : null;
	}
}
