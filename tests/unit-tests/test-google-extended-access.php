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

		// Initialize .
		\Newspack\ExtendedAccess\Google_ExtendedAccess::init();

		// Sample Post(s).
		$this->post      = get_post( $this->factory->post->create() );
		$GLOBALS['post'] = $this->post;
	}

	/**
	 * Test that the routes are all registered.
	 */
	public function test_should_register_script() {
		// Disables outputs performed using echo by the class/method being tested. Removing this print output performed by function 'google-account-gsi-client'.
		$this->setOutputCallback( function() {} );

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

}
