<?php
/**
 * Newspack Extended Access plugin initialization.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

/**
 * Class to handle the plugin initialization
 */
class Initializer {

	/**
	 * Stores notice description, if any.
	 *
	 * @var string
	 */
	public static $plugin_notice = '';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		// Setup Hooks & Filters.
		add_action( 'admin_notices', array( __CLASS__, 'show_admin_notice__error' ) );

		// Initialize non-dependency classes.
		WooCommerce::init();
		WC_Settings_Memberships_Option_Tab::init();
		Admin_Settings::init();

		// Defer the dependency-gated initialization until all plugins are
		// loaded: the Newspack Access Control classes belong to the Newspack
		// plugin, which may load after this plugin.
		add_action( 'plugins_loaded', array( __CLASS__, 'init_dependent_classes' ) );
	}

	/**
	 * Initialize classes only when all dependencies are met.
	 */
	public static function init_dependent_classes() {
		if ( self::has_valid_dependencies() ) {
			REST_Controller::init();
			Google_ExtendedAccess::init();
			SinglePost_Subscription::init();
		}
	}

	/**
	 * Check and displays plugin specific notices when required.
	 *
	 * Extended Access requires a configured Google Client API ID and a content
	 * gating system: either the WooCommerce Memberships stack or the
	 * first-party Newspack Access Control.
	 *
	 * @return bool Return false on error.
	 */
	public static function has_valid_dependencies() {
		if ( ! DependencyChecker::is_valid_google_client_api_id() ) {
			return false;
		}
		return DependencyChecker::is_wc_memberships_stack_active() || DependencyChecker::is_newspack_access_control_active();
	}

	/**
	 * Displays admin notice summarizing error.
	 */
	public static function show_admin_notice__error() {
		// The settings page is where the missing configuration gets fixed; a
		// notice pointing back at it would be noise there.
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $current_screen && 'settings_page_' . Admin_Settings::PAGE_SLUG === $current_screen->id ) {
			return;
		}

		$plugin_notice = '';
		$allowed_html  = array(
			'a'    => array(
				'href' => array(),
			),
			'b'    => array(),
			'code' => array(),
		);

		if ( ! DependencyChecker::is_wc_memberships_stack_active() && ! DependencyChecker::is_newspack_access_control_active() ) {
			$plugin_notice = '<b>Newspack Extended Access</b> plugin requires a content gating system: either <b>Newspack Access Control</b> (content gates) or <b>WooCommerce</b> with <b>WooCommerce Memberships</b>. Open <a href="' . esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ) . '">Plugins Page</a>.';
		} elseif ( ! DependencyChecker::is_valid_google_client_api_id() ) {
			if ( DependencyChecker::is_wc_memberships_stack_active() ) {
				$plugin_notice = '<b>Newspack Extended Access</b> plugin requires <b>Google Client API ID</b> to be configured. Please check your <b>Google Client API ID</b> into <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=memberships&section=newspack-extended-access' ) ) . '">Newspack Extended Access Settings</a>.';
			} else {
				$plugin_notice = sprintf(
					/* translators: %s: URL of the Extended Access settings page. */
					__( '<b>Newspack Extended Access</b> plugin requires <b>Google Client API ID</b> to be configured. Please add your <b>Google Client API ID</b> in <a href="%s">Extended Access Settings</a>.', 'newspack-extended-access' ),
					esc_url( Admin_Settings::get_settings_url() )
				);
			}
		}

		if ( ! empty( $plugin_notice ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					echo wp_kses( $plugin_notice, $allowed_html );
					?>
				</p>
			</div>
			<?php
		}
	}
}
