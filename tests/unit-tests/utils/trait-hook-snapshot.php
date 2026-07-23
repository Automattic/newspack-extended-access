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
 */
trait Newspack_Hook_Snapshot {

	/**
	 * Discard the hook snapshot so the next set_up() re-takes it.
	 */
	protected static function reset_hook_snapshot() {
		self::$hooks_saved = array();
	}
}
