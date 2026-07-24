<?php
/**
 * Hook snapshot control for test classes that load a plugin mid-run.
 *
 * @package Newspack\Tests
 */

/**
 * Lets a test class re-take the WP_UnitTestCase hook snapshot.
 *
 * WP_UnitTestCase snapshots $wp_filter on the first set_up() of the phpunit
 * process and restores it in every tear_down(), so any hook registered after
 * that snapshot is stripped once the first test of the process finishes. A
 * test class that loads a plugin in set_up_before_class() therefore keeps that
 * plugin's hooks only while it is the first class to run - as soon as another
 * class runs before it, the plugin is loaded too late for the snapshot and its
 * hooks vanish from the second test onwards. Discarding the snapshot right
 * after the plugin is loaded makes the class independent of that ordering.
 *
 * The snapshot being discarded lives on WP_UnitTestCase_Base and is shared by
 * every class in the phpunit process, so a bare reset would rebase the baseline
 * for all classes running afterwards rather than only the resetting one. The
 * prior snapshot is therefore captured and put back in tear_down_after_class(),
 * keeping the widened baseline scoped to the class that asked for it: a class
 * running later still starts from the baseline it would have had, so a test
 * asserting on a clean hook environment cannot pass alone and fail in the full
 * run because of a reset three files away.
 *
 * Requires the using class to extend WP_UnitTestCase, which is where the
 * snapshot property is declared.
 *
 * @see WP_UnitTestCase_Base::$hooks_saved
 */
trait Newspack_Hook_Snapshot {

	/**
	 * The hook snapshot as it stood before this class reset it.
	 *
	 * @var array|null
	 */
	protected static $hooks_saved_before_reset = null;

	/**
	 * Discard the hook snapshot so the next set_up() re-takes it.
	 */
	protected static function reset_hook_snapshot() {
		self::$hooks_saved_before_reset = self::$hooks_saved;
		self::$hooks_saved              = array();
	}

	/**
	 * Put back the snapshot this class replaced, so the widened baseline does
	 * not leak into classes that run afterwards in the same process.
	 */
	public static function tear_down_after_class() {
		if ( null !== self::$hooks_saved_before_reset ) {
			self::$hooks_saved              = self::$hooks_saved_before_reset;
			self::$hooks_saved_before_reset = null;
		}
		parent::tear_down_after_class();
	}
}
