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
 */
class Newspack_Test_Integration_Access_Control extends WP_UnitTestCase {

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
		$newspack_rel_latest = 'https://github.com/Automattic/newspack-plugin/releases/latest/download/newspack-plugin.zip';
		\Newspack\ExtendedAccess\Plugin_Manager::install( $newspack_rel_latest );
		\Newspack\ExtendedAccess\Plugin_Manager::activate( 'newspack-plugin' );

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
	 * Remove any unlock cookies set by a test.
	 */
	public function tear_down() {
		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, $this->reader_id ) ] );
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, $this->reader_id + 1 ) ] );
		// phpcs:enable
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
	 * Without WooCommerce Memberships active, the legacy restriction handler
	 * must be a no-op instead of a fatal.
	 */
	public function test_no_fatal_without_woocommerce_memberships() {
		$this->assertFalse( function_exists( 'wc_memberships' ), 'Precondition: WCM is not loaded in this suite.' );
		SinglePost_Subscription::manage_paywall_restriction();
		$this->assertTrue( true, 'manage_paywall_restriction() did not fatal without WCM.' );
	}

	/**
	 * A logged-in reader with a valid unlock cookie has the Access Control
	 * restriction lifted for that post.
	 */
	public function test_unlock_cookie_allows_post_under_access_control() {
		wp_set_current_user( $this->reader_id );
		$this->set_unlock_cookie( $this->post_id, $this->reader_id );
		$this->assertFalse(
			apply_filters( 'newspack_content_gate_restrict_post', true, $this->post_id ),
			'A valid unlock cookie must lift the restriction.'
		);
	}

	/**
	 * Without an unlock cookie the post stays restricted.
	 */
	public function test_no_cookie_stays_restricted() {
		wp_set_current_user( $this->reader_id );
		$this->assertTrue(
			apply_filters( 'newspack_content_gate_restrict_post', true, $this->post_id ),
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
			apply_filters( 'newspack_content_gate_restrict_post', true, $this->post_id ),
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
		wp_set_current_user( $this->reader_id );
		$this->set_unlock_cookie( $this->post_id, $this->reader_id + 1 );
		$this->assertTrue(
			apply_filters( 'newspack_content_gate_restrict_post', true, $this->post_id ),
			'An unlock minted for another user must not unlock the post.'
		);
	}

	/**
	 * Anonymous visitors never get an unlock, even if a cookie is present.
	 */
	public function test_anonymous_with_cookie_stays_restricted() {
		wp_set_current_user( 0 );
		$this->set_unlock_cookie( $this->post_id, 0 );
		$this->assertTrue(
			apply_filters( 'newspack_content_gate_restrict_post', true, $this->post_id ),
			'Anonymous visitors must stay restricted.'
		);
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $this->post_id, 0 ) ] );
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
	 * The LD+JSON schema is emitted under Access Control, with
	 * isAccessibleForFree reflecting the post's gating.
	 */
	public function test_ld_json_under_access_control() {
		$this->go_to( get_permalink( $this->post_id ) );

		// Simulate a post covered by a content gate.
		add_filter( 'newspack_post_has_restrictions', '__return_true' );
		ob_start();
		Google_ExtendedAccess::add_extended_access_ld_json();
		$output = ob_get_clean();
		remove_filter( 'newspack_post_has_restrictions', '__return_true' );

		$this->assertStringContainsString( 'newspack-extended-access-schema', $output, 'The LD+JSON schema must be emitted under Access Control.' );
		$this->assertStringContainsString( '"isAccessibleForFree":false', $output, 'A gated post must be marked not accessible for free.' );
	}

	/**
	 * The LD+JSON schema marks ungated posts as accessible for free.
	 */
	public function test_ld_json_ungated_post_is_free() {
		$this->go_to( get_permalink( $this->post_id ) );

		ob_start();
		Google_ExtendedAccess::add_extended_access_ld_json();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'newspack-extended-access-schema', $output, 'The LD+JSON schema must be emitted under Access Control.' );
		$this->assertStringContainsString( '"isAccessibleForFree":true', $output, 'An ungated post must be marked accessible for free.' );
	}
}
