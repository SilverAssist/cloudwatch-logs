<?php
/**
 * Silver Assist CloudWatch Logs - Log search
 *
 * @package SilverAssist\CloudWatchLogs\Service
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Service;

use Aws\Exception\AwsException;
use RuntimeException;
use SilverAssist\CloudWatchLogs\Model\LogEvent;
use SilverAssist\CloudWatchLogs\Model\LogQuery;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;

\defined( 'ABSPATH' ) || exit;

/**
 * Runs searches against CloudWatch Logs.
 *
 * @since 1.0.0
 */
class LogsService {

	/**
	 * Settings source.
	 *
	 * @var SettingsRepository
	 * @since 1.0.0
	 */
	private SettingsRepository $settings;

	/**
	 * AWS client factory.
	 *
	 * @var ClientFactory
	 * @since 1.0.0
	 */
	private ClientFactory $clients;

	/**
	 * Build the service.
	 *
	 * @param SettingsRepository|null $settings Settings source, defaulting to the shared repository.
	 * @param ClientFactory|null      $clients  Client factory, defaulting to a new one.
	 * @since 1.0.0
	 */
	public function __construct( ?SettingsRepository $settings = null, ?ClientFactory $clients = null ) {
		$this->settings = $settings ?? SettingsRepository::instance();
		$this->clients  = $clients ?? new ClientFactory( $this->settings );
	}

	/**
	 * Run a search.
	 *
	 * @param LogQuery $query The validated search.
	 * @return array{events: array<int, LogEvent>, next_token: string, searched_streams: int} The page of results.
	 * @throws RuntimeException When AWS refuses the request.
	 * @since 1.0.0
	 */
	public function search( LogQuery $query ): array {
		try {
			$result = $this->clients->make_logs_client()->filterLogEvents( $query->to_api_args() );
		} catch ( AwsException $e ) {
			throw new RuntimeException( $this->explain( $e ), 0, $e );
		}

		$events = [];

		foreach ( $result['events'] ?? [] as $row ) {
			if ( \is_array( $row ) ) {
				$events[] = LogEvent::from_api( $row );
			}
		}

		$searched = $result['searchedLogStreams'] ?? [];

		return [
			'events'           => $events,
			'next_token'       => (string) ( $result['nextToken'] ?? '' ),
			'searched_streams' => \is_array( $searched ) ? \count( $searched ) : 0,
		];
	}

	/**
	 * Turn an AWS error into an actionable message.
	 *
	 * @param AwsException $e The error returned by the SDK.
	 * @return string A message naming the likely cause.
	 * @since 1.0.0
	 */
	private function explain( AwsException $e ): string {
		$detail = $e->getAwsErrorMessage() ?? $e->getMessage();

		return match ( (string) $e->getAwsErrorCode() ) {
			'AccessDeniedException', 'AccessDenied' => \sprintf(
				/* translators: %s: the error message returned by AWS. */
				\__( 'Access denied. The IAM identity in use needs logs:FilterLogEvents on this log group. AWS said: %s', 'silver-assist-cloudwatch-logs' ),
				$detail
			),
			'ResourceNotFoundException' => \__(
				'The log group no longer exists in this region.',
				'silver-assist-cloudwatch-logs'
			),
			'InvalidParameterException' => \sprintf(
				/* translators: %s: the error message returned by AWS. */
				\__( 'CloudWatch rejected the search: %s', 'silver-assist-cloudwatch-logs' ),
				$detail
			),
			'ThrottlingException', 'LimitExceededException' => \__(
				'AWS is throttling requests for this account. CloudWatch allows five searches per second across the whole account; wait a moment and try again.',
				'silver-assist-cloudwatch-logs'
			),
			default => $detail,
		};
	}
}
