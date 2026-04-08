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
	const LOGIN_OR_REGISTER_GOOGLE_ENDPOINT = '/google/register';
	const UNLOCK_ARTICLE_ENDPOINT           = '/unlock-article';
	const VERIFY_USER_ENDPOINT              = '/login/status';

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_api_endpoints' ) );
	}

	/**
	 * Generate a keyed, non-forgeable unlock cookie name for a post and user.
	 *
	 * @param int $post_id The post ID.
	 * @param int $user_id The user ID.
	 * @return string The cookie name.
	 */
	public static function get_unlock_cookie_name( $post_id, $user_id ) {
		return 'newspack_' . wp_hash( $post_id . '|' . $user_id );
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
			self::UNLOCK_ARTICLE_ENDPOINT,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'api_unlock_article' ),
				'permission_callback' => function () {
					if ( ! is_user_logged_in() ) {
						return false;
					}
					// Only Extended Access users (registered via Google) may unlock articles.
					return (bool) get_user_meta( get_current_user_id(), 'extended_access_sub', true );
				},
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
		$google_token = new Google_Jwt( $request->get_body() );
		$token        = $google_token->decode();

		// Allow overriding the token for testing.
		$token = apply_filters( 'newspack_extended_access_decoded_token', $token, $request->get_body() );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Get Google Email.
		$email = $token->email;

		$existing_user = get_user_by( 'email', $email );
		$post_id       = $request->get_header( 'X-WP-Post-ID' );
		$user_id       = false;
		$granted       = false;

		if ( $existing_user ) {
			$user_id = $existing_user->ID;
			$result  = Newspack\Reader_Activation::set_current_reader( $existing_user->ID );
			if ( is_wp_error( $result ) ) {
				return $result;
			} else {
				update_user_meta( $existing_user->ID, 'extended_access_sub', $token->sub );
			}
		} else {
			// Enables registering through SWG even if it is disabled.
			add_filter( 'newspack_reader_activation_enabled', '__return_true' );
			$result = Newspack\Reader_Activation::register_reader( $email, '', true, [ 'registration_method' => 'google-extended-access' ] );

			if ( is_numeric( $result ) ) {
				$user_id = $result;
			} else {
				$user_id = $result->ID;
			}

			Newspack\Reader_Activation::set_current_reader( $user_id );
			$existing_user = get_user_by( 'id', $user_id );

			add_user_meta( $result, 'extended_access_sub', $token->sub );
			remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
			// At this point the user will be logged in.
		}

		$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );

		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			$granted = true;
		} else {
			$granted = false;
		}

		$member_can_view_post = false;
		if ( function_exists( 'wc_memberships_user_can' ) ) {
			$member_can_view_post = wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) );
		}

		if ( $member_can_view_post ) {
			$response = rest_ensure_response(
				array(
					'id'                    => base64_encode( $token->sub ),
					'email'                 => $email,
					'postId'                => $post_id,
					'registrationTimestamp' => strtotime( $existing_user->user_registered ),
					'subscriptionTimestamp' => strtotime( $existing_user->user_registered ), // TODO (@AnuragVasanwala): This should be revised.
					'granted'               => true,
					'grantReason'           => 'SUBSCRIBER',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		} else {
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
	public static function api_unlock_article( $request ) {
		$post_id = absint( $request->get_header( 'X-WP-Post-ID' ) );
		$user_id = get_current_user_id();

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', 'A valid post ID is required.', array( 'status' => 400 ) );
		}

		$member_can_view_post = false;
		if ( function_exists( 'wc_memberships_user_can' ) ) {
			$member_can_view_post = wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) );
		}

		if ( $member_can_view_post ) {
			return rest_ensure_response(
				array(
					'status' => 'SUBSCRIBER',
				)
			);
		}

		// Set the unlock cookie server-side instead of exposing the key.
		$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );
		if ( ! headers_sent() ) {
			setcookie( $cookie_name, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}

		return rest_ensure_response(
			array(
				'status' => 'UNLOCKED',
			)
		);
	}

	/**
	 * Handles Google Extended Access login status route.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return mixed            Returns Extended Access userState object.
	 */
	public static function api_verify_user( $request ) {
		$logged_in_user = wp_get_current_user();
		$post_id        = $request->get_header( 'X-WP-Post-ID' );

		if ( $logged_in_user ) {
			$email         = $logged_in_user->user_email;
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

				$granted = false;
				$user_id = $logged_in_user->ID;
				$jwt_sub = get_user_meta( $user_id, 'extended_access_sub', true );

				// Checks if cookie is set, grants access only if cookie is set.
				if ( $jwt_sub ) {
					$member_can_view_post = false;
					if ( function_exists( 'wc_memberships_user_can' ) ) {
						$member_can_view_post = wc_memberships_user_can( $existing_user->ID, 'view', array( 'post' => $post_id ) );
					}

					$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );

					if ( $member_can_view_post ) {
						$response = rest_ensure_response(
							array(
								'id'                    => base64_encode( $jwt_sub ),
								'email'                 => $email,
								'registrationTimestamp' => strtotime( $logged_in_user->user_registered ),
								'subscriptionTimestamp' => strtotime( $logged_in_user->user_registered ), // TODO (@AnuragVasanwala): This should be revised.
								'granted'               => true,
								'grantReason'           => 'SUBSCRIBER',
							)
						);
						$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
						return $response;
					} elseif ( isset( $_COOKIE[ $cookie_name ] ) ) {
						$response = rest_ensure_response(
							array(
								'id'                    => base64_encode( $jwt_sub ),
								'email'                 => $email,
								'registrationTimestamp' => strtotime( $logged_in_user->user_registered ),
								'granted'               => true,
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
								'granted'               => false,
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
						'reason'  => 'USER_DOES_NOT_EXISTS',
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
