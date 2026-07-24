<?php
/**
 * Tests the Newspack Access Control (content gates) integration.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess\DependencyChecker;
use Newspack\ExtendedAccess\Google_ExtendedAccess;
use Newspack\ExtendedAccess\Initializer;
use Newspack\ExtendedAccess\REST_Controller;
use Newspack\ExtendedAccess\SinglePost_Subscription;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';

/**
 * Tests that unlocks granted via Google Extended Access are honored by the
 * first-party Newspack Access Control gating (WooCommerce Memberships inactive).
 *
 * This class defines the NEWSPACK_CONTENT_GATES constant, which cannot be
 * undefined again, so it lives in its own phpunit testsuite ("Access Control
 * Integration") that is ordered last in phpunit.xml - and `composer test`
 * runs it as a separate phpunit process for full isolation. Any future test
 * that asserts flag-off behavior must not share a process with this suite.
 * (PHPUnit's run-class-in-separate-process mode is not usable here: the
 * re-executed WP tests bootstrap hangs on install.php against the parent's
 * open DB connections. Its annotation must also never be spelled with the
 * at-sign in this docblock - PHPUnit parses it from prose.)
 */
class Newspack_Test_Integration_Access_Control extends WP_UnitTestCase {

	/**
	 * The Newspack plugin release the suite is verified against. Pinned so the
	 * harness does not float with `releases/latest` (a moving target that can
	 * silently change what these tests exercise). Note Plugin_Manager::install
	 * skips the download when a newspack-plugin directory already exists in
	 * the test WP install, so a stale copy must be deleted for a new pin to
	 * take effect.
	 *
	 * @var string
	 */
	const NEWSPACK_PLUGIN_ZIP = 'https://github.com/Automattic/newspack-plugin/releases/download/v6.42.3/newspack-plugin.zip';

	/**
	 * Sample post ID.
	 *
	 * @var int
	 */
	protected $post_id;

	/**
	 * Sample reader user ID.
	 *
	 * @var int
	 */
	protected $reader_id;

