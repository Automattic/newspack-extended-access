<?php
/**
 * Newspack Extended Access plugin initialization.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

/**
 * Class to handle the plugin initialization
 */
class Initializer {

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		WooCommerce::init();
		YoastSEO::init();
	}

}
