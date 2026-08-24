<?php
/**
 * Silver Assist CloudWatch Logs - Log event
 *
 * @package SilverAssist\CloudWatchLogs\Model
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Model;

use SilverAssist\CloudWatchLogs\Utils\Helpers;

\defined( 'ABSPATH' ) || exit;

/**
 * One event returned by CloudWatch Logs.
 *
 * @since 1.0.0
 */
final class LogEvent {

	/**
	 * Build an event.
	 *
	 * @param string $id           Event id, used to deduplicate while tailing.
	 * @param int    $timestamp_ms Event timestamp, in milliseconds.
	 * @param string $stream       Log stream the event came from.
	 * @param string $message      Raw message.
	 * @since 1.0.0
	 */
	public function __construct(
		public readonly string $id,
		public readonly int $timestamp_ms,
		public readonly string $stream,
		public readonly string $message
	) {
	}

	/**
	 * Build an event from an API result row.
	 *
	 * @param array<string, mixed> $row One entry of the events array.
	 * @return self The event.
	 * @since 1.0.0
	 */
	public static function from_api( array $row ): self {
		return new self(
			id: (string) ( $row['eventId'] ?? '' ),
			timestamp_ms: (int) ( $row['timestamp'] ?? 0 ),
			stream: (string) ( $row['logStreamName'] ?? '' ),
			message: (string) ( $row['message'] ?? '' )
		);
	}

	/**
	 * Represent the event for the browser.
	 *
	 * @return array<string, mixed> The event data, with a preformatted timestamp.
	 * @since 1.0.0
	 */
	public function to_array(): array {
		return [
			'id'        => $this->id,
			'timestamp' => $this->timestamp_ms,
			'time'      => Helpers::format_timestamp( $this->timestamp_ms ),
			'stream'    => $this->stream,
			'message'   => $this->message,
		];
	}
}
