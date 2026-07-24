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
	 * Google Extended Access URL parameter.
	 *
	 * @var string
	 */
	const GOOGLE_EA_REQUEST_PARAM = 'gaa_ts';

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'add_extended_access_ld_json' ), -1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
		add_action( 'query_vars', array( __CLASS__, 'add_extended_access_query_vars' ) );
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

		// Whether the post is covered by content gating rules, from whichever
		// gating system is active. Stays null when no gating system can answer,
		// in which case no schema is emitted at all: a wrong answer here tells
		// Google that gated content is free.
		$is_post_restricted = null;
		if ( DependencyChecker::is_wc_memberships_loaded() ) {
			$is_post_restricted = wc_memberships_is_post_content_restricted();
		} elseif ( DependencyChecker::is_newspack_restriction_state_available() ) {
			$is_post_restricted = \Newspack\Content_Gate::post_has_restrictions( get_the_ID() );
		}

		if ( null !== $is_post_restricted ) {
			self::render_extended_access_ld_json( (bool) $is_post_restricted );
		}
	}

	/**
	 * Renders the LD+JSON schema for a known restriction state.
	 *
	 * Separate from the branching above so the schema's shape is exercised
	 * independently of which gating system supplied the state.
	 *
	 * @param bool $is_post_restricted Whether the post is covered by gating rules.
	 */
	public static function render_extended_access_ld_json( bool $is_post_restricted ) {
		// Add 'isAccessibleForFree' schema for compatibility with Google Extended Access.
		$flags = ( JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$url_parts = wp_parse_url( home_url() );
		$domain    = str_replace( 'www.', '', $url_parts['host'] );

		$ld_json = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Article',
			'isAccessibleForFree' => ! $is_post_restricted,
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

	/**
	 * Add query vars for Google Extended Access.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_extended_access_query_vars( $vars ) {
		$vars[] = self::GOOGLE_EA_REQUEST_PARAM;
		return $vars;
	}

	/**
	 * Enqueues scripts for Google Extended Access and Newspack SWG script.
	 */
	public static function enqueue_script() {
		if ( ! self::can_insert_frontend_markup() ) {
			return;
		}

		// Only enqueue scripts when Extended Access is happening.
		if ( empty( get_query_var( self::GOOGLE_EA_REQUEST_PARAM ) ) ) {
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

			/*
			 * The page the SwG script sends existing readers to for logging in:
			 * WooCommerce's My Account when available, otherwise the WP login
			 * URL. Both are handed over bare - the script appends the return
			 * destination itself, from the live URL rather than the permalink,
			 * so the Extended Access query args needed to resume the flow after
			 * login survive the round trip.
			 */
			$my_account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();

			// Nonce for REST API.
			wp_localize_script(
				'newspack-swg',
				'authenticationSettings',
				array(
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'allowedReferrers'  => $allowed_referrers,
					'postID'            => get_the_ID(),
					'googleClientApiID' => get_option( 'newspack_extended_access__google_client_api_id', '' ),
					'myAccountURL'      => $my_account_url,
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
