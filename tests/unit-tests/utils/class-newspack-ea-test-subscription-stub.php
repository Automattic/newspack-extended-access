<?php
/**
 * Stand-in for a WooCommerce Subscriptions subscription.
 *
 * @package Newspack\Tests
 */

/**
 * A subscription.
 */
class Newspack_EA_Test_Subscription_Stub {

	/**
	 * Subscription ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Start date as a Unix timestamp.
	 *
	 * @var int
	 */
	private $start;

	/**
	 * IDs of the products on the subscription.
	 *
	 * @var int[]
	 */
	private $product_ids;

	/**
	 * Subscription status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Next payment retry as a Unix timestamp. Set while a payment is being recovered.
	 *
	 * @var int
	 */
	private $payment_retry;

	/**
	 * Constructor.
	 *
	 * @param int    $id            Subscription ID.
	 * @param int    $start         Start date as a Unix timestamp.
	 * @param int[]  $product_ids   IDs of the products on the subscription.
	 * @param string $status        Subscription status.
	 * @param int    $payment_retry Next payment retry as a Unix timestamp.
	 */
	public function __construct( $id, $start, $product_ids, $status = 'active', $payment_retry = 0 ) {
		$this->id            = $id;
		$this->start         = $start;
		$this->product_ids   = $product_ids;
		$this->status        = $status;
		$this->payment_retry = $payment_retry;
	}

	/**
	 * Get the subscription ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Whether the subscription has a status.
	 *
	 * @param string|string[] $status Status or statuses to check.
	 * @return bool
	 */
	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	/**
	 * Whether a product is on the subscription.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function has_product( $product_id ) {
		return in_array( (int) $product_id, $this->product_ids, true );
	}

	/**
	 * Get a date as a Unix timestamp.
	 *
	 * @param string $date_type Date type.
	 * @return int
	 */
	public function get_time( $date_type ) {
		$dates = array(
			'start'         => $this->start,
			'payment_retry' => $this->payment_retry,
		);
		return $dates[ $date_type ] ?? 0;
	}
}
