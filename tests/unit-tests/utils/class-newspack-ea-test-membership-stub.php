<?php
/**
 * Stand-in for a WooCommerce Memberships user membership.
 *
 * @package Newspack\Tests
 */

if ( ! class_exists( 'Newspack_EA_Test_Membership_Stub' ) ) {
	/**
	 * Exposes just the `get_start_date()` shape the plugin reads off a
	 * `WC_Memberships_User_Membership`.
	 */
	class Newspack_EA_Test_Membership_Stub {
		/**
		 * Start timestamp returned by `get_start_date( 'timestamp' )`.
		 *
		 * @var int
		 */
		public $start_timestamp;

		/**
		 * @param int $start_timestamp Membership start timestamp.
		 */
		public function __construct( $start_timestamp ) {
			$this->start_timestamp = $start_timestamp;
		}

		/**
		 * @param string $format Format token. Only 'timestamp' is honoured here.
		 * @return int
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- format arg kept for parity with WC.
		public function get_start_date( $format = 'mysql' ) {
			return $this->start_timestamp;
		}
	}
}
