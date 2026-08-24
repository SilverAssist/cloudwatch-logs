<?php
/**
 * Silver Assist CloudWatch Logs - Admin assets
 *
 * @package SilverAssist\CloudWatchLogs\Admin
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Admin;

use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\PluginKernel\Interfaces\LoadableInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the plugin's admin styles and scripts, only on its own screens.
 *
 * @since 1.0.0
 */
class AssetManager implements LoadableInterface {

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
	 * @return self The asset manager.
	 * @since 1.0.0
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the enqueue hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Loading priority.
	 *
	 * @return int Assets band.
	 * @since 1.0.0
	 */
	public function get_priority(): int {
		return 40;
	}

	/**
	 * Whether this component should load.
	 *
	 * @return bool True in the admin only.
	 * @since 1.0.0
	 */
	public function should_load(): bool {
		return \is_admin();
	}

	/**
	 * Enqueue the admin assets on this plugin's screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		\wp_enqueue_style(
			'silver-assist-cloudwatch-admin',
			SILVER_ASSIST_CLOUDWATCH_URL . 'assets/css/admin.css',
			[],
			SILVER_ASSIST_CLOUDWATCH_VERSION
		);

		\wp_enqueue_script(
			'silver-assist-cloudwatch-admin',
			SILVER_ASSIST_CLOUDWATCH_URL . 'assets/js/admin.js',
			[],
			SILVER_ASSIST_CLOUDWATCH_VERSION,
			true
		);

		if ( 'logs' === AdminPage::instance()->current_tab() ) {
			\wp_enqueue_script(
				'silver-assist-cloudwatch-viewer',
				SILVER_ASSIST_CLOUDWATCH_URL . 'assets/js/viewer.js',
				[ 'silver-assist-cloudwatch-admin' ],
				SILVER_ASSIST_CLOUDWATCH_VERSION,
				true
			);

			$settings = SettingsRepository::instance();

			\wp_localize_script(
				'silver-assist-cloudwatch-viewer',
				'silverAssistCloudWatchViewer',
				[
					'pollInterval' => $settings->get_int( 'poll_interval' ),
					'minInterval'  => SettingsRepository::MIN_POLL_INTERVAL,
					'maxInterval'  => SettingsRepository::MAX_POLL_INTERVAL,
					'logGroup'     => $settings->get_string( 'log_group' ),
				]
			);
		}

		\wp_localize_script(
			'silver-assist-cloudwatch-admin',
			'silverAssistCloudWatch',
			[
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( Helpers::NONCE_ACTION ),
				'i18n'    => [
					'testing'      => \__( 'Validating…', 'silver-assist-cloudwatch-logs' ),
					'requestError' => \__( 'The request failed. Check that WordPress can reach admin-ajax.php.', 'silver-assist-cloudwatch-logs' ),
					/* translators: %d: number of log events currently displayed. */
					'eventsLoaded' => \__( '%d events loaded', 'silver-assist-cloudwatch-logs' ),
					'tailing'      => \__( 'Tailing…', 'silver-assist-cloudwatch-logs' ),
					'tailSlowed'   => \__( 'CloudWatch is throttling requests; slowing the refresh down.', 'silver-assist-cloudwatch-logs' ),
					'tailStopped'  => \__( 'Tail stopped after repeated errors.', 'silver-assist-cloudwatch-logs' ),
				],
			]
		);
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return bool True on the plugin's own screens.
	 * @since 1.0.0
	 */
	private function is_plugin_screen( string $hook_suffix ): bool {
		return false !== \strpos( $hook_suffix, AdminPage::PAGE_SLUG );
	}
}
