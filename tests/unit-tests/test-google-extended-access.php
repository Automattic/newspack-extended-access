<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';
require_once dirname( __FILE__ ) . '/utils/wc-memberships-stubs.php';

/**
 * Tests the scripts that should be registered and enqueued for Google Extended Access.
 */
class Newspack_Test_Google_ExtendedAccess extends WP_UnitTestCase {

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		parent::set_up();

		// Stub wc_get_page_permalink if WooCommerce is not loaded.
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			function wc_get_page_permalink( $page ) {
				return home_url( '/' . $page );
			}
		}

		newspack_ea_reset_wc_memberships_stubs();

		// Reset the SwG script enqueue state so each test starts clean.
		// WP_UnitTestCase does not reset $wp_scripts between tests, which would
		// otherwise let a prior enqueue leak into negative-path assertions.
		wp_dequeue_script( 'newspack-swg' );
		wp_deregister_script( 'newspack-swg' );

		// Initialize .
		\Newspack\ExtendedAccess\Google_ExtendedAccess::init();

		// Sample Post(s).
		$this->post = get_post( $this->factory->post->create() );
	}

	/**
	 * Helper: navigate to a URL with the Extended Access query param set, so
	 * `is_singular('post')` and the GAA query-var check both fire.
	 *
	 * @param string $url Target URL.
	 */
	private function go_to_with_gaa( $url ) {
		$separator = strpos( $url, '?' ) === false ? '?' : '&';
		$this->go_to( $url . $separator . \Newspack\ExtendedAccess\Google_ExtendedAccess::GOOGLE_EA_REQUEST_PARAM . '=' . time() );
	}

	/**
	 * Scripts must enqueue on single post pages reached via a Showcase link
	 * (i.e. when the GAA query parameter is present).
	 */
	public function test_should_register_script() {
		// Disables outputs performed using echo by the class/method being tested. Removing this print output performed by function 'google-account-gsi-client'.
		$this->setOutputCallback( function() {} );

		$this->go_to_with_gaa( get_permalink( $this->post ) );

		do_action( 'wp_head' );
		$this->assertTrue( wp_script_is( 'newspack-swg', 'registered' ) );
		$this->assertTrue( wp_script_is( 'newspack-swg', 'enqueued' ) );

        // phpcs:disable
		// Following scripts are directly printed using 'wp_print_script_tag' so they cannot be checked.
		// $this->assertTrue( wp_script_is( 'google-account-gsi-client', 'registered' ) );
		// $this->assertTrue( wp_script_is( 'google-account-gsi-client', 'enqueued' ) );
		// $this->assertTrue( wp_script_is( 'google-news-swg-gaa', 'registered' ) );
		// $this->assertTrue( wp_script_is( 'google-news-swg-gaa', 'enqueued' ) );
        // phpcs:enable
	}

	/**
	 * Captures whatever the LD+JSON hook emits for the current view.
	 *
	 * @return string
	 */
	private function capture_ld_json() {
		ob_start();
		\Newspack\ExtendedAccess\Google_ExtendedAccess::add_extended_access_ld_json();
		return ob_get_clean();
	}

	/**
	 * Both directions of the single-post scoping, in one contrast: the schema
	 * that declares a gated article to Google appears on a post and not on a
	 * page. Asserting only the negative would stay green if the schema stopped
	 * being emitted anywhere at all.
	 */
	public function test_ld_json_is_emitted_on_posts_only() {
		$GLOBALS['newspack_ea_test_post_content_restricted'] = true;

		$this->go_to( get_permalink( $this->post ) );
		$post_output = $this->capture_ld_json();

		$page_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );
		$page_output = $this->capture_ld_json();

		$this->assertStringContainsString( 'newspack-extended-access-schema', $post_output, 'LD+JSON should be emitted on single posts.' );
		$this->assertStringContainsString( '"isAccessibleForFree":false', $post_output, 'A gated post must be declared as not free.' );
		$this->assertStringNotContainsString( 'newspack-extended-access-schema', $page_output, 'LD+JSON should not be emitted on pages.' );
	}

	/**
	 * Scoping to posts leaves publishers who gate a page or a custom post type
	 * with no Extended Access on that content and nothing in the admin to say
	 * so, so the scope is filterable.
	 */
	public function test_ld_json_scope_can_be_widened_by_filter() {
		$GLOBALS['newspack_ea_test_post_content_restricted'] = true;
		add_filter( 'newspack_extended_access_can_insert_frontend_markup', '__return_true' );

		$page_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );
		$output = $this->capture_ld_json();

		remove_filter( 'newspack_extended_access_can_insert_frontend_markup', '__return_true' );

		$this->assertStringContainsString( 'newspack-extended-access-schema', $output, 'The filter must be able to restore the schema on a gated page.' );
	}

	/**
	 * Scripts must NOT enqueue on non-singular-post views, even when the GAA
	 * query parameter is present, so the SwG library only loads on article
	 * pages.
	 */
	public function test_scripts_not_enqueued_on_non_post_views() {
		$page_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		$this->go_to_with_gaa( get_permalink( $page_id ) );

		do_action( 'wp_head' );
		$this->assertFalse( wp_script_is( 'newspack-swg', 'enqueued' ), 'newspack-swg must not load on pages.' );
	}

	/**
	 * Opening an article through an Extended Access entry point is recorded
	 * against the reader. This is the whole of Use Case 4's server-side trace:
	 * readers who sign in rather than registering through Google never reach
	 * the registration endpoint, and the unlock endpoint has nothing else to
	 * recognise them by.
	 */
	public function test_extended_access_entry_is_recorded_for_logged_in_readers() {
		$this->setOutputCallback( function() {} );
		$reader = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $reader );
		update_option( \Newspack\ExtendedAccess\Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION, 'test.apps.googleusercontent.com' );

		$this->go_to_with_gaa( get_permalink( $this->post ) );
		do_action( 'wp_head' );

		$this->assertNotEmpty(
			get_user_meta( $reader, \Newspack\ExtendedAccess\REST_Controller::EXTENDED_ACCESS_ENTRY_META, true ),
			'A reader arriving through an Extended Access link should be recorded as being in the flow.'
		);
	}

	/**
	 * The marker is only written when the reader actually arrives through
	 * Extended Access, so ordinary article views do not quietly make every
	 * reader eligible to unlock.
	 */
	public function test_extended_access_entry_is_not_recorded_on_ordinary_views() {
		$this->setOutputCallback( function() {} );
		$reader = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $reader );

		$this->go_to( get_permalink( $this->post ) );
		do_action( 'wp_head' );

		$this->assertEmpty(
			get_user_meta( $reader, \Newspack\ExtendedAccess\REST_Controller::EXTENDED_ACCESS_ENTRY_META, true ),
			'An article view without the Extended Access parameter is not an entry.'
		);
	}
}
