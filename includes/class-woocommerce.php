<?php
/**
 * Adjustments and special handling related to WooCommerce.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

/**
 * Adjustments and special handling related to WooCommerce.
 */
class WooCommerce {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		/**
		 * Allow paywalled articles within feeds, otherwise Google News won't ingest those articles.
		 */
		add_filter( 'wc_memberships_is_feed_restricted', '__return_false' );

		/**
		 * Don't display the restricted messages in general but mostly in feeds.
		 */
		add_filter( 'wc_memberships_display_content_category_restricted_messages', '__return_false' );
	}

}
