<?php
/**
 * Silver Assist CloudWatch Logs - GitHub update channel
 *
 * @package SilverAssist\CloudWatchLogs\Core
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Core;

use SilverAssist\WpGithubUpdater\Updater as GitHubUpdater;
use SilverAssist\WpGithubUpdater\UpdaterConfig;

\defined( 'ABSPATH' ) || exit;

/**
 * Delivers plugin updates from the repository's GitHub releases.
 *
 * The parent constructor registers the update hooks, so building this class is
 * what switches the update channel on.
 *
 * @since 1.0.0
 */
class Updater extends GitHubUpdater {

	/**
	 * Configure the update channel.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $github_repo Repository in "owner/name" form.
	 * @since 1.0.0
	 */
	public function __construct( string $plugin_file, string $github_repo ) {
		$config = new UpdaterConfig(
			$plugin_file,
			$github_repo,
			[
				'plugin_name'        => 'Silver Assist CloudWatch Logs',
				'plugin_description' => 'View, search and follow the events of an Amazon CloudWatch Logs log group from the WordPress admin.',
				'plugin_author'      => 'Silver Assist',
				'plugin_homepage'    => "https://github.com/{$github_repo}",
				'requires_wordpress' => '6.5',
				'requires_php'       => '8.2',
				// Must match what scripts/build-release.sh produces, which is
				// named after the plugin folder rather than the main file.
				'asset_pattern'      => 'cloudwatch-logs-v{version}.zip',
				'cache_duration'     => 12 * 3600,
				'ajax_action'        => 'silver_assist_cloudwatch_check_version',
				'ajax_nonce'         => 'silver_assist_cloudwatch_version_check',
				'text_domain'        => 'silver-assist-cloudwatch-logs',
			]
		);

		parent::__construct( $config );
	}
}
