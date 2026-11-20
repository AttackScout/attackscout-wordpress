<?php
/**
 * Plugin Name:       AttackScout
 * Description:       Shows the AttackScout security grade of your site (A to F), its upcoming deadlines and your PHP and WordPress versions on the dashboard. The audit only runs when an administrator clicks.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            AttackScout
 * Author URI:        https://attackscout.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       attackscout
 * Domain Path:       /languages
 *
 * @package AttackScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

define( 'ATTACKSCOUT_VERSION', '0.1.0' );

// Addresses of the AttackScout service. A test or staging site can point
// elsewhere by defining these constants in wp-config.php.
if ( ! defined( 'ATTACKSCOUT_API_URL' ) ) {
	define( 'ATTACKSCOUT_API_URL', 'https://attackscout.com' );
}
if ( ! defined( 'ATTACKSCOUT_SITE_URL' ) ) {
	define( 'ATTACKSCOUT_SITE_URL', 'https://attackscout.com' );
}

require_once __DIR__ . '/includes/class-attackscout-api.php';
require_once __DIR__ . '/includes/class-attackscout-report.php';
require_once __DIR__ . '/includes/class-attackscout-admin.php';
require_once __DIR__ . '/includes/class-attackscout-plugin.php';

AttackScout_Plugin::boot( __FILE__ );
