<?php
/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

/**
 * Defines functionality to bypass paywall restriction for single post for single user.
 */
class Single_Post_Subscription {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		/* Hook function before Restriction class function hook,
		 * it removes actions added by woocommerce membership to
		 * restrict access to post content.
		 */
		add_action( 'wp', [ __CLASS__, 'remove_pay_restriction' ], 9 );
	}
	
	/**
	 * Removes actions added by woocommerce membership to restrict access to post content.
	 */
	public static function remove_pay_restriction() {
		$post_id = get_the_ID();
		$user_id = get_current_user_id();

		// Example cookie name, Made from post id and user id 
		$cookie_name = 'newspack_' . md5( $post_id . $user_id );
		
		// Checks if cookie is set, grants access only if cookie is set.
		if ( isset( $_COOKIE[ $cookie_name ]) ) { 
			$membership_instance = wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance();
			remove_action( 'wp', [ $membership_instance, 'handle_restriction_modes' ] ) ;
			remove_filter( 'the_posts',        [ $membership_instance, 'exclude_restricted_content_comments' ], 999, 2 );
			remove_filter( 'pre_get_comments', [ $membership_instance, 'exclude_restricted_comments' ], 999 );
			remove_filter( 'get_previous_post_where', [ $membership_instance, 'exclude_restricted_adjacent_posts' ], 1, 5 );
			remove_filter( 'get_next_post_where',     [ $membership_instance, 'exclude_restricted_adjacent_posts' ], 1, 5 );
			remove_filter( 'posts_clauses',  [ $membership_instance, 'handle_posts_clauses' ], 999, 2 );
			remove_filter( 'get_terms_args', [ $membership_instance, 'handle_get_terms_args' ], 999, 2 );
			remove_filter( 'terms_clauses',  [ $membership_instance, 'handle_terms_clauses' ], 999 );
		}
	}
}
