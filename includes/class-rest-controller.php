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
	 * User meta recording the last time the reader opened an article through a
	 * Google Extended Access entry point. See record_extended_access_entry().
	 */
	const EXTENDED_ACCESS_ENTRY_META = 'newspack_extended_access_entry';

	/**
	 * User meta linking an account to a Google Account, holding Google's `sub`.
	 */
	const GOOGLE_SUB_META = 'extended_access_sub';

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_api_endpoints' ) );
		add_action( 'newspack_reader_verified', array( __CLASS__, 'unlink_google_account' ) );
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
	 * Grants the reader a metered unlock for a post.
	 *
	 * The grant is mirrored into `$_COOKIE` as well as sent to the browser, so
	 * anything later in the same request — `SinglePost_Subscription`, a second
	 * call to this endpoint — already sees the unlock instead of waiting for the
	 * browser to send the cookie back on the next request.
	 *
	 * @param int $post_id The post ID.
	 * @param int $user_id The user ID.
	 */
	private static function grant_unlock( $post_id, $user_id ) {
		$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );
		if ( ! headers_sent() ) {
			setcookie( $cookie_name, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_COOKIE[ $cookie_name ] = '1';
	}

	/**
	 * Records that a reader loaded an article through a Google Extended Access
	 * entry point, i.e. a Showcase link carrying the GAA request parameter.
	 *
	 * This is the only server-side trace of the "Already registered? Sign in"
	 * branch (Use Case 4): those readers authenticate through the site's normal
	 * login, never touch `/google/register`, and so never acquire an
	 * `extended_access_sub`. Without a marker the unlock endpoint cannot tell
	 * them apart from any other registered reader.
	 *
	 * @param int $user_id The user ID.
	 */
	public static function record_extended_access_entry( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}
		// Only freshness matters, and this runs on every Showcase article view,
		// so an entry recorded moments ago is left alone.
		$recorded = (int) get_user_meta( $user_id, self::EXTENDED_ACCESS_ENTRY_META, true );
		if ( $recorded && ( time() - $recorded ) < 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		update_user_meta( $user_id, self::EXTENDED_ACCESS_ENTRY_META, time() );
	}

	/**
	 * Whether the reader is in a position for Google to have granted them
	 * Extended Access: they either registered through Extended Access, or they
	 * recently opened an article through an Extended Access entry point.
	 *
	 * What this does and does not establish: in *client-side* Extended Access
	 * the grant decision is made by Google's library in the browser and the
	 * publisher receives no artifact to verify, so no check here can prove a
	 * grant happened. What it can do is keep the unlock endpoint scoped to
	 * readers who are actually in the Extended Access flow, rather than every
	 * registered reader on the site — the endpoint mints the cookie that lifts
	 * content gating, so the caller set is worth keeping small.
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	private static function reader_is_in_extended_access_flow( $user_id ) {
		if ( get_user_meta( $user_id, self::GOOGLE_SUB_META, true ) ) {
			return true;
		}
		$entry = (int) get_user_meta( $user_id, self::EXTENDED_ACCESS_ENTRY_META, true );
		return $entry > 0 && ( time() - $entry ) < HOUR_IN_SECONDS;
	}

	/**
	 * Permission check for the unlock endpoint.
	 *
	 * @return bool
	 */
	public static function unlock_article_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return self::reader_is_in_extended_access_flow( get_current_user_id() );
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
	 * subscriber, not when their WP account was created. The active gating
	 * system supplies the candidates, and the earliest wins. Google requires
	 * the field for a SUBSCRIBER grant, so access that no subscription granted
	 * reports the supplied registration timestamp. Google discards a userState
	 * whose timestamp is in the future, so those candidates are dropped.
	 *
	 * @param int $user_id            The user ID.
	 * @param int $post_id            The post the reader has access to.
	 * @param int $fallback_timestamp Timestamp to return when no start date can be resolved.
	 * @return int Unix timestamp.
	 */
	private static function get_subscription_timestamp( $user_id, $post_id, $fallback_timestamp ) {
		$start_dates = DependencyChecker::is_wc_memberships_loaded()
			? self::get_membership_start_dates( $user_id )
			: self::get_granting_subscription_start_dates( $user_id, $post_id );

		$now         = time();
		$start_dates = array_filter(
			$start_dates,
			function ( $start_date ) use ( $now ) {
				return $start_date > 0 && $start_date <= $now;
			}
		);

		return empty( $start_dates ) ? $fallback_timestamp : min( $start_dates );
	}

	/**
	 * Get the start dates of the reader's active WooCommerce Memberships.
	 *
	 * @param int $user_id The user ID.
	 * @return int[] Unix timestamps.
	 */
	private static function get_membership_start_dates( $user_id ) {
		if ( ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
			return array();
		}

		$start_dates = array();
		foreach ( (array) wc_memberships_get_user_active_memberships( $user_id ) as $membership ) {
			if ( is_object( $membership ) && method_exists( $membership, 'get_start_date' ) ) {
				$start_dates[] = (int) $membership->get_start_date( 'timestamp' );
			}
		}
		return $start_dates;
	}

	/**
	 * Get the start dates of the subscriptions that grant the reader access to
	 * a post under Access Control.
	 *
	 * This follows the order Access Control evaluates a post in: gates run by
	 * priority and the first one that lets the reader through decides access.
	 * Inside that gate, a subscription counts only when it satisfies a rule in
	 * a rule group the reader passes, so a recurring donation next to a gated
	 * product subscription is not reported.
	 *
	 * Access that a filter grants, such as a subscription held on another
	 * network site, has no local record and so no start date here.
	 *
	 * @param int $user_id The user ID.
	 * @param int $post_id The post ID.
	 * @return int[] Unix timestamps.
	 */
	private static function get_granting_subscription_start_dates( $user_id, $post_id ) {
		if (
			! $post_id
			|| ! function_exists( 'wcs_get_subscription' )
			|| ! is_callable( array( '\Newspack\Content_Restriction_Control', 'evaluate_gate' ) )
		) {
			return array();
		}

		foreach ( \Newspack\Content_Restriction_Control::get_post_gates( $post_id ) as $gate ) {
			$verdict = \Newspack\Content_Restriction_Control::evaluate_gate( $gate, $user_id );
			if ( ! $verdict['restricted'] ) {
				return empty( $gate['custom_access']['active'] ) ? array() : self::get_gate_subscription_start_dates( $gate, $user_id );
			}
			// A refusing gate with a layout ends the walk, so access came from elsewhere.
			if ( $verdict['gate_layout_id'] ) {
				return array();
			}
		}
		return array();
	}

	/**
	 * Get the start dates of the subscriptions that satisfy a gate's custom
	 * access rules for the reader.
	 *
	 * @param array $gate    Gate data.
	 * @param int   $user_id The user ID.
	 * @return int[] Unix timestamps.
	 */
	private static function get_gate_subscription_start_dates( $gate, $user_id ) {
		$evaluation       = \Newspack\User_Gate_Access::evaluate_gate_for_user( $gate, $user_id );
		$subscription_ids = array();
		foreach ( $evaluation['groups'] as $group ) {
			if ( ! $group['passes'] ) {
				continue;
			}
			foreach ( $group['rules'] as $rule ) {
				if ( 'subscription' !== $rule['slug'] ) {
					continue;
				}
				// The gate's own settings decide which statuses count, as they did for access.
				$subscription_ids = array_merge(
					$subscription_ids,
					\Newspack\Access_Rules::with_evaluation_context(
						$evaluation['context'],
						function () use ( $user_id, $rule ) {
							return \Newspack\Access_Rules::get_active_subscription_ids( $user_id, $rule['value'] );
						}
					)
				);
			}
		}

		$start_dates = array();
		foreach ( array_unique( $subscription_ids ) as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				$start_dates[] = (int) $subscription->get_time( 'start' );
			}
		}
		return $start_dates;
	}

	/**
	 * Get the email address in an ID token, if Google is authoritative for it.
	 *
	 * Google is authoritative for Gmail addresses and for verified addresses on
	 * a Workspace or Cloud hosted domain, which the `hd` claim names. Any other
	 * Google Account uses an address Google checked once, whose ownership may
	 * have changed since, so `email_verified` alone does not settle it.
	 *
	 * @see https://developers.google.com/identity/gsi/web/guides/verify-google-id-token
	 *
	 * @param object|\WP_Error $token An ID token.
	 * @return string|null The address, or null if Google is not authoritative for it.
	 */
	private static function get_google_authoritative_email( $token ) {
		if ( is_wp_error( $token ) || true !== ( $token->email_verified ?? null ) || empty( $token->email ) ) {
			return null;
		}
		$is_gmail = str_ends_with( strtolower( $token->email ), '@gmail.com' );
		return $is_gmail || ! empty( $token->hd ) ? $token->email : null;
	}

	/**
	 * Find the account linked to a Google Account.
	 *
	 * Earlier releases could leave a `sub` on two accounts when a reader's Google
	 * address changed. The one with the token's address is the one in use.
	 *
	 * @param string $sub   The `sub` claim of an ID token.
	 * @param string $email The address in the same token.
	 * @return \WP_User|false The linked account, if there is one.
	 */
	private static function get_user_by_google_sub( $sub, $email ) {
		$linked_users = get_users(
			array(
				'blog_id'    => 0,
				'meta_key'   => self::GOOGLE_SUB_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'ID',
			)
		);
		foreach ( $linked_users as $linked_user ) {
			if ( 0 === strcasecmp( $linked_user->user_email, $email ) ) {
				return $linked_user;
			}
		}
		return empty( $linked_users ) ? false : $linked_users[0];
	}

	/**
	 * Ends a reader's link to a Google Account once their address is verified.
	 *
	 * The address is proved by then, and a link made before that may belong to
	 * whoever started the account, so it goes the way their sessions do. A
	 * Google Account that is authoritative for the address links again on its
	 * next sign-in.
	 *
	 * @param \WP_User $user The verified reader.
	 */
	public static function unlink_google_account( $user ) {
		delete_user_meta( $user->ID, self::GOOGLE_SUB_META );
	}

	/**
	 * Whether the user has full access to the post through the active content
	 * gating system (e.g. a membership or a satisfied content gate), meaning
	 * no metered unlock is needed.
	 *
	 * The user must be the current user: the Newspack Access Control check
	 * evaluates gate access rules for the current session.
	 *
	 * @param int $user_id The user ID.
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	private static function can_user_view_post( $user_id, $post_id ) {
		if ( DependencyChecker::is_wc_memberships_loaded() ) {
			return (bool) wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) );
		}
		if ( ! $post_id || ! DependencyChecker::is_newspack_access_control_active() ) {
			return false;
		}
		/*
		 * The Access Control check evaluates the current session only, so a
		 * mismatch cannot be answered rather than denied. Every caller reaches
		 * here after Reader_Activation::set_current_reader(), so this is loud
		 * instead of a quiet denial: a caller that stops setting the current
		 * reader would otherwise downgrade a genuine subscriber to a metered
		 * single-article unlock with no visible symptom.
		 */
		if ( get_current_user_id() !== (int) $user_id ) {
			_doing_it_wrong(
				__METHOD__,
				'The Access Control access check can only answer for the current user.',
				'1.2.0'
			);
			return false;
		}

		/*
		 * Evaluate the underlying gate access without the Extended Access unlock
		 * filter: a metered unlock must not report as full (SUBSCRIBER) access.
		 *
		 * Restore exactly what was removed, at the priority it was registered at,
		 * and do it in a finally: a gate access rule that throws must not leave
		 * the front-end unlock lifting disabled for the rest of the request.
		 */
		$unlock_filter       = array( SinglePost_Subscription::class, 'maybe_unrestrict_unlocked_post' );
		$registered_priority = has_filter( 'newspack_is_post_restricted', $unlock_filter );
		if ( false !== $registered_priority ) {
			remove_filter( 'newspack_is_post_restricted', $unlock_filter, $registered_priority );
		}
		try {
			return ! \Newspack\Content_Gate::is_post_restricted( (int) $post_id );
		} finally {
			if ( false !== $registered_priority ) {
				add_filter( 'newspack_is_post_restricted', $unlock_filter, $registered_priority, 2 );
			}
		}
	}

	/**
	 * Starts the reader's session and keeps `$_COOKIE` in step with it.
	 *
	 * `Reader_Activation::set_current_reader()` ends the current session and
	 * opens a new one, but WordPress only sends the new cookie to the browser —
	 * `$_COOKIE` keeps the token that just died. Any nonce minted afterwards in
	 * this request is therefore bound to a session the client no longer has, so
	 * the client's next call fails its nonce check. Mirroring the new cookie
	 * back into `$_COOKIE` makes `wp_create_nonce()` produce a nonce the client
	 * can actually use.
	 *
	 * @param int $user_id The user ID.
	 * @return \WP_User|\WP_Error
	 */
	private static function set_current_reader( $user_id ) {
		$sync_session_cookie = function ( $logged_in_cookie ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
		};
		add_action( 'set_logged_in_cookie', $sync_session_cookie );
		$result = Newspack\Reader_Activation::set_current_reader( $user_id );
		remove_action( 'set_logged_in_cookie', $sync_session_cookie );
		return $result;
	}

	/**
	 * Wraps a userState payload in the response shape Extended Access expects.
	 *
	 * Every userState response carries a freshly minted REST nonce: both
	 * userState endpoints re-issue the reader's session, which invalidates the
	 * nonce the page was rendered with. The client has to adopt this one for its
	 * next call.
	 *
	 * @param array $user_state The userState payload.
	 * @return \WP_REST_Response
	 */
	private static function user_state_response( array $user_state ) {
		$response = rest_ensure_response( $user_state );
		$response->set_headers( array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		return $response;
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
				 * The cookie this endpoint mints is what lifts content gating
				 * for the reader, so the caller set stays as small as the
				 * Extended Access flow allows: a logged-in reader who either
				 * registered through Extended Access or opened this article
				 * through an Extended Access entry point in the last hour.
				 *
				 * Gating on `extended_access_sub` alone locked out readers who
				 * arrive through the "Already registered? Sign in" branch (Use
				 * Case 4) — they authenticate through the site's normal login
				 * and never acquire that meta — which is why the entry marker
				 * exists alongside it.
				 */
				'permission_callback' => array( __CLASS__, 'unlock_article_permissions_check' ),
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

		// Read before the filter below, which can replace or edit the token: only
		// `decode()` has checked its signature, issuer, audience and expiry.
		$google_authoritative_email = self::get_google_authoritative_email( $token );

		// Allow overriding the token for testing.
		$token = apply_filters( 'newspack_extended_access_decoded_token', $token, $request->get_body() );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// `sub` and the address identify the reader, and an empty `sub` in the
		// lookup below would match any linked account.
		if ( empty( $token->sub ) || empty( $token->email ) ) {
			return new \WP_Error( 'newspack_extended_access_google_token', __( 'Invalid token.', 'newspack-extended-access' ), array( 'status' => 400 ) );
		}

		// Get Google Email.
		$email = $token->email;

		// Google's `sub` identifies the reader. An address only opens an account
		// that has no Google Account linked yet.
		$linked_user   = self::get_user_by_google_sub( $token->sub, $email );
		$existing_user = $linked_user ? $linked_user : get_user_by( 'email', $email );
		// Normalise to an int so the unlock cookie name hashes identically
		// everywhere it is written and read.
		$post_id = absint( $request->get_header( 'X-WP-Post-ID' ) );
		$user_id = false;

		if ( $existing_user ) {
			// The address is all that ties this token to an unlinked account, and an
			// account linked to another Google Account belongs to someone else.
			if ( ! $linked_user && ( get_user_meta( $existing_user->ID, self::GOOGLE_SUB_META, true ) || $email !== $google_authoritative_email ) ) {
				return new \WP_Error( 'newspack_extended_access_google_account_mismatch', __( 'This Google Account cannot sign in to this account.', 'newspack-extended-access' ), array( 'status' => 403 ) );
			}

			$email   = $existing_user->user_email;
			$user_id = $existing_user->ID;
			$result  = self::set_current_reader( $existing_user->ID );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 403 ) );
			}
			update_user_meta( $existing_user->ID, self::GOOGLE_SUB_META, $token->sub );
		} else {
			// Enables registering through SWG even if it is disabled.
			add_filter( 'newspack_reader_activation_enabled', '__return_true' );
			$result = Newspack\Reader_Activation::register_reader( $email, '', true, [ 'registration_method' => 'google-extended-access' ] );

			if ( ! is_numeric( $result ) ) {
				remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
				return is_wp_error( $result )
					? new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 403 ) )
					: new \WP_Error( 'newspack_extended_access_registration_failed', __( 'The account could not be registered.', 'newspack-extended-access' ), array( 'status' => 409 ) );
			}

			$user_id = $result;
			self::set_current_reader( $user_id );
			$existing_user = get_user_by( 'id', $user_id );

			/*
			 * Google is authoritative for this address and the account did not
			 * exist before this request, so nobody else can hold its credentials.
			 * The branch above never does this: a matching account may have been
			 * created by someone else, who could still sign in with its password
			 * once it is marked as verified. Linking comes after this because
			 * verifying a reader ends any earlier link.
			 */
			if ( $email === $google_authoritative_email ) {
				Newspack\Reader_Activation::set_reader_verified( $user_id );
			}

			add_user_meta( $user_id, self::GOOGLE_SUB_META, $token->sub );
			remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
			// At this point the user will be logged in.
		}

		$member_can_view_post = self::can_user_view_post( $user_id, $post_id );

		if ( $member_can_view_post ) {
			$registration_timestamp = strtotime( $existing_user->user_registered );
			return self::user_state_response(
				array(
					'id'                    => base64_encode( $token->sub ),
					'email'                 => $email,
					'postId'                => $post_id,
					'registrationTimestamp' => $registration_timestamp,
					'subscriptionTimestamp' => self::get_subscription_timestamp( $user_id, $post_id, $registration_timestamp ),
					'granted'               => true,
					'grantReason'           => 'SUBSCRIBER',
				)
			);
		}

		// Grant metered access for the post the reader registered from.
		if ( $post_id ) {
			self::grant_unlock( $post_id, $user_id );
		}

		return self::user_state_response(
			array(
				'id'                    => base64_encode( $token->sub ),
				'email'                 => $email,
				'postId'                => $post_id,
				'registrationTimestamp' => strtotime( $existing_user->user_registered ),
				'granted'               => true,
				'grantReason'           => 'METERING',
			)
		);
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

		$member_can_view_post = self::can_user_view_post( $user_id, $post_id );

		if ( $member_can_view_post ) {
			return rest_ensure_response(
				array(
					'status' => 'SUBSCRIBER',
				)
			);
		}

		/*
		 * A repeat call changes nothing on the page, so it is answered
		 * distinctly from a first-time grant. Only the caller can decide whether
		 * a reload is worth doing, and without this it has nothing to tell the
		 * two apart by.
		 */
		$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );
		if ( isset( $_COOKIE[ $cookie_name ] ) ) { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
			return rest_ensure_response(
				array(
					'status' => 'ALREADY_UNLOCKED',
				)
			);
		}

		// The cookie is set server-side rather than handing its key to the client.
		self::grant_unlock( $post_id, $user_id );

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
			return self::user_state_response( array( 'granted' => false ) );
		}

		$user_id                = $logged_in_user->ID;
		$email                  = $logged_in_user->user_email;
		$registration_timestamp = strtotime( $logged_in_user->user_registered );

		// Refresh the current reader so the Newspack reader activation system
		// keeps in sync with the logged-in user.
		$result = self::set_current_reader( $user_id );
		if ( is_wp_error( $result ) ) {
			return self::user_state_response(
				array(
					'granted' => false,
					'reason'  => $result->get_error_code(),
				)
			);
		}

		// Build the userState `id`. Prefer the Google `sub` when the user
		// registered via Extended Access (so Google can correlate identities
		// across visits); otherwise derive a stable id from the WP user ID so
		// users who registered through other channels are still recognised as
		// "registered users" instead of being treated as anonymous. The derived
		// id is a keyed hash rather than an encoding, so the internal user ID
		// can't be recovered from the value we hand to Google.
		$jwt_sub       = get_user_meta( $user_id, self::GOOGLE_SUB_META, true );
		$user_state_id = $jwt_sub ? base64_encode( $jwt_sub ) : self::get_derived_user_state_id( $user_id );

		// Subscriber: the active content gating system grants full access to the
		// requested post — a WooCommerce Memberships plan or a satisfied Newspack
		// Access Control gate — so no metered unlock is needed.
		$member_can_view_post = $post_id && self::can_user_view_post( $user_id, $post_id );
		if ( $member_can_view_post ) {
			return self::user_state_response(
				array(
					'id'                    => $user_state_id,
					'email'                 => $email,
					'registrationTimestamp' => $registration_timestamp,
					'subscriptionTimestamp' => self::get_subscription_timestamp( $user_id, $post_id, $registration_timestamp ),
					'granted'               => true,
					'grantReason'           => 'SUBSCRIBER',
				)
			);
		}

		// Metered: a previously granted unlock cookie is present for this post.
		$cookie_name = self::get_unlock_cookie_name( $post_id, $user_id );
		if ( $post_id && isset( $_COOKIE[ $cookie_name ] ) ) {
			return self::user_state_response(
				array(
					'id'                    => $user_state_id,
					'email'                 => $email,
					'registrationTimestamp' => $registration_timestamp,
					'granted'               => true,
					'grantReason'           => 'METERING',
				)
			);
		}

		// Registered user with no current grant. `id` and `registrationTimestamp`
		// signal to Google that this is a known reader so it can evaluate Extended
		// Access (showing the EA CTA) instead of the registration intervention.
		return self::user_state_response(
			array(
				'id'                    => $user_state_id,
				'email'                 => $email,
				'registrationTimestamp' => $registration_timestamp,
				'granted'               => false,
			)
		);
	}
}
