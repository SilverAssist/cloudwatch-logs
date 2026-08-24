<?php
/**
 * Silver Assist CloudWatch Logs - AWS SDK availability
 *
 * @package SilverAssist\CloudWatchLogs\Service
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Service;

use SilverAssist\PluginKernel\Interfaces\LoadableInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Confirms that the AWS SDK is reachable before any other component uses it.
 *
 * Every WordPress site this plugin targets already loads its own copy of
 * aws/aws-sdk-php from the project-level autoloader, so the bundled copy is a
 * fallback rather than the primary source. Both paths end with the SDK classes
 * autoloadable; this component only reports which one applied and warns the
 * administrator when neither did.
 *
 * @since 1.0.0
 */
class SdkLoader implements LoadableInterface {

	/**
	 * Class used to probe for the SDK.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const PROBE_CLASS = \Aws\CloudWatchLogs\CloudWatchLogsClient::class;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 * @since 1.0.0
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self The loader.
	 * @since 1.0.0
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the admin notice shown when the SDK is missing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		if ( self::is_available() ) {
			return;
		}

		\add_action( 'admin_notices', [ $this, 'render_missing_sdk_notice' ] );
	}

	/**
	 * Loading priority.
	 *
	 * @return int Core band, so the check runs before any consumer.
	 * @since 1.0.0
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * Whether this component should load.
	 *
	 * @return bool Always true; the component reports on the SDK either way.
	 * @since 1.0.0
	 */
	public function should_load(): bool {
		return true;
	}

	/**
	 * Whether the AWS SDK is autoloadable in this request.
	 *
	 * @return bool True when the CloudWatch Logs client can be instantiated.
	 * @since 1.0.0
	 */
	public static function is_available(): bool {
		return \class_exists( self::PROBE_CLASS );
	}

	/**
	 * Tell an administrator that the SDK could not be found.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_missing_sdk_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}

		\printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			\esc_html__(
				'Silver Assist CloudWatch Logs: the AWS SDK for PHP could not be loaded. Reinstall the plugin from its release package, or run "composer install" in the plugin directory.',
				'silver-assist-cloudwatch-logs'
			)
		);
	}
}
