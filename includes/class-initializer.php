<?php
/**
 * Newspack Extended Access plugin initialization.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

use Newspack;

/**
 * Class to handle the plugin initialization
 */
class Initializer {

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		// TODO (@AnuragVasanwala): Please remove try...catch. It is only enabled for testing purpose.
		try {
			WooCommerce::init();
			ExtendedAccess_REST_Endpoint::init();
			Google_ExtendedAccess::init();
			Single_Post_Subscription::init();
			Option_Page::init();
		} catch ( \Error $er ) {
			echo esc_html( $er );
		}
	}

}
