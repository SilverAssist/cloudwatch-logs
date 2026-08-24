<?php
/**
 * Silver Assist CloudWatch Logs - Admin page
 *
 * @package SilverAssist\CloudWatchLogs\Admin
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Admin;

use SilverAssist\CloudWatchLogs\Model\ConnectionStatus;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Service\ConnectionTester;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\CloudWatchLogs\View\Admin\LogViewerView;
use SilverAssist\CloudWatchLogs\View\Admin\SettingsView;
use SilverAssist\PluginKernel\Interfaces\LoadableInterface;
use SilverAssist\SettingsHub\SettingsHub;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin admin page and dispatches its tabs.
 *
 * @since 1.0.0
 */
class AdminPage implements LoadableInterface {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public const PAGE_SLUG = 'silver-assist-cloudwatch-logs';

	/**
	 * Tabs the page can show.
	 *
	 * @var array<int, string>
	 * @since 1.0.0
	 */
	public const TABS = [ 'logs', 'settings' ];

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 * @since 1.0.0
	 */
	private static ?self $instance = null;

	/**
	 * Settings source.
	 *
	 * @var SettingsRepository
	 * @since 1.0.0
	 */
	private SettingsRepository $settings;

	/**
	 * Connection tester.
	 *
	 * @var ConnectionTester
	 * @since 1.0.0
	 */
	private ConnectionTester $tester;

	/**
	 * Build the page.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->settings = SettingsRepository::instance();
		$this->tester   = new ConnectionTester( $this->settings );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self The page.
	 * @since 1.0.0
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		\add_action( 'admin_menu', [ $this, 'register_with_hub' ], 4 );
		\add_action( 'admin_init', [ $this, 'handle_submit' ] );
	}

	/**
	 * Loading priority.
	 *
	 * @return int Admin band.
	 * @since 1.0.0
	 */
	public function get_priority(): int {
		return 30;
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
	 * Register the page with the Settings Hub, falling back to a standalone menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_with_hub(): void {
		if ( ! \class_exists( SettingsHub::class ) ) {
			\add_menu_page(
				\__( 'CloudWatch Logs', 'silver-assist-cloudwatch-logs' ),
				\__( 'CloudWatch Logs', 'silver-assist-cloudwatch-logs' ),
				Helpers::required_capability(),
				self::PAGE_SLUG,
				[ $this, 'render' ],
				'dashicons-cloud'
			);

			return;
		}

		SettingsHub::get_instance()->register_plugin(
			self::PAGE_SLUG,
			\__( 'CloudWatch Logs', 'silver-assist-cloudwatch-logs' ),
			[ $this, 'render' ],
			[
				'description' => \__( 'Read, search and follow an Amazon CloudWatch Logs group without leaving WordPress.', 'silver-assist-cloudwatch-logs' ),
				'version'     => SILVER_ASSIST_CLOUDWATCH_VERSION,
				'tab_title'   => \__( 'CloudWatch Logs', 'silver-assist-cloudwatch-logs' ),
				'plugin_file' => SILVER_ASSIST_CLOUDWATCH_FILE,
			]
		);
	}

	/**
	 * Persist a submitted settings form.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_submit(): void {
		if ( ! isset( $_POST['silver_assist_cloudwatch_save'] ) ) {
			return;
		}

		if ( ! Helpers::current_user_can_access() ) {
			\wp_die( \esc_html__( 'You are not allowed to change these settings.', 'silver-assist-cloudwatch-logs' ) );
		}

		$nonce = isset( $_POST['silver_assist_cloudwatch_nonce'] )
			? \sanitize_text_field( \wp_unslash( $_POST['silver_assist_cloudwatch_nonce'] ) )
			: '';

		if ( ! \wp_verify_nonce( $nonce, 'silver_assist_cloudwatch_settings' ) ) {
			\wp_die( \esc_html__( 'Security check failed. Reload the page and try again.', 'silver-assist-cloudwatch-logs' ) );
		}

		$this->settings->save( $this->read_submitted_values() );

		\add_settings_error(
			'silver_assist_cloudwatch_messages',
			'silver_assist_cloudwatch_saved',
			\__( 'Settings saved.', 'silver-assist-cloudwatch-logs' ),
			'updated'
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render(): void {
		if ( ! Helpers::current_user_can_access() ) {
			return;
		}

		$tab = $this->current_tab();
		?>
		<div class="wrap sacw-admin">
			<h1 class="wp-heading-inline"><?php \esc_html_e( 'CloudWatch Logs', 'silver-assist-cloudwatch-logs' ); ?></h1>

			<nav class="nav-tab-wrapper sacw-tabs" aria-label="<?php \esc_attr_e( 'CloudWatch Logs sections', 'silver-assist-cloudwatch-logs' ); ?>">
				<a href="<?php echo \esc_url( $this->tab_url( 'logs' ) ); ?>"
					class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php \esc_html_e( 'Logs', 'silver-assist-cloudwatch-logs' ); ?>
				</a>
				<a href="<?php echo \esc_url( $this->tab_url( 'settings' ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php \esc_html_e( 'Settings', 'silver-assist-cloudwatch-logs' ); ?>
				</a>
			</nav>

			<?php if ( 'logs' === $tab ) : ?>
				<?php LogViewerView::render( $this->settings ); ?>
			<?php else : ?>
				<?php SettingsView::render( $this->settings, $this->status() ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The tab to render.
	 *
	 * The viewer is the point of the plugin, so it is the default — but an
	 * unconfigured site is sent to the settings instead, since the viewer
	 * would have nothing to show.
	 *
	 * @return string One of the values in TABS.
	 * @since 1.0.0
	 */
	public function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which tab to display changes nothing.
		$requested = isset( $_GET['tab'] ) ? \sanitize_key( \wp_unslash( $_GET['tab'] ) ) : '';

		if ( \in_array( $requested, self::TABS, true ) ) {
			return $requested;
		}

		return $this->settings->is_configured() ? 'logs' : 'settings';
	}

	/**
	 * The latest known connection status.
	 *
	 * @return ConnectionStatus The status, or an empty failure when unconfigured.
	 * @since 1.0.0
	 */
	private function status(): ConnectionStatus {
		if ( ! $this->settings->is_configured() ) {
			return ConnectionStatus::failure(
				'',
				'',
				$this->settings->get_string( 'log_group' ),
				$this->settings->get_string( 'region' ),
				$this->settings->get_auth_mode()
			);
		}

		return $this->tester->cached_status();
	}

	/**
	 * Build the URL of one tab.
	 *
	 * @param string $tab Tab identifier.
	 * @return string The admin URL for that tab.
	 * @since 1.0.0
	 */
	private function tab_url( string $tab ): string {
		return \add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			],
			\admin_url( 'admin.php' )
		);
	}

	/**
	 * Read the submitted settings from the request.
	 *
	 * Sanitization happens in the repository, which owns the shape of each
	 * setting; this method only unslashes the raw request values.
	 *
	 * @return array<string, mixed> The submitted values.
	 * @since 1.0.0
	 */
	private function read_submitted_values(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified by the caller.
		$fields = [ 'auth_mode', 'region', 'log_group', 'access_key_id', 'secret_access_key', 'secret_id', 'default_range' ];
		$values = [];

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) && \is_string( $_POST[ $field ] ) ) {
				$values[ $field ] = \wp_unslash( $_POST[ $field ] );
			}
		}

		foreach ( [ 'poll_interval', 'page_size' ] as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$values[ $field ] = \absint( $_POST[ $field ] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $values;
	}
}
