<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';
/**
 * Tests REST API Controller.
 */
class Newspack_Test_API_Controller extends WP_UnitTestCase {
	/**
	 * Plugin slug/folder.
	 *
	 * @var string
	 */
	protected $api_namespace = '/newspack-extended-access/v1';

	/**
	 * Setup for the tests.
	 */
	public static function set_up_before_class() {
		// Install and activate Dependency Plugins.
		$newspack_rel_latest = 'https://github.com/Automattic/newspack-plugin/releases/latest/download/newspack-plugin.zip';
		echo esc_html( 'Installing Newspack...' . PHP_EOL );
		\Newspack\ExtendedAccess\Plugin_Manager::install( $newspack_rel_latest );

		echo esc_html( 'Activating Newspack...' . PHP_EOL );
		\Newspack\ExtendedAccess\Plugin_Manager::activate( 'newspack-plugin' );

		echo esc_html( 'Initializing Newspack for test...' . PHP_EOL );
		\Newspack\Data_Events\Webhooks::init();
		do_action( 'init' );

		echo esc_html( 'Initializing testing...' . PHP_EOL );
	}

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		parent::set_up();

		// Setup Server to mock requests.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		// Initialize Extended Access REST Controller.
		\Newspack\ExtendedAccess\REST_Controller::init();
		do_action( 'rest_api_init' );

		// Create sample post(s) required for test(s).
		$this->post = $this->factory->post->create();

		// Create sample user(s) required for test(s).
		$this->subscriber = $this->factory->user->create(
			array(
				'role'  => 'subscriber',
				'email' => 'reader@test.com',
			)
		);
		$this->reader     = \Newspack\Reader_Activation::register_reader( 'reader@test.com', 'Reader' );
		wp_logout();

		// Create a cookie for testing purpose.
		$cookie_name = \Newspack\ExtendedAccess\REST_Controller::get_unlock_cookie_name( $this->post, $this->reader );
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE[ $cookie_name ] = 'true';
	}

	/**
	 * Test that the routes are all registered.
	 */
	public function test_register_route() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( $this->api_namespace, $routes, '' );
		$this->assertArrayHasKey( $this->api_namespace . '/login/status', $routes );
		$this->assertArrayHasKey( $this->api_namespace . '/google/register', $routes );
		$this->assertArrayHasKey( $this->api_namespace . '/unlock-article', $routes );
	}

	/**
	 * Ensures anonymous user should not be granted.
	 */
	public function test_login_status__anonymous_user() {
		// Set to no logged-in user.
		wp_set_current_user( 0 );

		// Prepare and send Request.
		$request       = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertFalse( false, $response_data['granted'], 'Anonymous user should not be granted.' );
	}

	/**
	 * Ensures non registered user should not be granted.
	 */
	public function test_login_status__non_registered_reader() {
		// Set to Newspack Reader user.
		wp_set_current_user( $this->reader );

		$request       = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertFalse( $response_data['granted'], 'Non registered subscriber user should not be granted.' );
	}

	/**
	 * Ensures already registered and subscribed user should be granted.
	 */
	public function test_login_status__registered_reader() {
		// Set to Newspack Reader user.
		wp_set_current_user( $this->reader );

		// Add sample subscriber meta to Newspack Reader user.
		update_user_meta( $this->reader, 'extended_access_sub', '0123456789' );

		// Prepare and send Request.
		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Registered subscriber should be granted.' );
		$this->assertEquals( 'reader@test.com', $response_data['email'] );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * Helper to mock a Google JWT token via the decoded token filter.
	 *
	 * @param string $email The email for the mock token.
	 * @param string $sub   The Google sub identifier.
	 */
	private function mock_google_token( $email, $sub = '0123456789' ) {
		add_filter(
			'newspack_extended_access_decoded_token',
			function () use ( $email, $sub ) {
				return (object) [
					'email'          => $email,
					'email_verified' => true,
					'sub'            => $sub,
					'azp'            => get_option( 'newspack_extended_access__google_client_api_id', '' ),
				];
			}
		);
	}

	/**
	 * A new user registering via Google should be granted metered access for
	 * the post they registered from. The unlock cookie is set server-side as
	 * part of the registration response.
	 */
	public function test_registration__new_user() {
		wp_set_current_user( 0 );
		$this->mock_google_token( 'newuser@test.com', '10698610589970977261' );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/google/register' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$request->set_body( 'mock-jwt' );

		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Newly registered Extended Access user should be granted metered access.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * A second new user registering for the same post should also be granted —
	 * metered access is per (user, post) and registration always unlocks it.
	 */
	public function test_registration__new_user_non_subscriber() {
		wp_set_current_user( 0 );
		$this->mock_google_token( 'another-newuser@test.com', '10698610589970977261' );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/google/register' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$request->set_body( 'mock-jwt' );

		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Newly registered Extended Access user should be granted metered access.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * An existing user logging in via Google should also be granted metered
	 * access for the post they logged in from.
	 */
	public function test_registration__existing_user_subscriber() {
		wp_set_current_user( 0 );
		$this->mock_google_token( 'reader@test.com', '0123456789' );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/google/register' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$request->set_body( 'mock-jwt' );

		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Existing user logging in should be granted metered access.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * Ensures unauthenticated user cannot access the unlock-article endpoint.
	 */
	public function test_unlock_article__unauthenticated_user() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/unlock-article' );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'Unauthenticated users should be denied access.' );
	}

	/**
	 * Ensures authenticated Extended Access user can unlock an article without leaking cookie name.
	 */
	public function test_unlock_article__extended_access_user() {
		wp_set_current_user( $this->reader );
		update_user_meta( $this->reader, 'extended_access_sub', '0123456789' );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/unlock-article' );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 'UNLOCKED', $response_data['status'], 'Extended Access user should get UNLOCKED status.' );
		$this->assertArrayNotHasKey( 'c', $response_data, 'Cookie name should not be exposed in the response.' );
	}

	/**
	 * Ensures logged-in user without Extended Access registration cannot unlock articles.
	 */
	public function test_unlock_article__non_extended_access_user() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/unlock-article' );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status(), 'Users without Extended Access registration should be denied.' );
	}

}
