<?php
/**
 * Tests the standalone wp-admin settings page.
 *
 * @package Newspack\Tests
 */

use Newspack\ExtendedAccess\Admin_Settings;

/**
 * Tests the Admin_Settings surface: menu registration, option sanitization,
 * and field rendering. This surface must work without WooCommerce
 * Memberships, so no WCM stack is loaded here.
 */
class Newspack_Test_Admin_Settings extends WP_UnitTestCase {

	/**
	 * Setup for the tests.
	 */
	public function set_up() {
		parent::set_up();
		// The settings-error API lives in an admin-only include.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		// Settings errors accumulate in a global that persists across tests.
		$GLOBALS['wp_settings_errors'] = array();
		// So does the admin menu, so a page registered by one test would still
		// be found by the next one and mask a failed registration.
		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();
		delete_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION );
	}

	/**
	 * The settings page is registered under the Settings menu for admins.
	 */
	public function test_settings_page_registered_for_admins() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		Admin_Settings::register_settings_page();

		$settings_page_url = menu_page_url( Admin_Settings::PAGE_SLUG, false );
		$this->assertStringContainsString(
			'options-general.php?page=' . Admin_Settings::PAGE_SLUG,
			$settings_page_url,
			'The settings page must be registered as a Settings submenu page.'
		);
		$this->assertSame(
			Admin_Settings::get_settings_url(),
			$settings_page_url,
			'The URL the admin notice links to must be the registered page URL.'
		);
	}

	/**
	 * The page holds a credential for an authentication flow, so the capability
	 * bar is part of its contract rather than an implementation detail. Locking
	 * it down here means a later refactor that widens the capability, or drops
	 * the guard in the render callback as "redundant with add_options_page",
	 * cannot keep the suite green.
	 */
	public function test_settings_page_not_registered_for_non_admins() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		Admin_Settings::register_settings_page();

		$this->assertEmpty(
			menu_page_url( Admin_Settings::PAGE_SLUG, false ),
			'A reader-level user must not get the Extended Access settings page in their menu.'
		);
	}

	/**
	 * Rendering is guarded independently of the menu registration, so reaching
	 * the page URL directly is refused rather than served.
	 */
	public function test_render_settings_page_refuses_non_admins() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );

		Admin_Settings::render_settings_page();
	}

	/**
	 * Saving the option runs the sanitize callback registered via
	 * register_setting: markup is stripped and whitespace trimmed.
	 */
	public function test_option_save_is_sanitized() {
		Admin_Settings::register_settings();

		update_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION, "  <b>1234-abc.apps.googleusercontent.com</b>\n" );

		$this->assertSame(
			'1234-abc.apps.googleusercontent.com',
			get_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION ),
			'The stored client ID must be stripped of markup and surrounding whitespace.'
		);
	}

	/**
	 * An invalid-looking client ID is still saved (matching the WooCommerce
	 * surface) but surfaces a settings-error warning.
	 */
	public function test_invalid_client_id_saves_with_warning() {
		$sanitized_value = Admin_Settings::sanitize_google_client_api_id( 'not a hostname' );

		$this->assertSame( 'not a hostname', $sanitized_value, 'An invalid value must still be saved.' );

		$settings_errors = get_settings_errors( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION );
		$this->assertCount( 1, $settings_errors, 'An invalid value must surface exactly one settings error.' );
		$this->assertSame( 'warning', $settings_errors[0]['type'], 'The settings error must be a warning, not a hard failure.' );
	}

	/**
	 * A first-ever save of an invalid value warns exactly once, although the
	 * sanitize callback runs twice (update_option on a missing option row
	 * falls through to add_option, which sanitizes again).
	 */
	public function test_first_ever_save_of_invalid_value_warns_once() {
		Admin_Settings::register_settings();

		update_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION, 'not a hostname' );

		$this->assertSame(
			'not a hostname',
			get_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION ),
			'The invalid value must still be saved on first-ever save.'
		);
		$this->assertCount(
			1,
			get_settings_errors( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION ),
			'The invalid-value warning must be queued exactly once even when the sanitizer runs twice.'
		);
	}

	/**
	 * A valid client ID produces no settings error.
	 */
	public function test_valid_client_id_saves_without_warning() {
		$sanitized_value = Admin_Settings::sanitize_google_client_api_id( '1234-abc.apps.googleusercontent.com' );

		$this->assertSame( '1234-abc.apps.googleusercontent.com', $sanitized_value );
		$this->assertCount(
			0,
			get_settings_errors( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION ),
			'A valid value must not surface a settings error.'
		);
	}

	/**
	 * The field renders the stored value in an input named after the option.
	 */
	public function test_field_renders_option_value() {
		update_option( Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION, '1234-abc.apps.googleusercontent.com' );

		ob_start();
		Admin_Settings::render_google_client_api_id_field();
		$field_html = ob_get_clean();

		$this->assertStringContainsString(
			'name="' . Admin_Settings::GOOGLE_CLIENT_API_ID_OPTION . '"',
			$field_html,
			'The input must post back to the option name.'
		);
		$this->assertStringContainsString(
			'value="1234-abc.apps.googleusercontent.com"',
			$field_html,
			'The input must be pre-filled with the stored client ID.'
		);
	}
}
