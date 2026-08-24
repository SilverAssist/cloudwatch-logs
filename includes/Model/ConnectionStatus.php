<?php
/**
 * Silver Assist CloudWatch Logs - Connection status value object
 *
 * @package SilverAssist\CloudWatchLogs\Model
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\Model;

\defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a connection check against CloudWatch Logs.
 *
 * @since 1.0.0
 */
final class ConnectionStatus {

	/**
	 * Build a status.
	 *
	 * @param bool        $connected     Whether the log group could be read.
	 * @param string      $log_group     Log group that was probed.
	 * @param string      $region        AWS region that was probed.
	 * @param string      $auth_mode     Credential mode that was used.
	 * @param string|null $arn           Log group ARN, when known.
	 * @param int|null    $retention     Retention in days, or null when the group never expires.
	 * @param int         $stored_bytes  Bytes stored in the log group.
	 * @param string      $latest_stream Most recently active log stream.
	 * @param int         $last_event_ms Timestamp of the most recent event, in milliseconds.
	 * @param string      $identity      IAM identity the credentials resolved to.
	 * @param string      $error_code    AWS error code, when the check failed.
	 * @param string      $error_message Human readable error, when the check failed.
	 * @since 1.0.0
	 */
	public function __construct(
		public readonly bool $connected,
		public readonly string $log_group = '',
		public readonly string $region = '',
		public readonly string $auth_mode = '',
		public readonly ?string $arn = null,
		public readonly ?int $retention = null,
		public readonly int $stored_bytes = 0,
		public readonly string $latest_stream = '',
		public readonly int $last_event_ms = 0,
		public readonly string $identity = '',
		public readonly string $error_code = '',
		public readonly string $error_message = ''
	) {
	}

	/**
	 * Build a failed status.
	 *
	 * @param string $message   Human readable error.
	 * @param string $code      AWS error code, when available.
	 * @param string $log_group Log group that was probed.
	 * @param string $region    AWS region that was probed.
	 * @param string $auth_mode Credential mode that was used.
	 * @return self The failed status.
	 * @since 1.0.0
	 */
	public static function failure(
		string $message,
		string $code = '',
		string $log_group = '',
		string $region = '',
		string $auth_mode = ''
	): self {
		return new self(
			connected: false,
			log_group: $log_group,
			region: $region,
			auth_mode: $auth_mode,
			error_code: $code,
			error_message: $message
		);
	}

	/**
	 * Represent the status as a plain array, for caching and AJAX responses.
	 *
	 * @return array<string, mixed> The status data.
	 * @since 1.0.0
	 */
	public function to_array(): array {
		return [
			'connected'     => $this->connected,
			'log_group'     => $this->log_group,
			'region'        => $this->region,
			'auth_mode'     => $this->auth_mode,
			'arn'           => $this->arn,
			'retention'     => $this->retention,
			'stored_bytes'  => $this->stored_bytes,
			'latest_stream' => $this->latest_stream,
			'last_event_ms' => $this->last_event_ms,
			'identity'      => $this->identity,
			'error_code'    => $this->error_code,
			'error_message' => $this->error_message,
		];
	}

	/**
	 * Rebuild a status from its array representation.
	 *
	 * @param array<string, mixed> $data Previously cached status data.
	 * @return self The status.
	 * @since 1.0.0
	 */
	public static function from_array( array $data ): self {
		return new self(
			connected: (bool) ( $data['connected'] ?? false ),
			log_group: (string) ( $data['log_group'] ?? '' ),
			region: (string) ( $data['region'] ?? '' ),
			auth_mode: (string) ( $data['auth_mode'] ?? '' ),
			arn: isset( $data['arn'] ) && \is_string( $data['arn'] ) ? $data['arn'] : null,
			retention: isset( $data['retention'] ) && \is_numeric( $data['retention'] ) ? (int) $data['retention'] : null,
			stored_bytes: (int) ( $data['stored_bytes'] ?? 0 ),
			latest_stream: (string) ( $data['latest_stream'] ?? '' ),
			last_event_ms: (int) ( $data['last_event_ms'] ?? 0 ),
			identity: (string) ( $data['identity'] ?? '' ),
			error_code: (string) ( $data['error_code'] ?? '' ),
			error_message: (string) ( $data['error_message'] ?? '' )
		);
	}
}
