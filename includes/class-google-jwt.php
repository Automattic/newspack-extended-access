<?php
/**
 * Handles Google HWE token verification
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

use Firebase\JWT\JWK as Firebase_JWK;
use Firebase\JWT\JWT as Firebase_JWT;
use Exception;

/**
 * Class responsible for verifying Google JWT tokens.
 *
 * It fetches and caches Google JWKs, and uses them to verify the JWT token.
 *
 * Cache is refreshed anytime a signature verification fails, but only if it's older than 5 minutes to avoid abuse.
 */
class Google_Jwt {

	/**
	 * The name of the option where we store the Google JWKs.
	 *
	 * @var string
	 */
	const CACHE_OPTION_NAME = 'newspack_extended_access_google_jwk';

	/**
	 * The name of the option where we store the Google JWKs cache creation timestamp.
	 *
	 * @var string
	 */
	const CACHE_TIMESTAMP_OPTION_NAME = 'newspack_extended_access_google_jwk_timestamp';

	/**
	 * The URL where we can find the Google OpenID configuration.
	 *
	 * @var string
	 */
	const GOOGLE_CONFIG_URL = 'https://accounts.google.com/.well-known/openid-configuration';

	/**
	 * The raw string with the received JWT token.
	 *
	 * @var string
	 */
	private $payload;

	/**
	 * Constructor
	 *
	 * @param string $payload The raw string with the received JWT token.
	 */
	public function __construct( $payload ) {
		$this->payload = $payload;
	}

	/**
	 * Get the JWKS URI from Google OpenID configuration.
	 *
	 * @return string|bool The JWKS URI or false if it could not be retrieved.
	 */
	public function get_jwks_uri() {
		$response = wp_remote_get( self::GOOGLE_CONFIG_URL );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body   = wp_remote_retrieve_body( $response );
		$config = json_decode( $body, true );
		if ( ! isset( $config['jwks_uri'] ) ) {
			return false;
		}

		return $config['jwks_uri'];
	}

	/**
	 * Get the JWKS from Google if the cache is expired and cache them.
	 *
	 * @return array|bool The JWKS or false if it could not be retrieved.
	 */
	public function get_jwks() {
		if ( ! $this->should_refresh_jwks_cache() ) {
			return $this->get_jwks_cached();
		}

		$jwks_uri = $this->get_jwks_uri();
		if ( ! $jwks_uri ) {
			return false;
		}

		$response = wp_remote_get( $jwks_uri );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$jwks = json_decode( $body, true );
		if ( ! isset( $jwks['keys'] ) ) {
			return false;
		}

		$this->update_jwks_cache( $jwks );

		return $jwks;
	}

	/**
	 * Update the JWKS cache.
	 *
	 * @param array $value The JWKS to cache.
	 */
	public function update_jwks_cache( $value ) {
		update_option( self::CACHE_OPTION_NAME, $value );
		update_option( self::CACHE_TIMESTAMP_OPTION_NAME, time() );
	}

	/**
	 * Get the JWKS from cache.
	 *
	 * @return array|bool The JWKS or false if it could not be retrieved.
	 */
	public function get_jwks_cached() {
		return get_option( self::CACHE_OPTION_NAME, false );
	}

	/**
	 * Check if we should refresh the JWKS cache.
	 *
	 * Cache is refreshed anytime a signature verification fails, but only if it's older than 5 minutes to avoid abuse.
	 */
	public function should_refresh_jwks_cache() {
		$timestamp = get_option( self::CACHE_TIMESTAMP_OPTION_NAME, 0 );
		return time() - $timestamp > 300;
	}

	/**
	 * Decode the JWT token.
	 *
	 * @return mixed|WP_Error The decoded token or a WP_Error if it could not be decoded.
	 */
	public function decode() {
		$jwks = $this->get_jwks_cached();
		if ( ! $jwks ) {
			$jwks = $this->get_jwks();
		}

		if ( ! $jwks ) {
			return new \WP_Error( 'jwt_error', __( 'Failed to retrieve jwks.', 'newspack-extended-access' ) );
		}
		try {
			$decoded = Firebase_JWT::decode( $this->payload, Firebase_JWK::parseKeySet( $jwks ) );
		} catch ( Exception $e ) {
			// refresh Google JWKs cache and try again.
			$jwks = $this->get_jwks();

			// If we still can't get the JWKs, return an error.
			if ( ! $jwks ) {
				return new \WP_Error( 'jwk_error', __( 'Failed to re-fetch & refresh jwks cache.', 'newspack-extended-access' ), array( 'status' => 500 ) );
			}

			try {
				$decoded = Firebase_JWT::decode( $this->payload, Firebase_JWK::parseKeySet( $jwks ) );
			} catch ( Exception $e ) {
				return new \WP_Error( 'jwt_error', $e->getMessage() );
			}
		}

		// Validate the token's issuer. Per Google's ID token verification guidance,
		// `iss` must be either `accounts.google.com` or `https://accounts.google.com`.
		$valid_issuers = array( 'https://accounts.google.com', 'accounts.google.com' );
		if ( ! isset( $decoded->iss ) || ! in_array( $decoded->iss, $valid_issuers, true ) ) {
			return new \WP_Error( 'newspack_extended_access_google_token', __( 'Invalid token issuer.', 'newspack-extended-access' ), array( 'status' => 403 ) );
		}

		// Validate the token's audience. `aud` must match our Google Client API ID.
		// `azp` (authorized party) is not a substitute: it's optional and may be absent,
		// and a token issued for a different app could still carry the expected `azp`.
		// Per RFC 7519 `aud` is either a single string or an array of strings, so
		// accept both shapes.
		$google_client_api_id = get_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION, '' );
		$token_audiences      = isset( $decoded->aud ) ? (array) $decoded->aud : array();
		if ( '' === $google_client_api_id || ! in_array( $google_client_api_id, $token_audiences, true ) ) {
			return new \WP_Error( 'newspack_extended_access_google_token', __( 'Invalid token audience.', 'newspack-extended-access' ), array( 'status' => 403 ) );
		}

		return $decoded;
	}

}
