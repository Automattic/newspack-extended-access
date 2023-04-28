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
		add_action( 'wp', array( __CLASS__, 'manage_paywall_restriction' ), 9 );
	}

	/**
	 * Manages actions added by woocommerce membership to restrict access to post content.
	 */
	public static function manage_paywall_restriction() {
		$post_id = get_the_ID();
		$user_id = get_current_user_id();

		// Example cookie name, Made from post id and user id.
		$cookie_name = 'newspack_' . md5( $post_id . $user_id );

		// Get membership instance.
		$membership_instance = wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance();

		// Checks if cookie is set, grants access only if cookie is set.
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			// Remove restriction for the post (post_id) for user (user_id).
			remove_action( 'wp', array( $membership_instance, 'handle_restriction_modes' ) );
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
