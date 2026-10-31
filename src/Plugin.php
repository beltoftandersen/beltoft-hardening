<?php
/**
 * Wires the controls.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Boot.
 */
final class Plugin {

	/**
	 * Whether the hooks are in place.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers every control. Each one checks its own setting when it runs, so a change in the
	 * settings takes effect at once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		RestUsers::register();
		AuthorArchives::register();
		LoginErrors::register();
		AdminLogin::register();
		AuthorDetails::register();
		SettingsPage::register();
	}
}
