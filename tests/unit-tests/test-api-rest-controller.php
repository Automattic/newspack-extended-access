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
		$cookie_name = 'newspack_' . md5( $this->post . $this->reader );
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
		$this->assertArrayHasKey( $this->api_namespace . '/login/google', $routes );
		$this->assertArrayHasKey( $this->api_namespace . '/subscription/register', $routes );
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
	 * Register a user (sent by SwG) and ensures they are not granted.
	 */
	public function test_registration__new_user() {
		// Set to no logged-in user.
		wp_set_current_user( 0 );

		// Prepare and send Request.
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/login/google' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		// Following gaaUser is the part of internal testing user.
		$request->set_body( 'eyJhbGciOiJSUzI1NiIsImtpZCI6Ijg2OTY5YWVjMzdhNzc4MGYxODgwNzg3NzU5M2JiYmY4Y2Y1ZGU1Y2UiLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL2FjY291bnRzLmdvb2dsZS5jb20iLCJuYmYiOjE2ODI1Njk0NjEsImF1ZCI6IjIyNDAwMTY5MDI5MS01MmY2YWYzNHFpNmI3dWc3aDZyMHZmOHRkdWRsbWhpMy5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbSIsInN1YiI6IjEwNjk4NjEwNTg5OTcwOTc3MjYxOSIsImVtYWlsIjoibmV3c3BhY2sudGVzdC5lYS4yMDIzQGdtYWlsLmNvbSIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJhenAiOiIyMjQwMDE2OTAyOTEtNTJmNmFmMzRxaTZiN3VnN2g2cjB2Zjh0ZHVkbG1oaTMuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJuYW1lIjoiTlAgRUEiLCJwaWN0dXJlIjoiaHR0cHM6Ly9saDMuZ29vZ2xldXNlcmNvbnRlbnQuY29tL2EvQUdObXl4WnU1WGhMZVFrUkpRckNBM1U0aG5IUGlvcU5DbU5YYjdtMmRIYUU9czk2LWMiLCJnaXZlbl9uYW1lIjoiTlAiLCJmYW1pbHlfbmFtZSI6IkVBIiwiaWF0IjoxNjgyNTY5NzYxLCJleHAiOjE2ODI1NzMzNjEsImp0aSI6IjQwNTkxMDI1ZTQzN2M4MGY1OThkYmE2NDhjMDRlMWMxMTUyYWEyZDEifQ.jbuImJJOLzsakSuMJJOXzapPg7C8aQ156rAL6e81H7H3rHPYLLJg-vLc6rJ0NyXsPxKhbl1CnktTsIzwxMky11xc-a5_hR_bUqzlrJd_bZFGYzLzmtmgdHF1zunLMTeLXKgxSvmFd2296xooqRzY_R_ucDaqDgCLASfBst682u7NoPKO-9DpuTvTm-p4_mWeIwuq3tFaOhlD-s9vyUpw9o7MJSqezwv0d4Z_KKNqPRZ0I8Xn3JLOxkwHqVSkK29Hlsp9Zqh6onesVenZbI6n1VxtkqR8Dv_Hl64MkYIIgoYR_ekeVwK0UAquYRhtcc5VHuaGcC3oy02lsLKLrW7BXQ' );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertFalse( $response_data['granted'], 'Registered subscriber should be granted.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * Ensures new user (with no subscription) should not be granted.
	 */
	public function test_registration__new_user_non_subscriber() {
		// Set to no logged-in user.
		wp_set_current_user( 0 );

		// Prepare and send Request.
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/login/google' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		// Following gaaUser is the part of internal testing user.
		$request->set_body( 'eyJhbGciOiJSUzI1NiIsImtpZCI6Ijg2OTY5YWVjMzdhNzc4MGYxODgwNzg3NzU5M2JiYmY4Y2Y1ZGU1Y2UiLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL2FjY291bnRzLmdvb2dsZS5jb20iLCJuYmYiOjE2ODI1Njk0NjEsImF1ZCI6IjIyNDAwMTY5MDI5MS01MmY2YWYzNHFpNmI3dWc3aDZyMHZmOHRkdWRsbWhpMy5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbSIsInN1YiI6IjEwNjk4NjEwNTg5OTcwOTc3MjYxOSIsImVtYWlsIjoibmV3c3BhY2sudGVzdC5lYS4yMDIzQGdtYWlsLmNvbSIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJhenAiOiIyMjQwMDE2OTAyOTEtNTJmNmFmMzRxaTZiN3VnN2g2cjB2Zjh0ZHVkbG1oaTMuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJuYW1lIjoiTlAgRUEiLCJwaWN0dXJlIjoiaHR0cHM6Ly9saDMuZ29vZ2xldXNlcmNvbnRlbnQuY29tL2EvQUdObXl4WnU1WGhMZVFrUkpRckNBM1U0aG5IUGlvcU5DbU5YYjdtMmRIYUU9czk2LWMiLCJnaXZlbl9uYW1lIjoiTlAiLCJmYW1pbHlfbmFtZSI6IkVBIiwiaWF0IjoxNjgyNTY5NzYxLCJleHAiOjE2ODI1NzMzNjEsImp0aSI6IjQwNTkxMDI1ZTQzN2M4MGY1OThkYmE2NDhjMDRlMWMxMTUyYWEyZDEifQ.jbuImJJOLzsakSuMJJOXzapPg7C8aQ156rAL6e81H7H3rHPYLLJg-vLc6rJ0NyXsPxKhbl1CnktTsIzwxMky11xc-a5_hR_bUqzlrJd_bZFGYzLzmtmgdHF1zunLMTeLXKgxSvmFd2296xooqRzY_R_ucDaqDgCLASfBst682u7NoPKO-9DpuTvTm-p4_mWeIwuq3tFaOhlD-s9vyUpw9o7MJSqezwv0d4Z_KKNqPRZ0I8Xn3JLOxkwHqVSkK29Hlsp9Zqh6onesVenZbI6n1VxtkqR8Dv_Hl64MkYIIgoYR_ekeVwK0UAquYRhtcc5VHuaGcC3oy02lsLKLrW7BXQ' );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertFalse( $response_data['granted'], 'Newly registered subscriber should not be granted.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * Ensures existing user with subscription are granted.
	 */
	public function test_registration__existing_user_subscriber() {
		// Set to no logged-in user.
		wp_set_current_user( 0 );

		// Prepare and send Request.
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/login/google' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		// Following gaaUser is the part of internal testing user: 'reader@test.com'.
		$request->set_body( 'eyJhbGciOiJSUzI1NiIsImtpZCI6Ijg2OTY5YWVjMzdhNzc4MGYxODgwNzg3NzU5M2JiYmY4Y2Y1ZGU1Y2UiLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL2FjY291bnRzLmdvb2dsZS5jb20iLCJuYmYiOjE2ODI1Njk0NjEsImF1ZCI6InNhbXBsZS5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbSIsInN1YiI6IjAxMjM0NTY3ODkiLCJlbWFpbCI6InJlYWRlckB0ZXN0LmNvbSIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJhenAiOiJzYW1wbGUuYXBwcy5nb29nbGV1c2VyY29udGVudC5jb20iLCJuYW1lIjoiTmV3c3BhY2sgUmVhZGVyIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL3NhbXBsZSIsImdpdmVuX25hbWUiOiJOUCIsImZhbWlseV9uYW1lIjoiRUEiLCJpYXQiOjE2ODI1Njk3NjEsImV4cCI6OTk5OTk5OTk5OSwianRpIjoiMDEyMzQ1Njc4OSJ9.yk1_Ayzo4Q4gYB2vSRExmgZ982t3Rg0qy2edirnP-eWZwT9SYULYi29c3JvzAPq1X4_KJnxaWFXzRhUtDs1amJbqDtM2JoIun6i9BKTbK3NtL1gFzpv9MM9s5rmWtx9lU0ayQX6nydSx9VefEyWyXI5hdOrLr-COMI_vCpK15R7C-G83Qz6OEEvHBzm3I_nu7BbyNvGq2s1bMQkfxgAuV6A9bCDZYQKBkHQHb6eNoIZEwnSneTtd03qG_B8gHRSO7v_4l234ZD0Z17tNs9kNXPTttLpl6Q-_vZrsEI-LbYLPaR1F3uM7BkFVAzpufGGoAstCDSr_7s-zV3qM9AAnjg' );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Newly registered subscriber should be granted.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * Ensures non existing user cannot have cookie created at their end.
	 */
	public function test_subscriber_registration__non_existing_user() {
		// Set to no logged-in user.
		wp_set_current_user( 0 );

		// Prepare and send Request.
		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/subscription/register' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_header( 'X-WP-User-Email', 'non.existing.user@test.com' );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 'NO_USER_OR_POST', $response_data['data'] );
	}

}
