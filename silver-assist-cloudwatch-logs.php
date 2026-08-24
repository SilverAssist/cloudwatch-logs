<?php
/**
 * Plugin Name: Silver Assist CloudWatch Logs
 * Plugin URI: https://github.com/SilverAssist/cloudwatch-logs
 * Description: View, search and follow the events of an Amazon CloudWatch Logs log group from the WordPress admin.
 * Version: 1.0.2
 * Author: Silver Assist
 * Author URI: https://silverassist.com
 * License: PolyForm-Noncommercial-1.0.0
 * License URI: https://polyformproject.org/licenses/noncommercial/1.0.0/
 * Text Domain: silver-assist-cloudwatch-logs
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 7.1
 * Requires PHP: 8.2
 * Network: false
 * Update URI: https://github.com/SilverAssist/cloudwatch-logs
 *
 * @package SilverAssist\CloudWatchLogs
 * @author Silver Assist
 * @license PolyForm-Noncommercial-1.0.0
 * @since 1.0.0
 * @version 1.0.2
 */

// Prevent direct access.
\defined( 'ABSPATH' ) || exit;

// Plugin constants.
\define( 'SILVER_ASSIST_CLOUDWATCH_VERSION', '1.0.2' );
\define( 'SILVER_ASSIST_CLOUDWATCH_FILE', __FILE__ );
\define( 'SILVER_ASSIST_CLOUDWATCH_PATH', \plugin_dir_path( __FILE__ ) );
\define( 'SILVER_ASSIST_CLOUDWATCH_URL', \plugin_dir_url( __FILE__ ) );
\define( 'SILVER_ASSIST_CLOUDWATCH_BASENAME', \plugin_basename( __FILE__ ) );

/**
 * Load the Composer autoloader, validating that it resolves inside the plugin
 * directory before requiring it.
 */
$silver_assist_cloudwatch_autoload      = SILVER_ASSIST_CLOUDWATCH_PATH . 'vendor/autoload.php';
$silver_assist_cloudwatch_real_autoload = \realpath( $silver_assist_cloudwatch_autoload );
$silver_assist_cloudwatch_real_path     = \realpath( SILVER_ASSIST_CLOUDWATCH_PATH );

if (
	$silver_assist_cloudwatch_real_autoload &&
	$silver_assist_cloudwatch_real_path &&
	0 === \strpos( $silver_assist_cloudwatch_real_autoload, $silver_assist_cloudwatch_real_path )
) {
	$silver_assist_cloudwatch_loader = require $silver_assist_cloudwatch_real_autoload;

	/*
	 * Composer registers its autoloader with prepend = true, so the last plugin
	 * to load wins every class name it can supply. That is exactly wrong here.
	 *
	 * This plugin bundles the AWS SDK, which drags in Guzzle and PSR-7 — libraries
	 * the host site very often already loaded, at a different version. PHP resolves
	 * a class name once and for good, so a prepended loader produces a mixed graph:
	 * the site's PSR-7 (loaded early, e.g. by a wp-config.php Secrets Manager call)
	 * combined with this plugin's newer Guzzle, which then calls PSR-7 methods that
	 * the older copy does not have.
	 *
	 * Appending instead means the host's copy of any shared library always wins, so
	 * whichever versions a site runs stay internally consistent. This plugin's own
	 * classes are unaffected: nothing else supplies them.
	 */
	if ( $silver_assist_cloudwatch_loader instanceof \Composer\Autoload\ClassLoader ) {
		$silver_assist_cloudwatch_loader->unregister();
		$silver_assist_cloudwatch_loader->register( false );
	}
} else {
	\add_action(
		'admin_notices',
		function (): void {
			\printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				\esc_html__(
					'Silver Assist CloudWatch Logs: missing or invalid Composer dependencies. Run "composer install".',
					'silver-assist-cloudwatch-logs'
				)
			);
		}
	);
	return;
}

// Load translations. Required explicitly because the plugin folder
// (cloudwatch-logs) and the text domain (silver-assist-cloudwatch-logs)
// intentionally differ, so WordPress cannot infer the path on its own.
\add_action(
	'init',
	function (): void {
		\load_plugin_textdomain(
			'silver-assist-cloudwatch-logs',
			false,
			\dirname( SILVER_ASSIST_CLOUDWATCH_BASENAME ) . '/languages'
		);
	}
);

// Bootstrap the plugin.
\add_action(
	'plugins_loaded',
	function (): void {
		\SilverAssist\CloudWatchLogs\Core\Plugin::instance()->init();
	}
);

// Activation and deactivation hooks.
\register_activation_hook(
	__FILE__,
	function (): void {
		\SilverAssist\CloudWatchLogs\Core\Activator::activate();
	}
);

\register_deactivation_hook(
	__FILE__,
	function (): void {
		\SilverAssist\CloudWatchLogs\Core\Activator::deactivate();
	}
);
