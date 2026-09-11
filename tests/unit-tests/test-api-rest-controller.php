<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';
require_once dirname( __FILE__ ) . '/utils/trait-hook-snapshot.php';
require_once dirname( __FILE__ ) . '/utils/wc-memberships-stubs.php';
require_once dirname( __FILE__ ) . '/utils/class-newspack-ea-test-membership-stub.php';

/**
 * Tests REST API Controller.
 */
class Newspack_Test_API_Controller extends WP_UnitTestCase {

	use Newspack_Hook_Snapshot;

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
		// Install and activate Dependency Plugins. The Newspack plugin release
		// is pinned so the harness does not float with `releases/latest`; a
		// previously downloaded copy in the test WP install takes precedence
		// (Plugin_Manager::install skips existing directories), so delete it
		// when bumping the pin.
		$newspack_release_zip = 'https://github.com/Automattic/newspack-plugin/releases/download/v6.42.3/newspack-plugin.zip';
		echo esc_html( 'Installing Newspack...' . PHP_EOL );
		\Newspack\ExtendedAccess\Plugin_Manager::install( $newspack_release_zip );

		echo esc_html( 'Activating Newspack...' . PHP_EOL );
		\Newspack\ExtendedAccess\Plugin_Manager::activate( 'newspack-plugin' );

		echo esc_html( 'Initializing Newspack for test...' . PHP_EOL );
		\Newspack\Data_Events\Webhooks::init();
		do_action( 'init' );

		// The Newspack plugin's hooks were registered just now, so let the next
		// set_up() re-take the snapshot tear_down() restores from - otherwise
		// they are stripped after this class's first test whenever another test
		// class ran first. See the trait for the full mechanism.
		self::reset_hook_snapshot();

