<?php
/**
 * Silver Assist CloudWatch Logs - Log search AJAX endpoint
 *
 * @package SilverAssist\CloudWatchLogs\Admin\Ajax
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Admin\Ajax;

use Aws\Exception\AwsException;
use InvalidArgumentException;
use SilverAssist\CloudWatchLogs\Model\LogEvent;
use SilverAssist\CloudWatchLogs\Model\LogQuery;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;
use SilverAssist\CloudWatchLogs\Service\LogsService;
use SilverAssist\CloudWatchLogs\Utils\Helpers;
use SilverAssist\PluginKernel\Interfaces\LoadableInterface;
use Throwable;

\defined( 'ABSPATH' ) || exit;

/**
 * Serves the log viewer's searches.
 *
 * @since 1.0.0
 */
class LogsAjaxHandler implements LoadableInterface {

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
	 * Log search service.
	 *
	 * @var LogsService
	 * @since 1.0.0
	 */
	private LogsService $logs;

	/**
	 * Build the handler.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->settings = SettingsRepository::instance();
		$this->logs     = new LogsService( $this->settings );
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
	 * Register the AJAX endpoint.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		\add_action( 'wp_ajax_silver_assist_cloudwatch_search_logs', [ $this, 'search' ] );
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
	 * Run a search and return one page of events.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function search(): void {
		if ( ! Helpers::validate_ajax_request() ) {
			\wp_send_json_error(
				[ 'message' => \__( 'Security check failed.', 'silver-assist-cloudwatch-logs' ) ],
				403
			);
		}

		// Two locks: one keeps a single person from hammering the API, the
		// other keeps several administrators tailing at once from multiplying
		// the request rate against the account-wide CloudWatch quota.
		if ( ! Helpers::throttle( 'search_logs', 1 ) || ! Helpers::throttle_site( 'search_logs', 1 ) ) {
			\wp_send_json_error(
				[
					'message'   => \__( 'Slow down a moment; CloudWatch limits how often it can be searched.', 'silver-assist-cloudwatch-logs' ),
					'throttled' => true,
				],
				429
			);
		}

		$input = $this->read_input();

		try {
			$query = LogQuery::from_input(
				$input,
				$this->settings->get_string( 'log_group' ),
				$this->settings->get_int( 'page_size' )
			);

			$since_ms = isset( $input['since_ms'] ) ? (int) $input['since_ms'] : 0;

			if ( $since_ms > 0 ) {
				// Tailing: keep the same filter, but only ask for what arrived
				// after the newest event already on screen.
				$query = $query->following( $since_ms );
			}

			$page = $this->logs->search( $query );
		} catch ( InvalidArgumentException $e ) {
			\wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		} catch ( Throwable $e ) {
			\wp_send_json_error(
				[
					'message'   => $e->getMessage(),
					'throttled' => $this->is_throttling_error( $e ),
				]
			);
		}

		\wp_send_json_success(
			[
				'events'          => \array_map(
					static fn ( LogEvent $event ): array => $event->to_array(),
					$page['events']
				),
				'nextToken'       => $page['next_token'],
				'searchedStreams' => $page['searched_streams'],
			]
		);
	}

	/**
	 * Whether a failure was AWS refusing the request rate.
	 *
	 * The browser slows its polling down when it was, instead of retrying at
	 * the same cadence against a quota that is already exhausted.
	 *
	 * @param Throwable $e The failure.
	 * @return bool True when AWS reported throttling.
	 * @since 1.0.0
	 */
	private function is_throttling_error( Throwable $e ): bool {
		$previous = $e->getPrevious();

		if ( ! $previous instanceof AwsException ) {
			return false;
		}

		return \in_array(
			(string) $previous->getAwsErrorCode(),
			[ 'ThrottlingException', 'LimitExceededException' ],
			true
		);
	}

	/**
	 * Read the search parameters from the request.
	 *
	 * @return array<string, mixed> The sanitized input.
	 * @since 1.0.0
	 */
	private function read_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- validate_ajax_request() verified the nonce.
		$input = [];

		foreach ( [ 'range', 'search_mode', 'stream_prefix', 'next_token' ] as $field ) {
			if ( isset( $_POST[ $field ] ) && \is_string( $_POST[ $field ] ) ) {
				$input[ $field ] = \sanitize_text_field( \wp_unslash( $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['search'] ) && \is_string( $_POST['search'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_search_term() is the sanitizer; see its docblock for why sanitize_text_field() would corrupt the search.
			$input['search'] = Helpers::sanitize_search_term( \wp_unslash( $_POST['search'] ) );
		}

		foreach ( [ 'start_ms', 'end_ms', 'since_ms' ] as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$input[ $field ] = \absint( $_POST[ $field ] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $input;
	}
}
