<?php
/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

use Newspack;

define( 'NEWSPACK_SWG_SCRIPT_VERSION', '1.0.1' );

/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 */
class Google_ExtendedAccess {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'add_extended_access_ld_json' ), -1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
	}

	/**
	 * Check conditions for frontend markup insertion.
	 */
	private static function can_insert_frontend_markup() {
		return ! is_front_page() && ! is_404();
	}

	/**
	 * Embeds required LD+JSON schema for Google Extended Access.
	 */
	public static function add_extended_access_ld_json() {
		if ( ! self::can_insert_frontend_markup() ) {
			return;
		}
		// 'wc_memberships_is_post_content_restricted()' function will only available if WooCommerce Membership plugin is installed and active.
		if ( function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
			// Add 'isAccessibleForFree' schema for compatibility with Google Extended Access.
			$flags = ( JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			$url_parts = wp_parse_url( home_url() );
			$domain    = str_replace( 'www.', '', $url_parts['host'] );

			$ld_json = array(
				'@context'            => 'https://schema.org',
				'@type'               => 'Article',
				'isAccessibleForFree' => ! wc_memberships_is_post_content_restricted(),
				'isPartOf'            => array(
					'@type'     => array( 'CreativeWork', 'Product' ),
					'name'      => get_bloginfo( 'name' ),
					'productID' => $domain . ':showcase',
				),
				'publisher'           => array(
					'@type' => 'Organization',
					'name'  => get_bloginfo( 'name' ),
				),
			);

			$ld_json = wp_json_encode( $ld_json, $flags );
			$ld_json = str_replace( "\n", PHP_EOL . "\t", $ld_json );
			?>
			<script type="application/ld+json" class="newspack-extended-access-schema">
				<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $ld_json;
				?>
			</script>
			<?php
		}
	}

	/**
	 * Enqueues scripts for Google Extended Access and Newspack SWG script.
	 */
	public static function enqueue_script() {
		if ( ! self::can_insert_frontend_markup() ) {
			return;

		}
		// Add scripts only for `post` type.
		if ( get_post_type() === 'post' ) { // Add slug in condition.
			// Newspack Extended Access Script.
			$assets_path = plugins_url( '../assets/', __FILE__ );
			wp_register_script( 'newspack-swg', $assets_path . 'js/newspack-swg.js', array(), NEWSPACK_SWG_SCRIPT_VERSION, array( 'strategy' => 'async' ) );
			wp_enqueue_script( 'newspack-swg' );

			$home_url_parts    = wp_parse_url( home_url() );
			$allowed_referrers = array( $home_url_parts['host'] );

			// Nonce for REST API.
			wp_localize_script(
				'newspack-swg',
				'authenticationSettings',
				array(
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'allowedReferrers'  => $allowed_referrers,
					'postID'            => get_the_ID(),
					'googleClientApiID' => get_option( 'newspack_extended_access__google_client_api_id', '' ),
					'myAccountURL'      => wc_get_page_permalink( 'myaccount' )
				)
			);

			// Google Extended Access Scripts.
			wp_print_script_tag(
				array(
					'id'    => 'google-account-gsi-client',
					'async' => true,
					'src'   => esc_url( 'https://accounts.google.com/gsi/client' ),
					'defer' => true,
				)
			);

			wp_print_script_tag(
				array(
					'id'                    => 'google-news-swg',
					'async'                 => true,
					'subscriptions-control' => 'manual',
					'src'                   => esc_url( 'https://news.google.com/swg/js/v1/swg.js' ),
				)
			);

			wp_print_script_tag(
				array(
					'id'     => 'google-news-swg-gaa',
					'defer'  => true,
					'src'    => esc_url( 'https://news.google.com/swg/js/v1/swg-gaa.js' ),
					'onload' => 'initGaaMetering()',
				)
			);
		}
	}
}
