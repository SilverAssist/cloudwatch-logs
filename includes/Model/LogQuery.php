<?php
/**
 * Silver Assist CloudWatch Logs - Search parameters
 *
 * @package SilverAssist\CloudWatchLogs\Model
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Model;

use InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * A validated CloudWatch Logs search.
 *
 * @since 1.0.0
 */
final class LogQuery {

	/**
	 * Selectable relative ranges, mapped to their length in seconds.
	 *
	 * @var array<string, int>
	 * @since 1.0.0
	 */
	public const RANGES = [
		'5m'  => 300,
		'15m' => 900,
		'1h'  => 3600,
		'3h'  => 10800,
		'12h' => 43200,
		'24h' => 86400,
	];

	/**
	 * Build a query.
	 *
	 * @param string $log_group     Log group to search.
	 * @param int    $start_ms      Start of the range, in milliseconds.
	 * @param int    $end_ms        End of the range, in milliseconds.
	 * @param string $pattern       CloudWatch filter pattern, empty for no filtering.
	 * @param string $stream_prefix Log stream name prefix, empty for every stream.
	 * @param int    $limit         Maximum events to return.
	 * @param string $next_token    Pagination token from a previous page.
	 * @since 1.0.0
	 */
	private function __construct(
		public readonly string $log_group,
		public readonly int $start_ms,
		public readonly int $end_ms,
		public readonly string $pattern,
		public readonly string $stream_prefix,
		public readonly int $limit,
		public readonly string $next_token
	) {
	}

	/**
	 * Build a query from request input.
	 *
	 * @param array<string, mixed> $input     Sanitized request values.
	 * @param string               $log_group Configured log group.
	 * @param int                  $limit     Configured page size.
	 * @return self The validated query.
	 * @throws InvalidArgumentException When the range or search term is unusable.
	 * @since 1.0.0
	 */
	public static function from_input( array $input, string $log_group, int $limit ): self {
		if ( '' === $log_group ) {
			throw new InvalidArgumentException(
				\esc_html__( 'No log group is configured.', 'silver-assist-cloudwatch-logs' )
			);
		}

		[ $start_ms, $end_ms ] = self::resolve_range( $input );

		$pattern = FilterPatternBuilder::build(
			isset( $input['search'] ) ? (string) $input['search'] : '',
			isset( $input['search_mode'] ) ? (string) $input['search_mode'] : 'text'
		);

		return new self(
			log_group: $log_group,
			start_ms: $start_ms,
			end_ms: $end_ms,
			pattern: $pattern,
			stream_prefix: isset( $input['stream_prefix'] ) ? \trim( (string) $input['stream_prefix'] ) : '',
			limit: \max( 1, $limit ),
			next_token: isset( $input['next_token'] ) ? (string) $input['next_token'] : ''
		);
	}

	/**
	 * Build the query that fetches events newer than the last one seen.
	 *
	 * @param int $since_ms Timestamp of the newest event already displayed.
	 * @return self A query covering everything after that event.
	 * @since 1.0.0
	 */
	public function following( int $since_ms ): self {
		return new self(
			log_group: $this->log_group,
			start_ms: $since_ms + 1,
			end_ms: self::now_ms(),
			pattern: $this->pattern,
			stream_prefix: $this->stream_prefix,
			limit: $this->limit,
			next_token: ''
		);
	}

	/**
	 * Resolve the requested time range into absolute milliseconds.
	 *
	 * @param array<string, mixed> $input Sanitized request values.
	 * @return array{0: int, 1: int} Start and end of the range.
	 * @throws InvalidArgumentException When a custom range is incomplete or inverted.
	 * @since 1.0.0
	 */
	private static function resolve_range( array $input ): array {
		$range = isset( $input['range'] ) ? (string) $input['range'] : '1h';

		if ( 'custom' !== $range ) {
			$seconds = self::RANGES[ $range ] ?? self::RANGES['1h'];
			$end     = self::now_ms();

			return [ $end - ( $seconds * 1000 ), $end ];
		}

		$start_ms = isset( $input['start_ms'] ) ? (int) $input['start_ms'] : 0;
		$end_ms   = isset( $input['end_ms'] ) ? (int) $input['end_ms'] : 0;

		if ( $start_ms <= 0 || $end_ms <= 0 ) {
			throw new InvalidArgumentException(
				\esc_html__( 'Enter both a start and an end for a custom range.', 'silver-assist-cloudwatch-logs' )
			);
		}

		if ( $start_ms >= $end_ms ) {
			throw new InvalidArgumentException(
				\esc_html__( 'The start of the range must come before its end.', 'silver-assist-cloudwatch-logs' )
			);
		}

		return [ $start_ms, $end_ms ];
	}

	/**
	 * The current time in milliseconds.
	 *
	 * @return int Milliseconds since the Unix epoch.
	 * @since 1.0.0
	 */
	private static function now_ms(): int {
		return (int) ( \microtime( true ) * 1000 );
	}

	/**
	 * Represent the query as FilterLogEvents arguments.
	 *
	 * @return array<string, mixed> The API arguments.
	 * @since 1.0.0
	 */
	public function to_api_args(): array {
		$args = [
			'logGroupName' => $this->log_group,
			'startTime'    => $this->start_ms,
			'endTime'      => $this->end_ms,
			'limit'        => $this->limit,
		];

		if ( '' !== $this->pattern ) {
			$args['filterPattern'] = $this->pattern;
		}

		if ( '' !== $this->stream_prefix ) {
			$args['logStreamNamePrefix'] = $this->stream_prefix;
		}

		if ( '' !== $this->next_token ) {
			$args['nextToken'] = $this->next_token;
		}

		return $args;
	}
}
