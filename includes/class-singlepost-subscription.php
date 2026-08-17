<?php
/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

/**
 * Defines functionality to bypass paywall restriction for single post for single user.
 */
class SinglePost_Subscription {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		/*
		 * Hook function before Restriction class function hook,
		 * it removes actions added by woocommerce membership to
		 * restrict access to post content.
		 */
		add_action( 'wp', [ __CLASS__, 'manage_paywall_restriction' ], 5 ); // Before Woo Memberships' restriction handler, which was lowered to 9 in 1.27.2.

		/*
		 * Newspack Access Control (content gates) integration. Both callbacks
		 * stand down while Woo Memberships is loaded, keeping the legacy path
		 * untouched.
		 *
		 * The restriction predicate itself is filtered - rather than any single
		 * rendering surface - because every Access Control surface keys off it:
		 * the inline gate, the overlay gate, Campaigns prompt suppression, and
		 * the article_view activity suppression. Priority 20 runs after
		 * Content_Restriction_Control (10) has computed the gate outcome.
		 *
		 * Newspack's Newsletters_Access shares priority 20. The order between
		 * them does not matter because both only ever relax the predicate
		 * (true to false) and never tighten it. A future callback at 20 that
		 * tightens would make the outcome order-dependent, so anything added
		 * here must preserve that relax-only invariant.
		 */
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'maybe_unrestrict_unlocked_post' ], 20, 2 );

		/*
		 * Access Control truncates gated posts in feeds whenever its
		 * restrict_feeds setting is on, and that setting defaults to on. Google
		 * News has to ingest the full article for Extended Access to ever be
		 * offered on it, so feeds are exempted here for the same reason the Woo
		 * Memberships path disables wc_memberships_is_feed_restricted in
		 * WooCommerce::init().
		 */
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'unrestrict_feed_content' ], 20, 2 );

		/*
		 * The metering short-circuit is still needed on top of the predicate:
		 * surfaces such as the metering countdown call Metering::is_metering()
		 * before checking the predicate, and that call records the view against
		 * the reader's meter as a side effect.
		 */
		add_filter( 'newspack_content_gate_metering_short_circuit', [ __CLASS__, 'maybe_short_circuit_metering' ] );
	}

	/**
	 * Whether the current reader holds a valid unlock for the post.
	 *
	 * Only logged-in users can hold an unlock: the cookie name is keyed on
	 * the post and user IDs (see REST_Controller::get_unlock_cookie_name()),
	 * so a cookie minted for another post or user never matches.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	public static function has_valid_unlock( $post_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $post_id ) {
			return false;
		}
		return isset( $_COOKIE[ REST_Controller::get_unlock_cookie_name( $post_id, $user_id ) ] );
	}

	/**
	 * A post the reader has unlocked via Google Extended Access is not
	 * restricted for them under Access Control.
	 *
	 * @param bool $is_post_restricted Whether the post is restricted for the current user.
	 * @param int  $post_id            Post ID.
	 * @return bool
	 */
	public static function maybe_unrestrict_unlocked_post( $is_post_restricted, $post_id ) {
		if ( ! $is_post_restricted ) {
			return $is_post_restricted;
		}
		// While Woo Memberships is loaded it owns the front-end; leave its
		// restriction outcome untouched.
		if ( DependencyChecker::is_wc_memberships_loaded() ) {
			return $is_post_restricted;
		}
		if ( self::has_valid_unlock( $post_id ) ) {
			return false;
		}
		return $is_post_restricted;
	}

	/**
	 * Gated posts are not restricted inside feeds, so their full body stays
	 * available for Google News to ingest. Ingestion is a prerequisite for
	 * Extended Access ever being offered on the article, so this takes
	 * precedence over the Access Control restrict_feeds setting, exactly as the
	 * Woo Memberships path takes precedence over that plugin's feed
	 * restriction.
	 *
	 * @param bool $is_post_restricted Whether the post is restricted for the current user.
	 * @param int  $post_id            Post ID.
	 * @return bool
	 */
	public static function unrestrict_feed_content( $is_post_restricted, $post_id ) {
		if ( ! $is_post_restricted ) {
			return $is_post_restricted;
		}
		// While Woo Memberships is loaded it owns the front-end, and its own
		// feed stand-down already covers this.
		if ( DependencyChecker::is_wc_memberships_loaded() ) {
			return $is_post_restricted;
		}
		if ( is_feed() ) {
			return false;
		}
		return $is_post_restricted;
	}

	/**
	 * Skip Access Control metering entirely for an unlocked view, so the view
	 * is neither counted against the reader's meter nor surfaced in metering
	 * UI (e.g. the countdown notice).
	 *
	 * @param mixed $short_circuit Short-circuit value; anything non-null skips metering.
	 * @return mixed
	 */
	public static function maybe_short_circuit_metering( $short_circuit ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}
		// While Woo Memberships is loaded it owns the front-end; leave its
		// metering interplay untouched.
		if ( DependencyChecker::is_wc_memberships_loaded() ) {
			return $short_circuit;
		}
		if ( is_singular() && self::has_valid_unlock( get_queried_object_id() ) ) {
			return true;
		}
		return $short_circuit;
	}

	/**
	 * Manages actions added by woocommerce membership to restrict access to post content.
	 */
	public static function manage_paywall_restriction() {
		// Woo Memberships-specific handling; under Newspack Access Control the
		// integration happens via the content gate filters instead.
		if ( ! DependencyChecker::is_wc_memberships_loaded() ) {
			return;
		}

		$post_id = get_the_ID();
		$user_id = get_current_user_id();

		// Get membership instance.
		$membership_instance = wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance();

		// Only check the unlock cookie for logged-in users to prevent guest cookie forgery.
		$cookie_name = REST_Controller::get_unlock_cookie_name( $post_id, $user_id );
		if ( $user_id && isset( $_COOKIE[ $cookie_name ] ) ) {
			// Remove restriction for the post (post_id) for user (user_id).
			remove_action( 'wp', array( $membership_instance, 'handle_restriction_modes' ), 9 );
			remove_filter( 'the_posts', array( $membership_instance, 'exclude_restricted_content_comments' ), PHP_INT_MAX, 2 );
			remove_filter( 'pre_get_comments', array( $membership_instance, 'exclude_restricted_comments' ), PHP_INT_MAX );
			remove_filter( 'get_previous_post_where', array( $membership_instance, 'exclude_restricted_adjacent_posts' ), 1, 5 );
			remove_filter( 'get_next_post_where', array( $membership_instance, 'exclude_restricted_adjacent_posts' ), 1, 5 );
			remove_filter( 'posts_clauses', array( $membership_instance, 'handle_posts_clauses' ), PHP_INT_MAX, 2 );
			remove_filter( 'get_terms_args', array( $membership_instance, 'handle_get_terms_args' ), PHP_INT_MAX, 2 );
			remove_filter( 'terms_clauses', array( $membership_instance, 'handle_terms_clauses' ), PHP_INT_MAX );
		} elseif ( ! has_filter( 'wp', array( $membership_instance, 'handle_restriction_modes' ) ) ) {
			// Add restriction for the user (user_id) if they are not present for the post(post_id).
			add_action( 'wp', array( $membership_instance, 'handle_restriction_modes' ), 9 );
			add_filter( 'the_posts', array( $membership_instance, 'exclude_restricted_content_comments' ), PHP_INT_MAX, 2 );
			add_filter( 'pre_get_comments', array( $membership_instance, 'exclude_restricted_comments' ), PHP_INT_MAX );
			add_filter( 'get_previous_post_where', array( $membership_instance, 'exclude_restricted_adjacent_posts' ), 1, 5 );
			add_filter( 'get_next_post_where', array( $membership_instance, 'exclude_restricted_adjacent_posts' ), 1, 5 );
			add_filter( 'posts_clauses', array( $membership_instance, 'handle_posts_clauses' ), PHP_INT_MAX, 2 );
			add_filter( 'get_terms_args', array( $membership_instance, 'handle_get_terms_args' ), PHP_INT_MAX, 2 );
			add_filter( 'terms_clauses', array( $membership_instance, 'handle_terms_clauses' ), PHP_INT_MAX );
		}
	}
}
