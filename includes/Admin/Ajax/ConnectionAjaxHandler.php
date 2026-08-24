<?php
/**
 * Silver Assist CloudWatch Logs - Connection AJAX endpoints
 *
 * @package SilverAssist\CloudWatchLogs\Admin\Ajax
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Admin\Ajax;

use Aws\Exception\AwsException;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Service\ClientFactory;
use SilverAssist\CloudWatchLogs\Service\ConnectionTester;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\CloudWatchLogs\View\Admin\StatusTableView;
use SilverAssist\PluginKernel\Interfaces\LoadableInterface;
use Throwable;

\defined( 'ABSPATH' ) || exit;

/**
 * Validates the AWS connection and lists log groups for the settings screen.
 *
 * @since 1.0.0
 */
class ConnectionAjaxHandler implements LoadableInterface {

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
	 * Build the handler.
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
	 * @return self The handler.
	 * @since 1.0.0
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the AJAX endpoints.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		\add_action( 'wp_ajax_silver_assist_cloudwatch_test_connection', [ $this, 'test_connection' ] );
		\add_action( 'wp_ajax_silver_assist_cloudwatch_list_log_groups', [ $this, 'list_log_groups' ] );
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
	 * @return bool True for AJAX requests in the admin.
	 * @since 1.0.0
	 */
	public function should_load(): bool {
		return \is_admin();
	}

	/**
	 * Validate the connection against the submitted, possibly unsaved, settings.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function test_connection(): void {
		if ( ! Helpers::validate_ajax_request() ) {
			\wp_send_json_error(
				[ 'message' => \__( 'Security check failed.', 'silver-assist-cloudwatch-logs' ) ],
				403
			);
		}

		if ( ! Helpers::throttle( 'test_connection', 2 ) ) {
			\wp_send_json_error(
				[ 'message' => \__( 'Slow down a moment before testing again.', 'silver-assist-cloudwatch-logs' ) ],
				429
			);
		}

		$status = $this->tester->test_and_cache( $this->read_overrides() );

		\ob_start();
		StatusTableView::render( $status );
		$html = (string) \ob_get_clean();

		$payload = [
			'connected' => $status->connected,
			'message'   => $status->connected
				? \__( 'Connected to CloudWatch Logs.', 'silver-assist-cloudwatch-logs' )
				: $status->error_message,
			'html'      => $html,
		];

		if ( $status->connected ) {
			\wp_send_json_success( $payload );
		}

		\wp_send_json_error( $payload );
	}

	/**
	 * List log groups matching a prefix, for the log group field's suggestions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function list_log_groups(): void {
		if ( ! Helpers::validate_ajax_request() ) {
			\wp_send_json_error(
				[ 'message' => \__( 'Security check failed.', 'silver-assist-cloudwatch-logs' ) ],
				403
			);
		}

		if ( ! Helpers::throttle( 'list_log_groups', 1 ) ) {
			\wp_send_json_error(
				[ 'message' => \__( 'Slow down a moment before searching again.', 'silver-assist-cloudwatch-logs' ) ],
				429
			);
		}

		$overrides = $this->read_overrides();
		$prefix    = $overrides['log_group'] ?? '';

		try {
			$client = ( new ClientFactory( $this->settings ) )->make_logs_client( $overrides );
			$args   = [ 'limit' => 50 ];

			if ( '' !== $prefix ) {
				$args['logGroupNamePrefix'] = $prefix;
			}

			$result = $client->describeLogGroups( $args );
			$groups = [];

			foreach ( $result['logGroups'] ?? [] as $group ) {
				if ( \is_array( $group ) && isset( $group['logGroupName'] ) ) {
					$groups[] = (string) $group['logGroupName'];
				}
			}

			\wp_send_json_success( [ 'log_groups' => $groups ] );
		} catch ( AwsException $e ) {
			\wp_send_json_error( [ 'message' => (string) ( $e->getAwsErrorMessage() ?? $e->getMessage() ) ] );
		} catch ( Throwable $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Read settings overrides from the request.
	 *
	 * The settings screen validates before saving, so the form's current values
	 * are used in preference to what is stored. An empty secret access key means
	 * "use the stored one", matching how the form treats it.
	 *
	 * @return array<string, string> The submitted overrides.
	 * @since 1.0.0
	 */
	private function read_overrides(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- validate_ajax_request() verified the nonce.
		$fields    = [ 'auth_mode', 'region', 'log_group', 'access_key_id', 'secret_access_key', 'secret_id' ];
		$overrides = [];

		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) || ! \is_string( $_POST[ $field ] ) ) {
				continue;
			}

			$value = \sanitize_text_field( \wp_unslash( $_POST[ $field ] ) );

			if ( 'secret_access_key' === $field && '' === $value ) {
				continue;
			}

			$overrides[ $field ] = $value;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $overrides;
	}
}