		echo esc_html( 'Initializing testing...' . PHP_EOL );
	}

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		parent::set_up();

		// Reset the controllable WC Memberships stubs between tests so a single
		// SUBSCRIBER-path test can't leak access into unrelated assertions.
		newspack_ea_reset_wc_memberships_stubs();

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

		// A second post with no unlock cookie, so first-time and repeat unlocks
		// can be told apart.
		$this->unlocked_post = $this->factory->post->create();

		// Start from a clean cookie jar: the endpoints now mirror granted
		// unlocks into $_COOKIE, which would otherwise leak between tests.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE = array();

		// Seed an existing unlock for ($this->post, $this->reader).
		$cookie_name = \Newspack\ExtendedAccess\REST_Controller::get_unlock_cookie_name( $this->post, $this->reader );
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE[ $cookie_name ] = 'true';
	}

	/**
	 * Marks the reader as having opened an article through an Extended Access
	 * entry point, which is what the unlock endpoint's permission check looks
	 * for in readers who signed in rather than registering through Google.
	 *
	 * @param int $user_id  The user ID.
	 * @param int $age      How long ago the entry happened, in seconds.
	 */
	private function record_extended_access_entry( $user_id, $age = 0 ) {
		update_user_meta( $user_id, \Newspack\ExtendedAccess\REST_Controller::EXTENDED_ACCESS_ENTRY_META, time() - $age );
	}

	/**
	 * Dispatches an unlock request for a post as the current user.
	 *
	 * @param int $post_id The post ID.
	 * @return WP_REST_Response
	 */
	private function dispatch_unlock_request( $post_id ) {
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/unlock-article' );
		$request->set_header( 'X-WP-Post-ID', $post_id );
		return $this->server->dispatch( $request );
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

		// Not the user ID, and not any reversible encoding of it. Note that a
		// substring check on the ID's digits would be meaningless here: a hex
		// digest of a single-digit user ID contains that digit about nine times
		// out of ten, so such an assertion passes or fails by luck.
		$this->assertNotEquals( 'wp_' . $this->reader, $user_state_id, 'The derived id must not be the plain WP user ID.' );
		$this->assertNotEquals( base64_encode( 'wp_' . $this->reader ), $user_state_id, 'The derived id must not be a base64 encoding of the WP user ID.' );
		$this->assertNotEquals( 'wp_' . $this->reader, base64_decode( $user_state_id ), 'The derived id must not decode back to the WP user ID.' );
		$this->assertMatchesRegularExpression( '/^wp_[0-9a-f]{32}$/', $user_state_id, 'The derived id should be a keyed digest.' );

		// Stable: a second request for the same reader yields the same id, so
		// Google can still correlate the reader across visits.
		$second_response_data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' ) )->get_data();
		$this->assertEquals( $user_state_id, $second_response_data['id'], 'The derived id must be stable for a given reader.' );

		// Distinct per reader, so Google cannot conflate two readers.
		wp_set_current_user( $this->subscriber );
		$other_response_data = $this->server->dispatch( new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' ) )->get_data();
		$this->assertNotEquals( $user_state_id, $other_response_data['id'], 'Two readers must not share a derived id.' );
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

		// The access check must be asked about this reader and this post. A
		// stub that only reports its return value would pass just as happily on
		// the wrong user, or on the `0` an absent post ID header normalises to.
		$this->assertEquals(
			array(
				array(
					'user_id' => $this->reader,
					'action'  => 'view',
					'args'    => array( 'post' => $this->post ),
				),
			),
			$GLOBALS['newspack_ea_test_wc_memberships_user_can_calls'],
			'The membership access check should be asked about the current reader and the requested post.'
		);
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
		$this->assertEquals(
			array( $this->reader ),
			$GLOBALS['newspack_ea_test_active_memberships_calls'],
			'Memberships should be looked up for the current reader.'
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
	 * The status endpoint re-issues the reader's session, which invalidates the
	 * nonce the page was rendered with. The nonce it hands back must belong to
	 * the session the reader's browser now holds, or every later Extended
	 * Access call fails its nonce check — including the unlock the reader is
	 * about to make.
	 */
	public function test_login_status__returned_nonce_matches_the_reissued_session() {
		wp_set_current_user( $this->reader );
		$original_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;

		// The cookie WordPress sends to the browser, captured after the endpoint
		// has had its chance to keep $_COOKIE in step with it.
		$browser_cookie = null;
		add_action(
			'set_logged_in_cookie',
			function ( $logged_in_cookie ) use ( &$browser_cookie ) {
				$browser_cookie = $logged_in_cookie;
			},
			99
		);

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $this->api_namespace . '/login/status' ) );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'X-WP-Nonce', $headers, 'The response must carry a nonce for the reader to continue with.' );
		$this->assertNotNull( $browser_cookie, 'Precondition: the endpoint re-issues the session.' );

		// Verify as the reader's browser would on its next request.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE[ LOGGED_IN_COOKIE ] = $browser_cookie;
		$nonce_is_valid              = wp_verify_nonce( $headers['X-WP-Nonce'], 'wp_rest' );

		if ( null === $original_cookie ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			$_COOKIE[ LOGGED_IN_COOKIE ] = $original_cookie;
		}

		$this->assertNotFalse( $nonce_is_valid, 'The nonce handed back must be usable by the session the reader now holds.' );
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

		$response = $this->dispatch_unlock_request( $this->post );

		$this->assertEquals( 401, $response->get_status(), 'Unauthenticated users should be denied access.' );
	}

	/**
	 * A reader who registered through Extended Access unlocks an article, and
	 * the unlock is recorded as the per-(user, post) cookie that lifts content
	 * gating. Asserting on the cookie rather than the status string matters
	 * because the cookie is the entire mechanism — the string is just a report.
	 */
	public function test_unlock_article__extended_access_user() {
		wp_set_current_user( $this->reader );
		update_user_meta( $this->reader, 'extended_access_sub', '0123456789' );

		$cookie_name = \Newspack\ExtendedAccess\REST_Controller::get_unlock_cookie_name( $this->unlocked_post, $this->reader );
		$this->assertArrayNotHasKey( $cookie_name, $_COOKIE, 'Precondition: the post starts locked for this reader.' );

		$response      = $this->dispatch_unlock_request( $this->unlocked_post );
		$response_data = $response->get_data();

		$this->assertEquals( 'UNLOCKED', $response_data['status'], 'Extended Access user should get UNLOCKED status.' );
		$this->assertArrayHasKey( $cookie_name, $_COOKIE, 'The unlock must be recorded as a cookie for this (user, post).' );
		$this->assertArrayNotHasKey( 'c', $response_data, 'Cookie name should not be exposed in the response.' );
	}

	/**
	 * The unlock is scoped to one post: unlocking an article must not lift the
	 * gate on any other.
	 */
	public function test_unlock_article__unlock_is_scoped_to_the_requested_post() {
		wp_set_current_user( $this->reader );
		update_user_meta( $this->reader, 'extended_access_sub', '0123456789' );
		$other_post = $this->factory->post->create();

		$this->dispatch_unlock_request( $this->unlocked_post );

		$this->assertArrayNotHasKey(
			\Newspack\ExtendedAccess\REST_Controller::get_unlock_cookie_name( $other_post, $this->reader ),
			$_COOKIE,
			'Unlocking one post must not unlock another.'
		);
	}

	/**
	 * A repeat call for an already-unlocked post is answered distinctly, so the
	 * client can reload on a first-time grant only. Without this the endpoint
	 * reports UNLOCKED forever and the client has nothing to stop reloading on.
	 */
	public function test_unlock_article__repeat_call_reports_already_unlocked() {
		wp_set_current_user( $this->reader );
		update_user_meta( $this->reader, 'extended_access_sub', '0123456789' );

		$first  = $this->dispatch_unlock_request( $this->unlocked_post )->get_data();
		$second = $this->dispatch_unlock_request( $this->unlocked_post )->get_data();

		$this->assertEquals( 'UNLOCKED', $first['status'], 'The first call is a fresh grant.' );
		$this->assertEquals( 'ALREADY_UNLOCKED', $second['status'], 'A repeat call must be distinguishable from a fresh grant.' );
	}

	/**
	 * A logged-in reader who never registered via Google Extended Access — for
	 * example a pre-existing publisher reader who reached the article via the
	 * "Already registered? Sign in" branch of Use Case 4 — must still be able
	 * to unlock the article. Gating on `extended_access_sub` alone 403'd these
	 * readers, breaking the dismiss-CTA unlock for any account predating EA.
	 */
	public function test_unlock_article__reader_who_arrived_via_extended_access_can_unlock() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );
		$this->record_extended_access_entry( $this->reader );

		$response      = $this->dispatch_unlock_request( $this->unlocked_post );
		$response_data = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'A reader in the Extended Access flow should be allowed to unlock.' );
		$this->assertEquals( 'UNLOCKED', $response_data['status'] );
	}

	/**
	 * The unlock endpoint mints the cookie that lifts content gating, so a
	 * logged-in reader who is not in the Extended Access flow at all — no EA
	 * registration, never arrived through a Showcase link — must not reach it.
	 */
	public function test_unlock_article__reader_outside_extended_access_flow_is_denied() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );
		delete_user_meta( $this->reader, \Newspack\ExtendedAccess\REST_Controller::EXTENDED_ACCESS_ENTRY_META );

		$response = $this->dispatch_unlock_request( $this->unlocked_post );

		$this->assertEquals( 403, $response->get_status(), 'Being logged in is not on its own grounds to unlock an article.' );
		$this->assertArrayNotHasKey(
			\Newspack\ExtendedAccess\REST_Controller::get_unlock_cookie_name( $this->unlocked_post, $this->reader ),
			$_COOKIE,
			'A denied request must not leave an unlock behind.'
		);
	}

	/**
	 * The Extended Access entry marker expires, so a reader who passed through
	 * Extended Access once cannot unlock arbitrary articles indefinitely.
	 */
	public function test_unlock_article__stale_extended_access_entry_is_denied() {
		wp_set_current_user( $this->reader );
		delete_user_meta( $this->reader, 'extended_access_sub' );
		$this->record_extended_access_entry( $this->reader, DAY_IN_SECONDS );

		$response = $this->dispatch_unlock_request( $this->unlocked_post );

		$this->assertEquals( 403, $response->get_status(), 'A stale Extended Access entry should not still grant unlocks.' );
	}
}
