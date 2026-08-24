<?php
/**
 * Silver Assist CloudWatch Logs - Connection status table
 *
 * @package SilverAssist\CloudWatchLogs\View\Admin
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\View\Admin;

use SilverAssist\CloudWatchLogs\Model\ConnectionStatus;
use SilverAssist\CloudWatchLogs\Utils\Helpers;

\defined( 'ABSPATH' ) || exit;

/**
 * Renders the card summarising the CloudWatch connection.
 *
 * @since 1.0.0
 */
class StatusTableView {

	/**
	 * Render the status card.
	 *
	 * @param ConnectionStatus $status Result of the latest connection check.
	 * @return void
	 * @since 1.0.0
	 */
	public static function render( ConnectionStatus $status ): void {
		?>
		<div class="status-card sacw-status-card">
			<div class="card-header">
				<span class="dashicons dashicons-cloud"></span>
				<h3><?php \esc_html_e( 'Connection', 'silver-assist-cloudwatch-logs' ); ?></h3>
				<span class="status-indicator <?php echo $status->connected ? 'active' : 'inactive'; ?>">
					<?php
					echo $status->connected
						? \esc_html__( 'Connected', 'silver-assist-cloudwatch-logs' )
						: \esc_html__( 'Not connected', 'silver-assist-cloudwatch-logs' );
					?>
				</span>
			</div>
			<div class="card-content">
				<?php if ( $status->connected ) : ?>
					<?php self::render_details( $status ); ?>
				<?php else : ?>
					<?php self::render_error( $status ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the table describing a working connection.
	 *
	 * @param ConnectionStatus $status Result of the latest connection check.
	 * @return void
	 * @since 1.0.0
	 */
	private static function render_details( ConnectionStatus $status ): void {
		$rows = [
			\__( 'Log group', 'silver-assist-cloudwatch-logs' )    => $status->log_group,
			\__( 'Region', 'silver-assist-cloudwatch-logs' )       => $status->region,
			\__( 'Retention', 'silver-assist-cloudwatch-logs' )    => self::format_retention( $status->retention ),
			\__( 'Stored size', 'silver-assist-cloudwatch-logs' )  => Helpers::format_bytes( $status->stored_bytes ),
			\__( 'Latest stream', 'silver-assist-cloudwatch-logs' ) => '' !== $status->latest_stream
				? $status->latest_stream
				: \__( 'No streams yet', 'silver-assist-cloudwatch-logs' ),
			\__( 'Last event', 'silver-assist-cloudwatch-logs' )   => '' !== Helpers::format_timestamp( $status->last_event_ms )
				? Helpers::format_timestamp( $status->last_event_ms )
				: \__( 'Never', 'silver-assist-cloudwatch-logs' ),
			\__( 'IAM identity', 'silver-assist-cloudwatch-logs' ) => '' !== $status->identity
				? $status->identity
				: \__( 'Not reported', 'silver-assist-cloudwatch-logs' ),
			\__( 'Auth mode', 'silver-assist-cloudwatch-logs' )    => self::format_auth_mode( $status->auth_mode ),
		];
		?>
		<table class="widefat striped sacw-status-table">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo \esc_html( (string) $label ); ?></th>
						<td><code><?php echo \esc_html( (string) $value ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the explanation of a failed connection.
	 *
	 * @param ConnectionStatus $status Result of the latest connection check.
	 * @return void
	 * @since 1.0.0
	 */
	private static function render_error( ConnectionStatus $status ): void {
		if ( '' === $status->error_message ) {
			?>
			<p><?php \esc_html_e( 'Configure the log group and credentials below, then validate the connection.', 'silver-assist-cloudwatch-logs' ); ?></p>
			<?php
			return;
		}
		?>
		<p class="sacw-status-error"><?php echo \esc_html( $status->error_message ); ?></p>
		<?php if ( '' !== $status->error_code ) : ?>
			<p class="description">
				<?php
				\printf(
					/* translators: %s: the AWS error code. */
					\esc_html__( 'AWS error code: %s', 'silver-assist-cloudwatch-logs' ),
					'<code>' . \esc_html( $status->error_code ) . '</code>'
				);
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * Describe a retention setting in words.
	 *
	 * @param int|null $days Retention in days, or null when the group never expires.
	 * @return string The description.
	 * @since 1.0.0
	 */
	private static function format_retention( ?int $days ): string {
		if ( null === $days || $days <= 0 ) {
			return \__( 'Never expires', 'silver-assist-cloudwatch-logs' );
		}

		return \sprintf(
			/* translators: %d: number of days. */
			\_n( '%d day', '%d days', $days, 'silver-assist-cloudwatch-logs' ),
			$days
		);
	}

	/**
	 * Describe the credential mode in words.
	 *
	 * @param string $mode Stored authentication mode.
	 * @return string The description.
	 * @since 1.0.0
	 */
	private static function format_auth_mode( string $mode ): string {
		return match ( $mode ) {
			'keys'   => \__( 'Access key and secret', 'silver-assist-cloudwatch-logs' ),
			'secret' => \__( 'AWS Secrets Manager', 'silver-assist-cloudwatch-logs' ),
			default  => \__( 'Host IAM role', 'silver-assist-cloudwatch-logs' ),
		};
	}
}
