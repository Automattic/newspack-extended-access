<?php
/**
 * Adds REST Endpoints to register and check status
 * of the current Google Extended Access user.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

use Newspack;

/**
 * Adds REST Endpoints to register and check status
 * of the current Google Extended Access user.
 */
class ExtendedAccess_REST_Endpoint {

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_api_endpoints' ) );
	}

	/**
	 * Registers REST Endpoints for Extended Access.
	 */
	public static function register_api_endpoints() {
		\register_rest_route(
			'newspack-extended-access/v1',
			'/login/status',
			array(
				'methods'  => \WP_REST_Server::READABLE,
				'callback' => array( __CLASS__, 'api_login_status' ),
			)
		);

		\register_rest_route(
			'newspack-extended-access/v1',
			'/login/google',
			array(
				'methods'  => \WP_REST_Server::CREATABLE,
				'callback' => array( __CLASS__, 'api_google_login_register' ),
			)
		);
	}

	/**
	 * Handles Google Extended Access registration route.
	 *
	 * @param  \WP_REST_Request $request Request object.
	 * @return mixed            Returns Extended Access userState  object.
	 */
	public static function api_google_login_register( $request ) {
		// Decode JWT.
		$token = json_decode( base64_decode( str_replace( '_', '/', str_replace( '-', '+', explode( '.', $request->get_body() )[1] ) ) ) );

		// Get Google Email.
		$email = $token->email;

		$existing_user = \get_user_by( 'email', $email );

		if ( $existing_user ) {
			self::assign_user_plan( $email );
		} else {
			\add_filter( 'newspack_reader_activation_enabled', array( __CLASS__, 'bypass_newspack_reader_activation_enabled' ) );
			$result = Newspack\Reader_Activation::register_reader( $email, '', true, array() );
			self::assign_user_plan( $email );
			\remove_filter( 'newspack_reader_activation_enabled', array( __CLASS__, 'bypass_newspack_reader_activation_enabled' ) );
			// At this point the user will be logged in.
		}
		if ( is_wp_error( $result ) ) {
			return \rest_ensure_response(
				array(
					'granted' => false,
					'reason'  => $result,
				)
			);
		}

		return \rest_ensure_response(
			array(
				'id'                    => base64_encode( $token->sub ),
				'registrationTimestamp' => time(),
				'subscriptionTimestamp' => time(),
				'granted'               => true,
				'grantReason'           => 'SUBSCRIBER',
			)
		);
	}

	/**
	 * Assign an existing user 'premium-membership' when registered.
	 *
	 * @param  string $email EmailId of the new registered user.
	 */
	public static function assign_user_plan( $email ) {
		$existing_user = \get_user_by( 'email', $email );

		if ( $existing_user ) {
			// Log the user in.
			$result = Newspack\Reader_Activation::set_current_reader( $existing_user->ID );

			$user_id             = $existing_user->ID;
			$membership_plan     = wc_memberships_get_membership_plan( 'premium-membership' );
			$is_active_member    = wc_memberships_is_user_member( $user_id, $membership_plan->id, false );
			$existing_membership = wc_memberships_get_user_membership( $user_id, $membership_plan->id );
			$plans               = wc_memberships_get_membership_plans();

			if ( ! $is_active_member && empty( $existing_membership ) && 0 !== $user_id ) {
				$args           = array(
					// Enter the ID (post ID) of the plan to grant at registration.
					'plan_id' => $membership_plan->id,
					'user_id' => $user_id,
				);
				$new_membership = wc_memberships_create_user_membership( $args );
			}
		}
	}

	/**
	 * Handles Google Extended Access login status route.
	 *
	 * @param  \WP_REST_Request $request Request object.
	 * @return mixed            Returns Extended Access userState object.
	 */
	public static function api_login_status( $request ) {
		$logged_in_user = \wp_get_current_user();

		if ( $logged_in_user ) {
			$email = $logged_in_user->user_email;

			$existing_user = \get_user_by( 'email', $email );

			if ( $existing_user ) {
				// Log the user in.
				$result = Newspack\Reader_Activation::set_current_reader( $existing_user->ID );

				if ( is_wp_error( $result ) ) {
					return \rest_ensure_response(
						array(
							'granted' => false,
							'reason'  => $result,
						)
					);
				}
			} else {
				return \rest_ensure_response(
					array(
						'granted' => false,
						'reason'  => $result,
					)
				);
			}

			return \rest_ensure_response(
				array(
					'id'                    => base64_encode( $token->sub ),
					'registrationTimestamp' => time(),
					'subscriptionTimestamp' => time(),
					'granted'               => true,
					'grantReason'           => 'SUBSCRIBER',
				)
			);
		} else {
			return \rest_ensure_response(
				array(
					'granted' => false,
					'reason'  => 'no-logged-in-user',
				)
			);
		}
	}


	/**
	 * Enables registering through SWG even if it is disabled.
	 *
	 * @param  boolean $is_enabled Existing value of newspack_reader_activation_enabled option.
	 * @return boolean Returns always true for SWG.
	 */
	public static function bypass_newspack_reader_activation_enabled( $is_enabled ) {
		return true;
	}
}
