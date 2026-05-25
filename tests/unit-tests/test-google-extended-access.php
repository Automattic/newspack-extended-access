<?php
/**
 * Tests the Settings.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess;

require_once dirname( __FILE__ ) . '/utils/class-plugin-manager.php';

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
	 * LD+JSON schema must NOT appear on non-singular-post views such as the
	 * front page, archives, or pages — `@type: Article` is incorrect for those
	 * and would confuse Google's Extended Access detection.
	 */
	public function test_ld_json_not_emitted_on_non_post_views() {
		$page_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );

		ob_start();
		\Newspack\ExtendedAccess\Google_ExtendedAccess::add_extended_access_ld_json();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'newspack-extended-access-schema', $output, 'LD+JSON should not be emitted on pages.' );
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
}
