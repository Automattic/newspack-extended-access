<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';

// Provide a controllable stub for WC Memberships' access check so tests can
// exercise the SUBSCRIBER code path without installing the (paid) plugin.
// Behaviour is toggled per-test via the $GLOBALS['newspack_ea_test_wc_memberships_user_can'] flag.
if ( ! function_exists( 'wc_memberships_user_can' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature must match WC Memberships'.
	function wc_memberships_user_can( $user_id, $action, $args = array() ) {
		return ! empty( $GLOBALS['newspack_ea_test_wc_memberships_user_can'] );
	}
}

// Lightweight stand-in for a WC_Memberships_User_Membership object, exposing
// just the `get_start_date()` shape the plugin reads.
if ( ! class_exists( 'Newspack_EA_Test_Membership_Stub' ) ) {
	/**
	 * Stub membership object that mirrors `WC_Memberships_User_Membership::get_start_date()`.
	 */
	class Newspack_EA_Test_Membership_Stub {
		/**
		 * Start timestamp returned by `get_start_date( 'timestamp' )`.
		 *
		 * @var int
		 */
		public $start_timestamp;

		/**
		 * @param int $start_timestamp Membership start timestamp.
		 */
		public function __construct( $start_timestamp ) {
			$this->start_timestamp = $start_timestamp;
		}

		/**
		 * @param string $format Format token. Only 'timestamp' is honoured here.
		 * @return int
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- format arg kept for parity with WC.
		public function get_start_date( $format = 'mysql' ) {
			return $this->start_timestamp;
		}
	}
}

// Controllable stub for WC Memberships' active-memberships lookup. Tests set
// $GLOBALS['newspack_ea_test_active_memberships'] to an array of stub objects.
if ( ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature must match WC.
	function wc_memberships_get_user_active_memberships( $user_id ) {
		return isset( $GLOBALS['newspack_ea_test_active_memberships'] )
			? $GLOBALS['newspack_ea_test_active_memberships']
			: array();
	}
}
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

		// Reset the controllable WC Memberships stub between tests so a single
		// SUBSCRIBER-path test can't leak access into unrelated assertions.
		$GLOBALS['newspack_ea_test_wc_memberships_user_can'] = false;
		$GLOBALS['newspack_ea_test_active_memberships']      = array();

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

		$this->assertFalse( $response_data['granted'], 'Anonymous user should not be granted.' );
	}

	/**
	 * A logged-in user without an `extended_access_sub` meta (i.e. registered
	 * via channels other than Google Extended Access) must still be returned
	 * to Google as a *registered* user — `id` and `registrationTimestamp` are
	 * the spec's signal that the visitor isn't anonymous. Without them, Google
	 * would show the registration intervention over an already-known reader.
	 */
	public function test_login_status__logged_in_user_without_ea_sub_is_recognised_as_registered() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );

		$request       = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertFalse( $response_data['granted'], 'Reader without subscription or metering cookie should not be granted.' );
		$this->assertArrayHasKey( 'id', $response_data, 'Logged-in users must expose an `id` so Google treats them as registered.' );
		$this->assertNotEmpty( $response_data['id'] );
		$this->assertArrayHasKey( 'registrationTimestamp', $response_data, 'Logged-in users must expose `registrationTimestamp`.' );
		$this->assertIsInt( $response_data['registrationTimestamp'] );
	}

	/**
	 * The userState `id` handed to Google for a non-EA reader must be opaque:
	 * stable across requests, but not decodable back to the internal WordPress
	 * user ID (which is sequential and therefore enumerable).
	 */
	public function test_login_status__derived_id_does_not_leak_wp_user_id() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );

		$request       = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$response_data = $this->server->dispatch( $request )->get_data();
		$user_state_id = $response_data['id'];

		$this->assertStringNotContainsString( (string) $this->reader, $user_state_id, 'The derived id must not embed the WP user ID.' );
		$this->assertNotEquals( 'wp_' . $this->reader, base64_decode( $user_state_id ), 'The derived id must not be a reversible encoding of the WP user ID.' );

		// Stable: a second request for the same reader yields the same id, so
		// Google can still correlate the reader across visits.
		$second_response_data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' ) )->get_data();
		$this->assertEquals( $user_state_id, $second_response_data['id'], 'The derived id must be stable for a given reader.' );
	}

	/**
	 * The unlock cookie name is hashed from the post ID, so a non-canonical
	 * numeric `X-WP-Post-ID` header (e.g. "042" or " 42") must normalise to the
	 * same value the unlock endpoint used — otherwise a granted metering cookie
	 * would never be found again.
	 */
	public function test_login_status__non_canonical_post_id_header_still_resolves_metering() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );

		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$request->set_header( 'X-WP-Post-ID', '0' . $this->post );
		$response_data = $this->server->dispatch( $request )->get_data();

		$this->assertTrue( $response_data['granted'], 'A zero-padded post ID header must resolve to the same unlock cookie.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
	}

	/**
	 * A logged-in user without an `extended_access_sub` meta who holds a
	 * metering unlock cookie for the requested post must be reported as
	 * `granted: true, grantReason: 'METERING'`. Previously the metering path
	 * was gated on the EA-registered sub, so non-EA-registered readers fell
	 * through to a `granted: false` even with a valid cookie.
	 */
	public function test_login_status__non_ea_user_with_metering_cookie_is_metered() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );

		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Reader with metering cookie should be granted.' );
		$this->assertEquals( 'METERING', $response_data['grantReason'] );
		$this->assertArrayHasKey( 'id', $response_data );
	}

	/**
	 * A logged-in publisher subscriber who never registered via Google EA must
	 * still be reported as `granted: true, grantReason: 'SUBSCRIBER'`. This is
	 * the spec scenario "Registered user with access through a publisher
	 * subscription" — Google must not show interventions over a subscriber's
	 * article, regardless of how the subscriber's account was created.
	 */
	public function test_login_status__non_ea_subscriber_returns_subscriber_state() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );
		$GLOBALS['newspack_ea_test_wc_memberships_user_can'] = true;

		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertTrue( $response_data['granted'], 'Subscriber should be granted regardless of EA registration history.' );
		$this->assertEquals( 'SUBSCRIBER', $response_data['grantReason'] );
		$this->assertArrayHasKey( 'id', $response_data );
		$this->assertArrayHasKey( 'subscriptionTimestamp', $response_data );
	}

	/**
	 * `subscriptionTimestamp` must reflect the user's actual membership start
	 * date, not their WP account creation date. When multiple memberships are
	 * active, the earliest start date is reported.
	 */
	public function test_login_status__subscription_timestamp_uses_membership_start_date() {
		wp_set_current_user( $this->reader );
		$GLOBALS['newspack_ea_test_wc_memberships_user_can'] = true;

		$earliest_start                                 = 1_600_000_000;
		$later_start                                    = 1_700_000_000;
		$GLOBALS['newspack_ea_test_active_memberships'] = array(
			new Newspack_EA_Test_Membership_Stub( $later_start ),
			new Newspack_EA_Test_Membership_Stub( $earliest_start ),
		);

		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 'SUBSCRIBER', $response_data['grantReason'] );
		$this->assertEquals(
			$earliest_start,
			$response_data['subscriptionTimestamp'],
			'subscriptionTimestamp should be the earliest active membership start date.'
		);
		$this->assertNotEquals(
			$response_data['registrationTimestamp'],
			$response_data['subscriptionTimestamp'],
			'subscriptionTimestamp must no longer be hardcoded to the user_registered date.'
		);
	}

	/**
	 * When no active memberships can be resolved, `subscriptionTimestamp`
	 * gracefully falls back to the registration timestamp so the response
	 * shape stays spec-compliant.
	 */
	public function test_login_status__subscription_timestamp_falls_back_when_memberships_missing() {
		wp_set_current_user( $this->reader );
		$GLOBALS['newspack_ea_test_wc_memberships_user_can'] = true;
		$GLOBALS['newspack_ea_test_active_memberships']      = array();

		$request = new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' );
		$request->set_header( 'X-WP-Post-ID', $this->post );
		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 'SUBSCRIBER', $response_data['grantReason'] );
		$this->assertEquals(
			$response_data['registrationTimestamp'],
			$response_data['subscriptionTimestamp'],
			'Fallback should use the registration timestamp when membership data is unavailable.'
		);
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
	 * A logged-in reader who never registered via Google Extended Access — for
	 * example a pre-existing publisher reader who reached the article via the
	 * "Already registered? Sign in" branch of Use Case 4 — must still be able
	 * to unlock the article when Google's GAA library decides to grant them
	 * EA. Previously the endpoint gated on `extended_access_sub` user-meta
	 * and 403'd these users, which broke the dismiss-CTA → unlock flow for
	 * any reader whose account predated EA.
	 */
	public function test_unlock_article__logged_in_user_without_ea_sub_can_unlock() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );

		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/unlock-article' );
		$request->set_header( 'X-WP-Post-ID', $this->post );

		$response      = $this->server->dispatch( $request );
		$response_data = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Logged-in users should be allowed to unlock irrespective of EA registration history.' );
		$this->assertEquals( 'UNLOCKED', $response_data['status'] );
	}
}
