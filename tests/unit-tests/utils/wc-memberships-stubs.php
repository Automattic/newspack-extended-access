<?php
/**
 * Stand-ins for the WooCommerce Memberships functions this plugin calls.
 *
 * Memberships is a paid plugin and is not installed in the test harness, so the
 * Memberships branch of the code would otherwise be unreachable. Each stub
 * answers from a `$GLOBALS` flag the test sets, and records the arguments it
 * received so tests can assert that the plugin passes the right user and post
 * rather than only that it reacted to the return value.
 *
 * Not loaded by the Access Control suite, which must keep seeing Memberships as
 * inactive.
 *
 * @package Newspack\Tests
 */

/**
 * Resets every stub flag and recorded call. Call from `set_up()` so one test's
 * granted access cannot leak into the next.
 */
function newspack_ea_reset_wc_memberships_stubs() {
	$GLOBALS['newspack_ea_test_wc_memberships_user_can']       = false;
	$GLOBALS['newspack_ea_test_active_memberships']            = array();
	$GLOBALS['newspack_ea_test_post_content_restricted']       = false;
	$GLOBALS['newspack_ea_test_wc_memberships_user_can_calls'] = array();
	$GLOBALS['newspack_ea_test_active_memberships_calls']      = array();
}

// `DependencyChecker::is_wc_memberships_loaded()` probes for `wc_memberships()`,
// so the access checks below are only reached when this exists. It is never
// dereferenced in this suite — the one call site that does
// (`SinglePost_Subscription`) is exercised by the Access Control suite, which
// runs as its own process.
if ( ! function_exists( 'wc_memberships' ) ) {
	function wc_memberships() {
		return null;
	}
}

// Restriction lookup behind the LD+JSON schema. Reached because the stub above
// makes Memberships look loaded, so it has to answer or `wp_head` fatals.
if ( ! function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature must match WC Memberships'.
	function wc_memberships_is_post_content_restricted( $post = null ) {
		return ! empty( $GLOBALS['newspack_ea_test_post_content_restricted'] );
	}
}

// Access check behind the SUBSCRIBER userState.
if ( ! function_exists( 'wc_memberships_user_can' ) ) {
	function wc_memberships_user_can( $user_id, $action, $args = array() ) {
		$GLOBALS['newspack_ea_test_wc_memberships_user_can_calls'][] = array(
			'user_id' => $user_id,
			'action'  => $action,
			'args'    => $args,
		);
		return ! empty( $GLOBALS['newspack_ea_test_wc_memberships_user_can'] );
	}
}

// Membership lookup behind `subscriptionTimestamp`.
if ( ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
	function wc_memberships_get_user_active_memberships( $user_id ) {
		$GLOBALS['newspack_ea_test_active_memberships_calls'][] = $user_id;
		return isset( $GLOBALS['newspack_ea_test_active_memberships'] )
			? $GLOBALS['newspack_ea_test_active_memberships']
			: array();
	}
}

newspack_ea_reset_wc_memberships_stubs();
