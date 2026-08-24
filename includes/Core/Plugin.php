<?php
/**
 * Silver Assist CloudWatch Logs - Plugin bootstrap
 *
 * @package SilverAssist\CloudWatchLogs\Core
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Core;

use SilverAssist\CloudWatchLogs\Admin\AssetManager;
use SilverAssist\CloudWatchLogs\Admin\Ajax\ConnectionAjaxHandler;
use SilverAssist\CloudWatchLogs\Admin\Ajax\LogsAjaxHandler;
use SilverAssist\CloudWatchLogs\Admin\AdminPage;
use SilverAssist\CloudWatchLogs\Service\SdkLoader;
use SilverAssist\PluginKernel\AbstractPlugin;
use SilverAssist\WpGithubUpdater\Updater as GitHubUpdater;

\defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * Registers every LoadableInterface component and the plugin-level hooks that
 * are not components themselves.
 *
 * @since 1.0.0
 */
final class Plugin extends AbstractPlugin {

	/**
	 * Repository the update channel pulls releases from.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const GITHUB_REPO = 'SilverAssist/cloudwatch-logs';

	/**
	 * The update channel, once initialised.
	 *
	 * @var Updater|null
	 * @since 1.0.0
	 */
	private ?Updater $updater = null;

	/**
	 * Components loaded by the kernel, in priority order.
	 *
	 * Each entry must expose a static instance() method and implement
	 * \SilverAssist\PluginKernel\Interfaces\LoadableInterface.
	 *
	 * @return array<int, class-string> Component class names.
	 * @since 1.0.0
	 */
	protected function get_components(): array {
		return [
			SdkLoader::class,
			AdminPage::class,
			ConnectionAjaxHandler::class,
			LogsAjaxHandler::class,
			AssetManager::class,
		];
	}

	/**
	 * Plugin-level hooks that are not LoadableInterface components.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function init_hooks(): void {
		\add_filter( 'plugin_action_links_' . SILVER_ASSIST_CLOUDWATCH_BASENAME, [ $this, 'add_action_links' ] );

		$this->init_updater();
	}

	/**
	 * Put Settings and View Logs on the plugin's row in the plugins list.
	 *
	 * Both point at the plugin's single admin page, each opening the tab its
	 * label promises, so neither link lands the reader on the wrong one.
	 *
	 * @param array<int|string, string> $links Existing action links.
	 * @return array<int|string, string> The links, with this plugin's first.
	 * @since 1.0.2
	 */
	public function add_action_links( array $links ): array {
		$own = [
			$this->build_action_link( 'settings', \__( 'Settings', 'silver-assist-cloudwatch-logs' ) ),
			$this->build_action_link( 'logs', \__( 'View Logs', 'silver-assist-cloudwatch-logs' ) ),
		];

		return \array_merge( $own, $links );
	}

	/**
	 * Build one link to a tab of the plugin's admin page.
	 *
	 * @param string $tab   Tab identifier.
	 * @param string $label Link text.
	 * @return string The anchor markup.
	 * @since 1.0.2
	 */
	private function build_action_link( string $tab, string $label ): string {
		$url = \add_query_arg(
			[
				'page' => AdminPage::PAGE_SLUG,
				'tab'  => $tab,
			],
			\admin_url( 'admin.php' )
		);

		return \sprintf( '<a href="%s">%s</a>', \esc_url( $url ), \esc_html( $label ) );
	}

	/**
	 * Wire the GitHub-based update channel.
	 *
	 * Constructing the updater is what registers its hooks; there is nothing
	 * further to call.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_updater(): void {
		if ( ! \class_exists( GitHubUpdater::class ) ) {
			return;
		}

		$this->updater = new Updater( SILVER_ASSIST_CLOUDWATCH_FILE, self::GITHUB_REPO );
	}

	/**
	 * The update channel, once initialised.
	 *
	 * @return Updater|null The updater, or null when the package is unavailable.
	 * @since 1.0.0
	 */
	public function get_updater(): ?Updater {
		return $this->updater;
	}
}
