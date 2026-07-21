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
		 * Newspack Access Control (content gates) integration. These filters are
		 * only consulted by the first-party gating, which stands down while Woo
		 * Memberships is active - so they are safe to register unconditionally.
		 */
		add_filter( 'newspack_content_gate_restrict_post', [ __CLASS__, 'maybe_allow_unlocked_post' ], 5, 2 ); // Before Access Control's metering handler at 10, so an unlocked view does not consume a metered view.
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
	 * Lift the Access Control restriction for a post the reader has unlocked
	 * via Google Extended Access.
	 *
	 * @param bool $restrict Whether to restrict the post.
	 * @param int  $post_id  Post ID.
	 * @return bool
	 */
	public static function maybe_allow_unlocked_post( $restrict, $post_id ) {
		if ( ! $restrict ) {
			return $restrict;
		}
		if ( self::has_valid_unlock( $post_id ) ) {
			return false;
		}
		return $restrict;
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
		// While Woo Memberships is active it owns the front-end; leave its
		// metering interplay untouched.
		if ( function_exists( 'wc_memberships' ) ) {
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
		if ( ! function_exists( 'wc_memberships' ) ) {
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
			add_action( 'wp', array( $membership_instance, 'handle_restriction_modes' ) );
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