	/**
	 * Load the Newspack plugin, which provides the Access Control system.
	 */
	public static function set_up_before_class() {
		// Install and activate the Newspack plugin (no-ops when already present).
		\Newspack\ExtendedAccess\Plugin_Manager::install( self::NEWSPACK_PLUGIN_ZIP );
		\Newspack\ExtendedAccess\Plugin_Manager::activate( 'newspack-plugin' );

		// The plugin loaded after the bootstrap fired 'init'; fire it again so
		// init-dependent registrations (e.g. default access rules) run.
		do_action( 'init' );

		// Enable the Access Control feature flag. Content_Gate re-reads the
		// constant on every call when IS_TEST_ENV is defined.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		parent::set_up();

		SinglePost_Subscription::init();

		$this->post_id   = $this->factory->post->create();
		$this->reader_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Remove unlock cookies and gates set by a test.
	 */
	public function tear_down() {
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, $this->reader_id ) ] );
		// phpcs:enable
		foreach ( \Newspack\Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		// The stand-in for the Access Control implementation names a method the
		// pinned Newspack release does not have, so a test that leaves it
		// registered would fatal any later test that fires the filter. Removing
		// it here rather than inline keeps that true even when a test fails
		// partway through.
		remove_filter( 'newspack_post_has_restrictions', DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK );
		$this->reset_post_gates_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Set the unlock cookie for a post/user pair.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 */
	private function set_unlock_cookie( $post_id, $user_id ) {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE[ REST_Controller::get_unlock_cookie_name( $post_id, $user_id ) ] = '1';
	}

	/**
	 * Clear Content_Restriction_Control request-scoped caches, so gates
	 * created mid-test become visible to restriction checks.
	 */
	private function reset_post_gates_cache() {
		// Not all cache properties exist in every Newspack plugin release;
		// reset whichever this one has.
		foreach ( array( 'post_gates_map', 'post_gate_id_map', 'post_gate_layout_id_map' ) as $property_name ) {
			if ( ! property_exists( \Newspack\Content_Restriction_Control::class, $property_name ) ) {
				continue;
			}
			$cache_property = new ReflectionProperty( \Newspack\Content_Restriction_Control::class, $property_name );
			$cache_property->setAccessible( true );
			$cache_property->setValue( null, array() );
		}
	}

	/**
	 * Create a published paywall-style gate applying to all posts, which the
	 * test reader fails (email domain whitelist).
	 *
	 * @param array $layout_meta Optional meta to set on the gate layout post (e.g. overlay style).
	 * @return int Gate ID.
	 */
	private function create_failing_paywall_gate( $layout_meta = array() ) {
		$paywall_gate_id = \Newspack\Content_Gate::create_gate( array( 'title' => 'EA Paywall Gate' ) );
		\Newspack\Content_Gate::update_gate_settings(
			$paywall_gate_id,
			array(
				'title'         => 'EA Paywall Gate',
				'status'        => 'publish',
				'priority'      => 1,
				'content_rules' => array(
					array(
						'slug'  => 'post_types',
						'value' => array( 'post' ),
					),
				),
				'registration'  => array(
					'active'               => false,
					'metering'             => array(
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					),
					'require_verification' => false,
					'gate_id'              => 0,
				),
				'custom_access' => array(
					'active'       => true,
					'metering'     => array(
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					),
					'gate_id'      => 0,
					'access_rules' => array(
						array(
							'slug'  => 'email_domain',
							'value' => 'vip.example.com',
						),
					),
				),
			)
		);
		if ( ! empty( $layout_meta ) ) {
			// Gate layouts are separate posts, auto-created by
			// update_gate_settings() and referenced from the settings meta;
			// apply layout meta (e.g. overlay style) to each of them, falling
			// back to the gate post itself for releases without layout posts.
			$layout_ids = array();
			foreach ( array( 'registration', 'custom_access' ) as $settings_key ) {
				$settings = get_post_meta( $paywall_gate_id, $settings_key, true );
				if ( is_array( $settings ) && ! empty( $settings['gate_layout_id'] ) ) {
					$layout_ids[] = (int) $settings['gate_layout_id'];
				}
			}
			if ( empty( $layout_ids ) ) {
				$layout_ids[] = $paywall_gate_id;
			}
			foreach ( array_unique( $layout_ids ) as $layout_id ) {
				foreach ( $layout_meta as $meta_key => $meta_value ) {
					update_post_meta( $layout_id, $meta_key, $meta_value );
				}
			}
		}
		$this->reset_post_gates_cache();
		return $paywall_gate_id;
	}

	/**
	 * Without WooCommerce Memberships active, the legacy restriction handler
	 * must be a no-op instead of a fatal.
	 */
	public function test_no_fatal_without_woocommerce_memberships() {
		$this->assertFalse( DependencyChecker::is_wc_memberships_loaded(), 'Precondition: WCM is not loaded in this suite.' );

		SinglePost_Subscription::manage_paywall_restriction();

		// The handler's whole job under Access Control is to do nothing, so the
		// observable outcome is that it removed no Woo Memberships hook and
		// reaching this line at all means it did not fatal.
		$this->assertFalse(
			has_action( 'the_content', 'wc_memberships_the_content' ),
			'No Woo Memberships content handler may be left registered when WCM is inactive.'
		);
	}

	/**
	 * A logged-in reader with a valid unlock cookie is not restricted: the
	 * restriction predicate itself is lifted, which opens every Access Control
	 * surface that keys off it (inline gate, overlay gate, prompt suppression,
	 * article_view activity suppression).
	 */
	public function test_unlock_cookie_lifts_restriction_predicate() {
		wp_set_current_user( $this->reader_id );
		$this->set_unlock_cookie( $this->post_id, $this->reader_id );
		$this->assertFalse(
			apply_filters( 'newspack_is_post_restricted', true, $this->post_id ),
			'A valid unlock cookie must lift the restriction predicate.'
		);
	}

	/**
	 * Without an unlock cookie the post stays restricted.
	 */
	public function test_no_cookie_stays_restricted() {
		wp_set_current_user( $this->reader_id );
		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', true, $this->post_id ),
			'Without an unlock cookie the restriction must hold.'
		);
	}

	/**
	 * An unlock cookie for a different post does not unlock this post.
	 */
	public function test_cookie_for_other_post_stays_restricted() {
		wp_set_current_user( $this->reader_id );
		$other_post_id = $this->factory->post->create();
		$this->set_unlock_cookie( $other_post_id, $this->reader_id );
		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', true, $this->post_id ),
			'An unlock for another post must not unlock this post.'
		);
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $other_post_id, $this->reader_id ) ] );
	}

	/**
	 * An unlock cookie minted for a different user does not unlock the post
	 * for the current user.
	 */
	public function test_cookie_for_other_user_stays_restricted() {
		$other_reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->reader_id );

		$this->set_unlock_cookie( $this->post_id, $other_reader_id );

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', true, $this->post_id ),
			'An unlock minted for another user must not unlock the post.'
		);
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, $other_reader_id ) ] );
	}

	/**
	 * Anonymous visitors never get an unlock, even if a cookie is present.
	 */
	public function test_anonymous_with_cookie_stays_restricted() {
		wp_set_current_user( 0 );
		$this->set_unlock_cookie( $this->post_id, 0 );
		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', true, $this->post_id ),
			'Anonymous visitors must stay restricted.'
		);
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, 0 ) ] );
	}

	/**
	 * The overlay gate must not render over an unlocked post: it is an
	 * independent rendering path from the inline gate, keying off the
	 * restriction predicate.
	 */
	public function test_overlay_gate_not_rendered_for_unlocked_post() {
		$this->create_failing_paywall_gate( array( 'style' => 'overlay' ) );
		wp_set_current_user( $this->reader_id );
		$this->go_to( get_permalink( $this->post_id ) );

		// With an unlock: predicate lifted, overlay must not render.
		$this->set_unlock_cookie( $this->post_id, $this->reader_id );
		$this->assertFalse( \Newspack\Content_Gate::is_post_restricted( $this->post_id ), 'Precondition: the unlock lifts the restriction.' );
		ob_start();
		\Newspack\Content_Gate::render_overlay_gate();
		$overlay_output_unlocked = ob_get_clean();
		$this->assertStringNotContainsString(
			'newspack-content-gate__overlay-gate',
			$overlay_output_unlocked,
			'The overlay gate must not render over an unlocked post.'
		);

		// Without the unlock: reader is restricted and the overlay renders.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, $this->reader_id ) ] );
		$this->assertTrue( \Newspack\Content_Gate::is_post_restricted( $this->post_id ), 'Precondition: the reader fails the paywall rule.' );
		ob_start();
		\Newspack\Content_Gate::render_overlay_gate();
		$overlay_output_locked = ob_get_clean();
		$this->assertStringContainsString(
			'newspack-content-gate__overlay-gate',
			$overlay_output_locked,
			'The overlay gate renders for a locked reader (control).'
		);
	}

	/**
	 * Campaigns prompts are not suppressed and the article_view reader
	 * activity is not dropped on an unlocked view.
	 */
	public function test_popups_and_article_view_not_suppressed_for_unlocked_post() {
		$this->create_failing_paywall_gate();
		wp_set_current_user( $this->reader_id );
		$this->go_to( get_permalink( $this->post_id ) );
		$article_view_activity = array( 'action' => 'article_view' );

		// Control: on a locked view both surfaces suppress.
		\Newspack\Content_Gate::is_post_restricted( $this->post_id ); // Warm the gate map, as restrict_post() does on a real request.
		$this->assertTrue(
			apply_filters( 'newspack_popups_assess_has_disabled_popups', false ),
			'Prompts are suppressed on a locked view (control).'
		);
		$this->assertFalse(
			apply_filters( 'newspack_reader_activity_article_view', $article_view_activity ),
			'The article_view activity is dropped on a locked view (control).'
		);

		// With the unlock: neither surface suppresses.
		$this->set_unlock_cookie( $this->post_id, $this->reader_id );
		$this->assertFalse(
			apply_filters( 'newspack_popups_assess_has_disabled_popups', false ),
			'Prompts must not be suppressed on an unlocked view.'
		);
		$this->assertSame(
			$article_view_activity,
			apply_filters( 'newspack_reader_activity_article_view', $article_view_activity ),
			'The article_view activity must not be dropped on an unlocked view.'
		);
	}

	/**
	 * An unlocked view must not consume a metered view: the metering
	 * short-circuit fires for the unlocked post.
	 */
	public function test_metering_short_circuit_with_unlock() {
		wp_set_current_user( $this->reader_id );
		$this->set_unlock_cookie( $this->post_id, $this->reader_id );
		$this->go_to( get_permalink( $this->post_id ) );
		$this->assertTrue(
			apply_filters( 'newspack_content_gate_metering_short_circuit', null ),
			'Metering must be short-circuited for an unlocked view.'
		);
	}

	/**
	 * Metering proceeds normally when there is no unlock.
	 */
	public function test_metering_not_short_circuited_without_unlock() {
		wp_set_current_user( $this->reader_id );
		$this->go_to( get_permalink( $this->post_id ) );
		$this->assertNull(
			apply_filters( 'newspack_content_gate_metering_short_circuit', null ),
			'Metering must not be short-circuited without an unlock.'
		);
	}

	/**
	 * A gated post is not restricted inside a feed, so Google News can ingest
	 * the full article. Without this, Access Control's restrict_feeds setting -
	 * which is on by default - truncates the body to the gate excerpt and the
	 * article stops being eligible for Extended Access entirely.
	 */
	public function test_gated_post_is_unrestricted_in_feeds() {
		wp_set_current_user( 0 );

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', true, $this->post_id ),
			'Outside a feed the gated post stays restricted.'
		);

		global $wp_query;
		$was_feed          = $wp_query->is_feed;
		$wp_query->is_feed = true;
		$restricted_in_feed = apply_filters( 'newspack_is_post_restricted', true, $this->post_id );
		$wp_query->is_feed = $was_feed;

		$this->assertFalse(
			$restricted_in_feed,
			'A gated post must not be restricted inside a feed, or Google News cannot ingest it.'
		);
	}

	/**
	 * The unlock-article endpoint reports a metered unlock as UNLOCKED, not
	 * SUBSCRIBER: holding an unlock cookie is not full gate access.
	 */
	public function test_unlock_article_reports_unlocked_not_subscriber() {
		$this->create_failing_paywall_gate();
		wp_set_current_user( $this->reader_id );
		$this->set_unlock_cookie( $this->post_id, $this->reader_id );

		$unlock_request = new WP_REST_Request( 'POST', '/newspack-extended-access/v1/unlock-article' );
		$unlock_request->set_header( 'X-WP-Post-ID', (string) $this->post_id );
		$unlock_response = REST_Controller::api_unlock_article( $unlock_request );

		$this->assertSame(
			'UNLOCKED',
			$unlock_response->get_data()['status'],
			'A reader whose only access is the unlock must be reported as UNLOCKED (metering grant), not SUBSCRIBER.'
		);
		// The gate evaluation above detaches the unlock filter to avoid reading a
		// metered unlock as full access. Leaving it detached would silently stop
		// lifting restrictions for the rest of the request, and set_up() re-runs
		// init() for every test, so nothing else here would notice.
		$this->assertNotFalse(
			has_filter( 'newspack_is_post_restricted', [ SinglePost_Subscription::class, 'maybe_unrestrict_unlocked_post' ] ),
			'The unlock filter must be restored after the gate access check.'
		);
	}

	/**
	 * The unlock-article endpoint reports SUBSCRIBER for a reader with actual
	 * gate access (no gates restrict them).
	 */
	public function test_unlock_article_reports_subscriber_with_gate_access() {
		// No gates: the reader has full access to the post.
		wp_set_current_user( $this->reader_id );

		$unlock_request = new WP_REST_Request( 'POST', '/newspack-extended-access/v1/unlock-article' );
		$unlock_request->set_header( 'X-WP-Post-ID', (string) $this->post_id );
		$unlock_response = REST_Controller::api_unlock_article( $unlock_request );

		$this->assertSame(
			'SUBSCRIBER',
			$unlock_response->get_data()['status'],
			'A reader with full access must be reported as SUBSCRIBER.'
		);
	}

	/**
	 * Access Control is detected as an available gating system.
	 */
	public function test_access_control_detected() {
		$this->assertTrue(
			DependencyChecker::is_newspack_access_control_active(),
			'Access Control must be detected when Newspack is active and the feature flag is on.'
		);
	}

	/**
	 * On a site with Access Control (and no WCM), dependencies are valid as
	 * long as the Google Client API ID is configured.
	 */
	public function test_dependencies_valid_with_access_control_only() {
		update_option( 'newspack_extended_access__google_client_api_id', 'example.com' );
		$this->assertTrue(
			Initializer::has_valid_dependencies(),
			'EA must consider dependencies valid on an Access Control site without WCM.'
		);
		delete_option( 'newspack_extended_access__google_client_api_id' );
	}

	/**
	 * Without a Google Client API ID, dependencies are invalid even with
	 * Access Control present.
	 */
	public function test_dependencies_invalid_without_google_client_id() {
		delete_option( 'newspack_extended_access__google_client_api_id' );
		$this->assertFalse(
			Initializer::has_valid_dependencies(),
			'The Google Client API ID requirement still applies under Access Control.'
		);
	}

	/**
	 * The LD+JSON schema reports a gated post as not accessible for free.
	 */
	public function test_ld_json_marks_gated_post_as_not_free() {
		$this->go_to( get_permalink( $this->post_id ) );

		ob_start();
		Google_ExtendedAccess::render_extended_access_ld_json( true );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'newspack-extended-access-schema', $output, 'The LD+JSON schema must be emitted.' );
		$this->assertStringContainsString( '"isAccessibleForFree":false', $output, 'A gated post must be marked not accessible for free.' );
	}

	/**
	 * The LD+JSON schema marks ungated posts as accessible for free.
	 */
	public function test_ld_json_marks_ungated_post_as_free() {
		$this->go_to( get_permalink( $this->post_id ) );

		ob_start();
		Google_ExtendedAccess::render_extended_access_ld_json( false );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'newspack-extended-access-schema', $output, 'The LD+JSON schema must be emitted.' );
		$this->assertStringContainsString( '"isAccessibleForFree":true', $output, 'An ungated post must be marked accessible for free.' );
	}

	/**
	 * The restriction state becomes knowable as soon as the Access Control
	 * implementation registers on the filter.
	 *
	 * Only the predicate is exercised: the stand-in callback names a method
	 * the pinned Newspack release does not carry, so firing the filter would
	 * fatal. What the predicate reads is the registration, not the result.
	 */
	public function test_restriction_state_available_once_access_control_implements_the_filter() {
		add_filter( 'newspack_post_has_restrictions', DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK );

		$this->assertTrue(
			DependencyChecker::is_newspack_restriction_state_available(),
			'The restriction state is knowable once the Access Control implementation is registered.'
		);

		remove_filter( 'newspack_post_has_restrictions', DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK );

		$this->assertFalse(
			DependencyChecker::is_newspack_restriction_state_available(),
			'The restriction state stops being knowable when the implementation goes away.'
		);
	}

	/**
	 * The callback name is a cross-repo contract: if the Newspack plugin ever
	 * renames or relocates its implementation, this plugin goes permanently
	 * quiet with no other symptom. The test above cannot catch that - it
	 * registers the same constant it then looks for - so assert the constant
	 * against the real class.
	 *
	 * Skips while the pinned Newspack release predates the implementation, and
	 * starts asserting the moment that pin moves past it.
	 */
	public function test_restriction_callback_constant_matches_the_newspack_implementation() {
		list( $class_name, $method_name ) = DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK;

		if ( ! method_exists( $class_name, $method_name ) ) {
			$this->markTestSkipped(
				sprintf(
					'The pinned Newspack release has no %s::%s(). Once the pin moves past the content-gate implementation, this test asserts the contract instead of skipping.',
					$class_name,
					$method_name
				)
			);
		}

		$this->assertTrue(
			is_callable( DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK ),
			'The pinned callback must name a real, callable Newspack implementation.'
		);
		$this->assertNotFalse(
			has_filter( 'newspack_post_has_restrictions', DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK ),
			'The Newspack implementation must be registered under the exact callback identity this plugin looks for.'
		);
	}

	/**
	 * `Content_Gate::post_has_restrictions()` returns a filtered default of
	 * false, so a Newspack build that does not yet answer
	 * `newspack_post_has_restrictions` for content gates reports every post as
	 * ungated. Emitting the schema off that default would tell Google that
	 * gated articles are free, so no schema is emitted until the Access
	 * Control implementation is present. This decouples the plugin's release
	 * from the Newspack plugin's: EA may be deployed to an Access Control site
	 * ahead of the implementation without mislabeling gated content.
	 *
	 * The pinned Newspack release predates that implementation, so this test
	 * exercises the real "too early" state rather than a simulated one.
	 */
	public function test_ld_json_skipped_when_restriction_state_is_unknowable() {
		$this->go_to( get_permalink( $this->post_id ) );

		$this->assertNotFalse(
			has_filter( 'newspack_post_has_restrictions' ),
			'Guards the reason the check is by name: Woo Memberships registers on this filter even with Memberships inactive, so a bare has_filter() would wrongly report the state as knowable.'
		);
		$this->assertFalse(
			has_filter( 'newspack_post_has_restrictions', DependencyChecker::NEWSPACK_POST_HAS_RESTRICTIONS_CALLBACK ),
			'Guards the premise: the pinned Newspack release must not carry the Access Control implementation, or this test proves nothing.'
		);
		$this->assertFalse(
			DependencyChecker::is_newspack_restriction_state_available(),
			'The restriction state is not knowable without the Access Control implementation of the filter.'
		);

		ob_start();
		Google_ExtendedAccess::add_extended_access_ld_json();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'newspack-extended-access-schema', $output, 'No schema may be emitted when the restriction state is unknowable.' );
	}

}
