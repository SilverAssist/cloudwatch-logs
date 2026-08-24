<?php
/**
 * Silver Assist CloudWatch Logs - Activation and deactivation
 *
 * @package SilverAssist\CloudWatchLogs\Core
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Core;

use SilverAssist\CloudWatchLogs\Service\ConnectionTester;

\defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation and deactivation.
 *
 * @since 1.0.0
 */
class Activator {

	/**
	 * Option name holding every plugin setting.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const OPTION_NAME = 'silver_assist_cloudwatch_settings';

	/**
	 * Default settings applied on first activation.
	 *
	 * The auth mode defaults to 'auto' so that a site running on ECS with a
	 * task role works without any credential being stored in the database.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	public const DEFAULT_SETTINGS = [
		'auth_mode'         => 'auto',
		'region'            => 'us-east-1',
		'log_group'         => '',
		'access_key_id'     => '',
		'secret_access_key' => '',
		'secret_id'         => '',
		'poll_interval'     => 10,
		'default_range'     => '1h',
		'page_size'         => 200,
	];

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function activate(): void {
		self::set_default_options();
	}

	/**
	 * Run deactivation tasks.
	 *
	 * Settings are intentionally preserved so that deactivating the plugin
	 * does not discard the AWS configuration.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		self::clear_transients();
	}

	/**
	 * Seed default settings without overwriting an existing configuration.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function set_default_options(): void {
		$existing = \get_option( self::OPTION_NAME );

		if ( ! \is_array( $existing ) ) {
			\add_option( self::OPTION_NAME, self::DEFAULT_SETTINGS );
			return;
		}

		// Fill in keys added by a newer version, keep what the site already set.
		\update_option( self::OPTION_NAME, \array_merge( self::DEFAULT_SETTINGS, $existing ) );
	}

	/**
	 * Drop the cached connection status.
	 *
	 * Credentials resolved from Secrets Manager are deliberately not cached in
	 * the database, so there is nothing else to clear here.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function clear_transients(): void {
		\delete_transient( ConnectionTester::CACHE_KEY );
	}
}
