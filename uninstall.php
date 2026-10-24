<?php
/**
 * Removes the plugin's single option.
 *
 * @package BeltoftHardening
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'beltoft_hardening_settings' );
