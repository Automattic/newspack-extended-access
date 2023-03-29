<?php
/**
 * Adjustments and special handling related to WooCommerce.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

/**
 * Adjustments and special handling related to Yoads SEO
 * by implementing required hooks for `isAccessibleForFree`
 * schema data to the JSON LD data on articles and any other
 * necessary schema data that is not already present
 * (according to implementation guide).
 */
class YoastSEO {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		/**
		 * Update Yoast SEO JSON+LD schema for Article.
		 */
		add_filter( 'wpseo_schema_article', [ __CLASS__, 'update_wpseo_schema_article' ] );
	}

	/**
	 * Update necessary schema of Article Schema data.
	 *
	 * @param array $data Schema.org Article data array.
	 * @return array Schema.org Article data array.
	 */
	public static function update_wpseo_schema_article( $data ) {
		// 'wc_memberships_is_post_content_restricted()' function will only available
		// if WooCommerce Membership plugin is installed and active.
		if ( function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
			// Add 'isAccessibleForFree' schema for compatibility with Google Extended Access.
			$data['isAccessibleForFree'] = wc_memberships_is_post_content_restricted();
		}

		return $data;
	}

}
