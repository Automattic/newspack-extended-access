<?php
/**
 * Plugin Name:     Newspack Extended Access Integration
 * Plugin URI:      https://newspack.pub
 * Description:     Google Extended Access integration for Newspack sites.
 * Author:          Automattic
 * Text Domain:     newspack-extended-access
 * Domain Path:     /languages
 * Version:         1.60.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NEWSPACK_EXTENDED_ACCESS_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_EXTENDED_ACCESS_PLUGIN_FILE', plugin_dir_path( __FILE__ ) );
}

/*
 TODO:
 - Add linter
 - Set up boilerplate/class
 - Enqueue stub Extended Access JS
*/

// Allow member posts in RSS so that Google News can still ingest them.
add_filter( 'wc_memberships_is_feed_restricted', '__return_false' );
