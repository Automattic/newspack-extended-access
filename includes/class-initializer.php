<?php
/**
 * Newspack Extended Access plugin initialization.
 *
 * @package Newspack\Extended_Access
 */

namespace Newspack\Extended_Access;

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
		// TODO (@AnuragVasanwala): Please remove try...catch. It is only enabled for testing purpose.
		try {
			add_action( 'admin_notices', [ __CLASS__, 'show_admin_notice__error' ] );

			/** Initialize non-dependency classes. */
			WooCommerce::init();
			WC_Settings_Memberships_Option_Tab::init();

			/** Initialize classes only when all dependencies are met. */
			if ( self::has_valid_dependencies() ) {
				REST_Endpoint::init();
				Google_ExtendedAccess::init();
				Single_Post_Subscription::init();
			}
		} catch ( Error $er ) {
			echo esc_html( $er );
		}
	}
		
	/**
	 * Check and displays plugin specific notices when required.
	 *
	 * @return bool Return false on error.
	 */
	public static function has_valid_dependencies() {
		if ( ! DependencyChecker::is_wc_memberships_installed() || ! DependencyChecker::is_wc_memberships_active() || ! DependencyChecker::is_valid_google_client_api_id() ) {
			return false;
		}
		return true;
	}
	
	/**
	 * Displays admin notice summarizing error.
	 */
	public static function show_admin_notice__error() {
		$plugin_notice = '';  

		if ( ! DependencyChecker::is_wc_memberships_installed() ) {
			$plugin_notice = '<b>Newspack Extended Access</b> plugin requires <b>WooCommerce Memberships</b> to be installed, active and configured.';
		} elseif ( ! DependencyChecker::is_wc_memberships_active() ) {
			$plugin_notice = '<b>Newspack Extended Access</b> plugin requires <b>WooCommerce Memberships</b> to be active. Open <a href="' . esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ) . '">Plugins Page</a>.';
		} elseif ( ! DependencyChecker::is_valid_google_client_api_id() ) {
			$plugin_notice = '<b>Newspack Extended Access</b> plugin requires <b>Google Client API ID</b> to be configured. Please check your <b>Google Client API ID</b> into <a href="' . admin_url( 'admin.php?page=wc-settings&tab=memberships&section=newspack-extended-access' ) . '">Newspack Extended Access Settings</a>.';
		}

		if ( ! empty( $plugin_notice ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php 
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $plugin_notice;
					?>
				</p>
			</div>
			<?php
		}       
	}
}
