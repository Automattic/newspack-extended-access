<?php
/**
 * Adds REST Endpoints to register and check status
 * of the current Google Extended Access user.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

use Newspack;
use WP_REST_Server;

/**
 * Adds REST Endpoints to register and check status
 * of the current Google Extended Access user.
 */
class REST_Controller {

	/**
	 * Plugin route namespace.
	 */
	const NAMESPACE = 'newspack-extended-access/v1';

	/**
	 * Endpoint constants.
	 */
	const LOGIN_OR_REGISTER_GOOGLE_ENDPOINT = '/login/google';
	const REGISTER_SUBSCRIPTION_ENDPOINT    = '/subscription/register';
	const VERIFY_USER_ENDPOINT              = '/login/status';

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
		register_rest_route(
			self::NAMESPACE,
			self::LOGIN_OR_REGISTER_GOOGLE_ENDPOINT,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'api_login_or_register_google_account' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::REGISTER_SUBSCRIPTION_ENDPOINT,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'api_register_subscription' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::VERIFY_USER_ENDPOINT,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'api_verify_user' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles Google Extended Access registration route.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return mixed            Returns Extended Access userState  object.
	 */
	public static function api_login_or_register_google_account( $request ) {

		// Decode JWT.
		$token = json_decode( base64_decode( str_replace( '_', '/', str_replace( '-', '+', explode( '.', $request->get_body() )[1] ) ) ) );

		// Get Google Email.
		$email = $token->email;

		$existing_user = get_user_by( 'email', $email );

		$granted = false;

		$post_id = $request->get_header( 'X-WP-Post-ID' );
		$user_id = false;

		if ( $existing_user ) {
			$user_id = $existing_user->ID;
			$result  = Newspack\Reader_Activation::set_current_reader( $existing_user->ID );
			if ( is_wp_error( $result ) ) {
				if ( in_array( array( 'administrator', 'editor' ), (array) $existing_user->roles ) ) {
					// Do not grand user with role either 'Admin' or 'Editor' to login via SwG.
					$user_id = -1;
				}
			} else {
				update_user_meta( $existing_user->ID, 'extended_access_sub', $token->sub );
			}
		} else {
			// Enables registering through SWG even if it is disabled.
			add_filter( 'newspack_reader_activation_enabled', '__return_true' );
			$result = Newspack\Reader_Activation::register_reader( $email, '', true, array() );

			if ( is_numeric( $result ) ) {
				$user_id = $result;
			} else {
				$user_id = $result->ID;
			}
			$current_reader = Newspack\Reader_Activation::set_current_reader( $user_id );
			$existing_user  = get_user_by( 'id', $user_id );

			add_user_meta( $result, 'extended_access_sub', $token->sub );

			remove_filter( 'newspack_reader_activation_enabled', '__return_true' );

			// At this point the user will be logged in.
		}

		// Example cookie name, Made from post id and user id.
		$cookie_name = 'newspack_' . md5( $post_id . $user_id );

		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			$granted  = true;
			$response = rest_ensure_response(
				array(
					'id'                    => base64_encode( $token->sub ),
					'postId'                => $post_id,
					'registrationTimestamp' => strtotime( $existing_user->user_registered ),
					'granted'               => $granted,
					'grantReason'           => 'METERING',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		} else {
			$granted  = false;
			$response = rest_ensure_response(
				array(
					'id'                    => base64_encode( $token->sub ),
					'postId'                => $post_id,
					'registrationTimestamp' => strtotime( $existing_user->user_registered ),
					'granted'               => $granted,
					'grantReason'           => 'METERING',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}
	}

	/**
	 * Handles Google Extended Access registration route.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return mixed            Returns Extended Access userState  object.
	 */
	public static function api_register_subscription( $request ) {
		try {
			$post_id       = $request->get_header( 'X-WP-Post-ID' );
			$existing_user = get_user_by( 'email', $request->get_header( 'X-WP-User-Email' ) );

			if ( $existing_user ) {
				$user_id = $existing_user->ID;

				if ( isset( $post_id ) ) {
					// Cookie name, Made from post-id and user-id.
					$cookie_name    = 'newspack_' . md5( $post_id . $user_id );
					$home_url_parts = wp_parse_url( home_url() );

					// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
					setcookie( $cookie_name, 'true', time() + 31556926, '/', $home_url_parts['host'], is_ssl(), false );
					return rest_ensure_response( array( 'data' => 'ok' ) );
				}
			}
			return rest_ensure_response( array( 'data' => 'NO_USER_OR_POST' ) );
		} catch ( Error $er ) {
			return rest_ensure_response( array( 'data' => $er ) );
		}
	}

	/**
	 * Handles Google Extended Access login status route.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return mixed            Returns Extended Access userState object.
	 */
	public static function api_verify_user( $request ) {
		$logged_in_user = wp_get_current_user();

		if ( $logged_in_user ) {
			$email = $logged_in_user->user_email;

			$existing_user = get_user_by( 'email', $email );

			if ( $existing_user ) {
				// Log the user in.
				$result = Newspack\Reader_Activation::set_current_reader( $existing_user->ID );

				if ( is_wp_error( $result ) ) {
					$response = rest_ensure_response(
						array(
							'granted' => false,
							'reason'  => $result,
						)
					);
					$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
					return $response;
				}
			} else {
				$response = rest_ensure_response(
					array(
						'granted' => false,
						'reason'  => 'USER_DOES_NOT_EXISTS',
					)
				);
				$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
				return $response;
			}

			$granted = false;
			$post_id = $request->get_header( 'X-WP-Post-ID' );
			$user_id = $logged_in_user->ID;

			// Cookie name, Made from post id and user id.
			$cookie_name = 'newspack_' . md5( $post_id . $user_id );

			$jwt_sub = get_user_meta( $user_id, 'extended_access_sub', true );

			// Checks if cookie is set, grants access only if cookie is set.
			if ( $jwt_sub ) {
				if ( isset( $_COOKIE[ $cookie_name ] ) ) {
					$granted = true;

					$response = rest_ensure_response(
						array(
							'id'                    => base64_encode( $jwt_sub ),
							'email'                 => $email,
							'registrationTimestamp' => strtotime( $logged_in_user->user_registered ),
							'granted'               => $granted,
							'grantReason'           => 'METERING',
						)
					);
					$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
					return $response;
				} else {
					$response = rest_ensure_response(
						array(
							'id'                    => base64_encode( $jwt_sub ),
							'email'                 => $email,
							'registrationTimestamp' => strtotime( $logged_in_user->user_registered ),
							'granted'               => $granted,
						)
					);
					$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
					return $response;
				}
			} else {
				$response = rest_ensure_response(
					array(
						'granted' => false,
					)
				);
				$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
				return $response;
			}
		} else {
			$response = rest_ensure_response(
				array(
					'granted' => false,
					'reason'  => 'NO_LOGGEND_IN_USER',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}
	}
}
