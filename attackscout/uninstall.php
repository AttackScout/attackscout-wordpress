<?php
/**
 * Removes the options of the plugin when it is deleted.
 *
 * @package AttackScout
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // @codeCoverageIgnore
}

delete_option( 'attackscout_api_key' );
delete_option( 'attackscout_last_scan' );
