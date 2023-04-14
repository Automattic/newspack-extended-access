<?php
/**
 * Plugin Name:     Newspack Extended Access Integration
 * Plugin URI:      https://newspack.pub
 * Description:     Google Extended Access integration for Newspack sites.
 * Author:          Automattic
 * Text Domain:     newspack-extended-access
 * Domain Path:     /languages
 * Version:         0.22
 *
 * @package newspack-extended-access
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NEWSPACK_EXTENDED_ACCESS_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_EXTENDED_ACCESS_PLUGIN_FILE', plugin_dir_path( __FILE__ ) );
}

require_once 'vendor/autoload.php';

Newspack_Extended_Access\Initializer::init();
