<?php
/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

use Newspack;

/**
 * Registers filters required to integrate the plugin's option tab into WooCommerce 'Settings -> Memberships' page.
 */
class WC_Settings_Memberships_Option_Tab {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		// Adds a new option tab to WooCommerce 'Settings -> Memberships' page.
		add_filter( 'woocommerce_get_sections_memberships', array( __CLASS__, 'woocommerce_get_sections_memberships__add_option_tab' ) );
		add_filter( 'woocommerce_get_settings_memberships', array( __CLASS__, 'woocommerce_get_settings_memberships__add_option_tab' ), 10, 2 );
	}

	/**
	 * Add tab for this plugin's options page into WooCommerce 'Settings -> Memberships'.
	 *
	 * @param array $sections Array of the plugin sections.
	 * @return array Returns updated sections.
	 */
	public static function woocommerce_get_sections_memberships__add_option_tab( $sections ) {
		// Add 'Newspack Extended Access' to existing sections.
		$sections['newspack-extended-access'] = __( 'Newspack Extended Access', 'newspack-extended-access' );

		return $sections;
	}

	/**
	 * Add this plugin's options page into WooCommerce 'Settings -> Memberships'.
	 *
	 * @param array  $settings Array of the plugin settings.
	 * @param string $current_section the current section being output.
	 * @return array Returns updated options.
	 */
	public static function woocommerce_get_settings_memberships__add_option_tab( $settings, $current_section ) {
		// Add this plugin's option only for 'newspack-extended-access' key.
		if ( 'newspack-extended-access' === $current_section ) {

			// Prepare server protocol and domain name.
			$home_url_parts = wp_parse_url( home_url() );
			
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Already validated.
			$allowed_referrers = $home_url_parts['scheme'] . '://' . $home_url_parts['host'];

			// Prepare title and input-box description.
			$title_desc = '<p>An integration for utilizing Google Extended Access.</p>';
			$input_desc = '<p>Refer <a href="https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid" target="_blank">Google Developer Documents</a> to setup and configure your Google Client API ID.</p><p>Make sure to add your domain <b><u>' . $allowed_referrers . '</u></b> to Authorized JavaScript origins.';

			// Override existing settings with out 'Newspack Extended Access' tab.
			$settings = array(

				array(
					'name' => __( 'Newspack Extended Access', 'newspack-extended-access' ),
					'type' => 'title',
					'desc' => $title_desc,
				),

				array(
					'type'    => 'textarea',
					'id'      => 'newspack_extended_access__google_client_api_id',
					'name'    => __( 'Google Client API ID', 'newspack-extended-access' ),
					'desc'    => $input_desc,
					'default' => '',
				),

				array(
					'type' => 'sectionend',
				),

			);

		}

		return $settings;
	}
}
