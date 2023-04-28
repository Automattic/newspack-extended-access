<?php
/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

// Check if needed functions exists - if not, require them.
if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * Provide functionality to check the plugin's dependencies on other plugin.
 *
 * @since 1.0
 */
class DependencyChecker {

	const WOOCOMMERCE_PLUGIN             = 'woocommerce/woocommerce.php';
	const WOOCOMMERCE_MEMBERSHIPS_PLUGIN = 'woocommerce-memberships/woocommerce-memberships.php';

	/**
	 * Check if plugin is installed by getting all plugins from the plugins dir.
	 *
	 * @param  string $plugin_slug Slug of the plugin to check for.
	 * @return bool
	 */
	protected static function check_plugin_installed( $plugin_slug ): bool {
		$installed_plugins = get_plugins();

		return array_key_exists( $plugin_slug, $installed_plugins ) || in_array( $plugin_slug, $installed_plugins, true );
	}

	/**
	 * Check if plugin is installed.
	 *
	 * @param  string $plugin_slug Slug of the plugin to check for.
	 * @return bool
	 */
	protected static function check_plugin_active( $plugin_slug ): bool {
		if ( is_plugin_active( $plugin_slug ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether 'WooCommerce Memberships' plugin is installed.
	 *
	 * @return bool Return true if plugin installed.
	 */
	public static function is_wc_installed(): bool {
		if ( self::check_plugin_installed( self::WOOCOMMERCE_PLUGIN ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether 'WooCommerce Memberships' plugin is active.
	 *
	 * @return bool Return true if plugin active.
	 */
	public static function is_wc_active(): bool {
		if ( self::check_plugin_active( self::WOOCOMMERCE_PLUGIN ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether 'WooCommerce Memberships' plugin is installed.
	 *
	 * @return bool Return true if plugin installed.
	 */
	public static function is_wc_memberships_installed(): bool {
		if ( self::check_plugin_installed( self::WOOCOMMERCE_MEMBERSHIPS_PLUGIN ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether 'WooCommerce Memberships' plugin is active.
	 *
	 * @return bool Return true if plugin active.
	 */
	public static function is_wc_memberships_active(): bool {
		if ( self::check_plugin_active( self::WOOCOMMERCE_MEMBERSHIPS_PLUGIN ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether Google Client API ID is valid or not.
	 *
	 * @return bool Return true if valid Google Client API ID is present.
	 */
	public static function is_valid_google_client_api_id(): bool {
		if ( filter_var( get_option( 'newspack_extended_access__google_client_api_id', '' ), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			return true;
		}
		return false;
	}
}
