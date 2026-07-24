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
	 * Derive an opaque, stable userState `id` for a reader who has no Google
	 * `sub` (i.e. registered through a channel other than Extended Access).
	 *
	 * Keyed with `wp_hash()` so the value is stable for a given user on a given
	 * site but cannot be decoded back to the internal WordPress user ID. It is
	 * tied to the site's auth salts, so rotating them re-issues the id and
	 * Google sees the reader as new — an acceptable trade for not leaking an
	 * enumerable user ID.
	 *
	 * @param int $user_id The user ID.
	 * @return string Opaque identifier.
	 */
	private static function get_derived_user_state_id( $user_id ) {
		return 'wp_' . wp_hash( 'newspack_extended_access_user_state|' . $user_id );
	}

	/**
	 * Resolve the `subscriptionTimestamp` for the userState payload.
	 *
	 * Per the Extended Access spec this should reflect when the user became a
	 * subscriber, not when their WP account was created. We use the earliest
	 * start date across the user's active WC Memberships; if none can be
	 * resolved we fall back to the supplied registration timestamp so the
	 * response shape is still spec-compliant.
	 *
	 * @param int $user_id              The user ID.
	 * @param int $fallback_timestamp   Timestamp to return when no membership can be resolved.
	 * @return int Unix timestamp.
	 */
	private static function get_subscription_timestamp( $user_id, $fallback_timestamp ) {
		if ( ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
			return $fallback_timestamp;
		}

		$memberships = wc_memberships_get_user_active_memberships( $user_id );
		if ( empty( $memberships ) ) {
			return $fallback_timestamp;
		}

		$earliest = null;
		foreach ( $memberships as $membership ) {
			$start = is_object( $membership ) && method_exists( $membership, 'get_start_date' )
				? (int) $membership->get_start_date( 'timestamp' )
				: 0;
			if ( $start > 0 && ( null === $earliest || $start < $earliest ) ) {
				$earliest = $start;
			}
		}

		return null === $earliest ? $fallback_timestamp : $earliest;
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
				/*
				 * Any logged-in reader may unlock an article. In a *client-side*
				 * Extended Access paywall (the mode this plugin operates in),
				 * Google's GAA library performs all grant-policy evaluation in
				 * the browser and calls `unlockArticle` only when it decides
				 * to grant access — the publisher's job is just to honor that
				 * decision. The endpoint is therefore protected by WP auth +
				 * the REST nonce, and the resulting unlock cookie is keyed to
				 * the (post, user) pair so a grant can't be replayed for
				 * another reader. We previously gated this on the
				 * `extended_access_sub` user-meta, but that excluded readers
				 * who reached the article via the "Already registered? Sign
				 * in" branch (Use Case 4) — Google grants them EA but they
				 * never went through the Google-registration code path and
				 * therefore have no `extended_access_sub` set.
				 */
				'permission_callback' => function () {
					return is_user_logged_in();
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
		// Normalise to an int so the unlock cookie name hashes identically
		// everywhere it is written and read.
		$post_id = absint( $request->get_header( 'X-WP-Post-ID' ) );
		$user_id = false;
		$granted = false;

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

		$member_can_view_post = false;
		if ( function_exists( 'wc_memberships_user_can' ) ) {
			$member_can_view_post = wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) );
		}

		if ( $member_can_view_post ) {
			$registration_timestamp = strtotime( $existing_user->user_registered );
			$response               = rest_ensure_response(
				array(
					'id'                    => base64_encode( $token->sub ),
					'email'                 => $email,
					'postId'                => $post_id,
					'registrationTimestamp' => $registration_timestamp,
					'subscriptionTimestamp' => self::get_subscription_timestamp( $user_id, $registration_timestamp ),
					'granted'               => true,
					'grantReason'           => 'SUBSCRIBER',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}

		// Grant metered access for the post the reader registered from by
		// setting the unlock cookie inline.
		if ( $post_id ) {
			$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );
			if ( ! headers_sent() ) {
				setcookie( $cookie_name, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
		}

		$response = rest_ensure_response(
			array(
				'id'                    => base64_encode( $token->sub ),
				'postId'                => $post_id,
				'registrationTimestamp' => strtotime( $existing_user->user_registered ),
				'granted'               => true,
				'grantReason'           => 'METERING',
			)
		);
		$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		return $response;
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
		// Normalise to an int so the unlock cookie name hashes identically to the
		// one `api_unlock_article()` sets — a non-canonical numeric header (e.g.
		// "042") would otherwise produce a different hash and metering would
		// never be detected.
		$post_id = absint( $request->get_header( 'X-WP-Post-ID' ) );

		// Anonymous visitor — Google should show the registration intervention.
		if ( ! $logged_in_user || ! $logged_in_user->ID ) {
			$response = rest_ensure_response( array( 'granted' => false ) );
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}

		$user_id                = $logged_in_user->ID;
		$email                  = $logged_in_user->user_email;
		$registration_timestamp = strtotime( $logged_in_user->user_registered );

		// Refresh the current reader so the Newspack reader activation system
		// keeps in sync with the logged-in user.
		$result = Newspack\Reader_Activation::set_current_reader( $user_id );
		if ( is_wp_error( $result ) ) {
			$response = rest_ensure_response(
				array(
					'granted' => false,
					'reason'  => $result->get_error_code(),
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}

		// Build the userState `id`. Prefer the Google `sub` when the user
		// registered via Extended Access (so Google can correlate identities
		// across visits); otherwise derive a stable id from the WP user ID so
		// users who registered through other channels are still recognised as
		// "registered users" instead of being treated as anonymous. The derived
		// id is a keyed hash rather than an encoding, so the internal user ID
		// can't be recovered from the value we hand to Google.
		$jwt_sub       = get_user_meta( $user_id, 'extended_access_sub', true );
		$user_state_id = $jwt_sub ? base64_encode( $jwt_sub ) : self::get_derived_user_state_id( $user_id );

		// Subscriber: publisher membership grants access to the requested post.
		$member_can_view_post = false;
		if ( $post_id && function_exists( 'wc_memberships_user_can' ) ) {
			$member_can_view_post = wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) );
		}
		if ( $member_can_view_post ) {
			$response = rest_ensure_response(
				array(
					'id'                    => $user_state_id,
					'email'                 => $email,
					'registrationTimestamp' => $registration_timestamp,
					'subscriptionTimestamp' => self::get_subscription_timestamp( $user_id, $registration_timestamp ),
					'granted'               => true,
					'grantReason'           => 'SUBSCRIBER',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}

		// Metered: a previously granted unlock cookie is present for this post.
		$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );
		if ( $post_id && isset( $_COOKIE[ $cookie_name ] ) ) {
			$response = rest_ensure_response(
				array(
					'id'                    => $user_state_id,
					'email'                 => $email,
					'registrationTimestamp' => $registration_timestamp,
					'granted'               => true,
					'grantReason'           => 'METERING',
				)
			);
			$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
			return $response;
		}

		// Registered user with no current grant. `id` and `registrationTimestamp`
		// signal to Google that this is a known reader so it can evaluate Extended
		// Access (showing the EA CTA) instead of the registration intervention.
		$response = rest_ensure_response(
			array(
				'id'                    => $user_state_id,
				'email'                 => $email,
				'registrationTimestamp' => $registration_timestamp,
				'granted'               => false,
			)
		);
		$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		return $response;
	}
}
