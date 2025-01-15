<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Firebase\JWT\JWT;
use Newspack\ExtendedAccess\Google_Jwt;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';

/**
 * Tests the Google JWT class methods.
 */
class Newspack_Test_Google_JWT extends WP_UnitTestCase {

	/**
	 * Setup for the tests.
	 */
	public function set_up(): void {
		parent::set_up();

		// Initialize .
		\Newspack\ExtendedAccess\Google_ExtendedAccess::init();
	}

	/**
	 * Test the decode() function to decode the JWT.
	 * Case: valid JWT.
	 *
	 * @covers Newspack\ExtendedAccess\Google_Jwt::decode
	 *
	 * @return void
	 */
	public function test_decode_valid_jwt(): void {
		// Create a private key.
		$private_key = openssl_pkey_new(
			array(
				'digest_alg'       => 'sha256',
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		// Get the public key.
		$public_key_details = openssl_pkey_get_details( $private_key );

		// Generate a unique Key ID (kid).
		$kid = 'test-key-id';

		// Convert the RSA public key to a JWK format.
		$jwk_key_set = $this->convert_rsa_to_jwk( $public_key_details, $kid );

		// Update the options.
		update_option( Google_Jwt::CACHE_OPTION_NAME, $jwk_key_set );
		update_option( 'newspack_extended_access__google_client_api_id', 'authorized-party' );

		// Create a message.
		$message = [
			'iss' => 'http://google.org',
			'aud' => 'http://google.com',
			'iat' => 1356999524,
			'nbf' => 1357000000,
			'azp' => 'authorized-party',
			'exp' => time() + 3600,
		];

		// Encode the message, including the correct "kid" in the header.
		$jwt = JWT::encode(
			$message,
			$private_key,
			'RS256',
			$kid
		);

		// Create the Google_Jwt object.
		$google_jwt = new Google_Jwt( $jwt );

		// Test the decode function.
		$result = $google_jwt->decode();

		// Assert the result.
		$this->assertNotWPError( $result );

		// Assert the message.
		$this->assertEquals( $message, (array) $result );
	}

	/**
	 * Test the decode() function to decode the JWT.
	 * Case: jwks is outdated.
	 *
	 * @covers Newspack\ExtendedAccess\Google_Jwt::decode
	 *
	 * @return void
	 */
	public function test_decode_outdated_jwks(): void {
		// Create a private key.
		$private_key = openssl_pkey_new(
			array(
				'digest_alg'       => 'sha256',
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		// Get the public key.
		$public_key_details = openssl_pkey_get_details( $private_key );

		// The Key stored in the cache is outdated and hence will be different from the one that will be in JWT.
		$invalid_kid = 'invalid-key-id';
		$valid_kid   = 'valid-key-id';

		// Convert the RSA public key to a JWK format.
		$valid_jwk_key_set   = $this->convert_rsa_to_jwk( $public_key_details, $valid_kid );
		$invalid_jwk_key_set = $this->convert_rsa_to_jwk( $public_key_details, $invalid_kid );

		// Update the options.
		// Update the cache with the invalid JWK key set to simulate an outdated cache.
		update_option( Google_Jwt::CACHE_OPTION_NAME, $invalid_jwk_key_set );
		update_option( 'newspack_extended_access__google_client_api_id', 'authorized-party' );

		// Create a message.
		$message = [
			'iss' => 'http://google.org',
			'aud' => 'http://google.com',
			'iat' => 1356999524,
			'nbf' => 1357000000,
			'azp' => 'authorized-party',
			'exp' => time() + 3600,
		];

		// Encode the message, including the correct "kid" in the header.
		$jwt = JWT::encode(
			$message,
			$private_key,
			'RS256',
			$valid_kid
		);

		// Create a mock for the Google_Jwt class to mock the get_jwks method.
		$google_jwt_mock = $this->getMockBuilder( Google_Jwt::class )
			->onlyMethods( ['get_jwks'] ) // Mock the get_jwks method
			->setConstructorArgs( [ $jwt ] ) // Pass the JWT to the constructor
			->getMock();

		// Configure the mock to return the cached JWK key set when get_jwks is called.
		// Because our code tries to refresh the jwks cache if the decode logic fails for the first time.
		// So, we need to return the valid updated JWK key set fetched from Google.
		$google_jwt_mock->method( 'get_jwks' )->willReturn( $valid_jwk_key_set ); // Return the cached JWK

		// Test the decode function.
		$result = $google_jwt_mock->decode();

		// Assert the result.
		$this->assertNotWPError( $result );

		// Assert the message.
		$this->assertEquals( $message, (array) $result );
	}

	/**
	 * Test the function that checks if we should refresh the JWKS cache.
	 * Case: The cache is outdated.
	 *
	 * @covers Newspack\ExtendedAccess\Google_Jwt::should_refresh_jwks_cache
	 *
	 * @return void
	 */
	public function test_should_refresh_jwks_cache_outdated(): void {
		// Update timestamp option.
		update_option( Google_Jwt::CACHE_TIMESTAMP_OPTION_NAME, time() - 500 );

		// Create the Google_Jwt object.
		$google_jwt = new Google_Jwt( '' );

		// Test the should_refresh_jwks_cache function.
		$this->assertTrue( $google_jwt->should_refresh_jwks_cache() );
	}

	/**
	 * Test the function that checks if we should refresh the JWKS cache.
	 * Case: The cache is not outdated.
	 *
	 * @covers Newspack\ExtendedAccess\Google_Jwt::should_refresh_jwks_cache
	 *
	 * @return void
	 */
	public function test_should_refresh_jwks_cache_not_outdated(): void {
		// Create the Google_Jwt object.
		$google_jwt = new Google_Jwt( '' );

		// Update timestamp option.
		update_option( Google_Jwt::CACHE_TIMESTAMP_OPTION_NAME, time() + 100 );

		// Test the should_refresh_jwks_cache function.
		$this->assertFalse( $google_jwt->should_refresh_jwks_cache() );
	}

	/**
	 * Test the get_jwks_cached() function.
	 *
	 * @covers Newspack\ExtendedAccess\Google_Jwt::get_jwks_cached
	 *
	 * @return void
	 */
	public function test_get_jwks_cached(): void {
		// Create the Google_Jwt object.
		$google_jwt = new Google_Jwt( '' );

		// Update the options.
		$jwk_key_set = [
			'keys' => [
				[
					'kty' => 'RSA',
					'kid' => 'test-key-id',
					'n'   => 'test-n',
					'e'   => 'test-e',
					'alg' => 'RS256',
					'use' => 'sig',
				],
			],
		];
		update_option( Google_Jwt::CACHE_OPTION_NAME, $jwk_key_set );

		// Test the get_jwks_cached function.
		$result = $google_jwt->get_jwks_cached();

		// Check the result.
		$this->assertEquals( $jwk_key_set, $result );
	}

	/**
	 * Test the update_jwks_cache() function.
	 *
	 * @covers Newspack\ExtendedAccess\Google_Jwt::update_jwks_cache
	 *
	 * @return void
	 */
	public function test_update_jwks_cache(): void {
		// Create the Google_Jwt object.
		$google_jwt = new Google_Jwt( '' );

		// Update the options.
		$jwk_key_set = [
			'keys' => [
				[
					'kty' => 'RSA',
					'kid' => 'test-key-id',
					'n'   => 'test-n',
					'e'   => 'test-e',
					'alg' => 'RS256',
					'use' => 'sig',
				],
			],
		];
		$google_jwt->update_jwks_cache( $jwk_key_set );

		// Test the get_jwks_cached function.
		$result = get_option( Google_Jwt::CACHE_OPTION_NAME );

		// Check the result.
		$this->assertEquals( $jwk_key_set, $result );
	}

	/**
	 * Converts an RSA public key to a JWK format with a "kid".
	 *
	 * @param array  $key_details The RSA public key details.
	 * @param string $kid         The Key ID.
	 * @return array The JWK key set.
	 */
	private function convert_rsa_to_jwk( $key_details, $kid ): array {
		// Encode the RSA public key.
		$n = base64_encode( $key_details['rsa']['n'] );
		$e = base64_encode( $key_details['rsa']['e'] );

		return [
			'keys' => [
				[
					'kty' => 'RSA',
					'kid' => $kid,
					'n'   => str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], $n ),
					'e'   => str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], $e ),
					'alg' => 'RS256',
					'use' => 'sig',
				],
			],
		];
	}
}
