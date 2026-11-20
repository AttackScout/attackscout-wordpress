<?php
/**
 * Plugin bootstrap.
 *
 * @package AttackScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Wires the plugin into WordPress. Nothing here contacts AttackScout: the
 * only outgoing request is sent when an administrator clicks "Run the audit".
 */
final class AttackScout_Plugin {

	/**
	 * Registers the hooks of the plugin.
	 *
	 * @param string $file Main plugin file.
	 * @return AttackScout_Admin
	 */
	public static function boot( string $file ): AttackScout_Admin {
		$basename = plugin_basename( $file );
		$admin    = new AttackScout_Admin( new AttackScout_Api( ATTACKSCOUT_API_URL ), plugin_dir_url( $file ), $basename );
		add_action(
			'init',
			static function () use ( $basename ): void {
				load_plugin_textdomain( 'attackscout', false, dirname( $basename ) . '/languages' );
			}
		);
		$admin->register();
		return $admin;
	}
}
