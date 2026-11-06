<?php
/**
 * Stand-ins for the WooCommerce Subscriptions functions the Newspack plugin
 * calls when it evaluates a `subscription` access rule.
 *
 * WooCommerce Subscriptions is a paid plugin and is not installed in the test
 * harness. Stubbing at this level keeps the real Newspack rule evaluation in
 * the path, so the tests prove which subscription the rule picks.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
	/**
	 * Get the test reader's subscriptions.
	 *
	 * @param int $user_id User ID.
	 * @return array Subscriptions, keyed by ID.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature must match WC Subscriptions'.
	function wcs_get_users_subscriptions( $user_id = 0 ) {
		return $GLOBALS['newspack_ea_test_subscriptions'] ?? array();
	}
}

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * Get one of the test reader's subscriptions.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return Newspack_EA_Test_Subscription_Stub|false
	 */
	function wcs_get_subscription( $subscription_id ) {
		return $GLOBALS['newspack_ea_test_subscriptions'][ $subscription_id ] ?? false;
	}
}
