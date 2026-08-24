<?php
/**
 * Silver Assist CloudWatch Logs - Log viewer screen
 *
 * @package SilverAssist\CloudWatchLogs\View\Admin
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\View\Admin;

use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;

\defined( 'ABSPATH' ) || exit;

/**
 * Renders the toolbar and the container the results are streamed into.
 *
 * @since 1.0.0
 */
class LogViewerView {

	/**
	 * Render the viewer.
	 *
	 * @param SettingsRepository $settings Current settings.
	 * @return void
	 * @since 1.0.0
	 */
	public static function render( SettingsRepository $settings ): void {
		?>
		<div class="sacw-viewer" data-log-group="<?php echo \esc_attr( $settings->get_string( 'log_group' ) ); ?>">
			<div class="sacw-toolbar">
				<div class="sacw-toolbar-row">
					<label for="sacw-range" class="screen-reader-text">
						<?php \esc_html_e( 'Time range', 'silver-assist-cloudwatch-logs' ); ?>
					</label>
					<select id="sacw-range">
						<?php foreach ( SettingsView::ranges() as $value => $label ) : ?>
							<option value="<?php echo \esc_attr( $value ); ?>"
								<?php \selected( $settings->get_string( 'default_range' ), $value ); ?>>
								<?php echo \esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
						<option value="custom"><?php \esc_html_e( 'Custom range', 'silver-assist-cloudwatch-logs' ); ?></option>
					</select>

					<span class="sacw-custom-range" hidden>
						<label for="sacw-start" class="screen-reader-text">
							<?php \esc_html_e( 'Start', 'silver-assist-cloudwatch-logs' ); ?>
						</label>
						<input type="datetime-local" id="sacw-start" />
						<label for="sacw-end" class="screen-reader-text">
							<?php \esc_html_e( 'End', 'silver-assist-cloudwatch-logs' ); ?>
						</label>
						<input type="datetime-local" id="sacw-end" />
					</span>

					<label for="sacw-search-mode" class="screen-reader-text">
						<?php \esc_html_e( 'Search mode', 'silver-assist-cloudwatch-logs' ); ?>
					</label>
					<select id="sacw-search-mode">
						<option value="text"><?php \esc_html_e( 'Text', 'silver-assist-cloudwatch-logs' ); ?></option>
						<option value="regex"><?php \esc_html_e( 'Regular expression', 'silver-assist-cloudwatch-logs' ); ?></option>
						<option value="pattern"><?php \esc_html_e( 'Filter pattern', 'silver-assist-cloudwatch-logs' ); ?></option>
					</select>

					<label for="sacw-search" class="screen-reader-text">
						<?php \esc_html_e( 'Search', 'silver-assist-cloudwatch-logs' ); ?>
					</label>
					<input type="search" id="sacw-search" class="regular-text"
						placeholder="<?php \esc_attr_e( 'Search the log messages…', 'silver-assist-cloudwatch-logs' ); ?>" />

					<button type="button" class="button button-primary" id="sacw-search-run">
						<?php \esc_html_e( 'Search', 'silver-assist-cloudwatch-logs' ); ?>
					</button>
					<span class="spinner sacw-viewer-spinner"></span>
				</div>

				<div class="sacw-toolbar-row">
					<label for="sacw-stream-prefix">
						<?php \esc_html_e( 'Stream prefix', 'silver-assist-cloudwatch-logs' ); ?>
					</label>
					<input type="text" id="sacw-stream-prefix" class="regular-text"
						placeholder="<?php \esc_attr_e( 'Every stream', 'silver-assist-cloudwatch-logs' ); ?>" />

					<label class="sacw-tail-toggle">
						<input type="checkbox" id="sacw-tail" />
						<?php
						\printf(
							/* translators: %d: refresh interval in seconds. */
							\esc_html__( 'Tail (refresh every %ds)', 'silver-assist-cloudwatch-logs' ),
							(int) $settings->get_int( 'poll_interval' )
						);
						?>
					</label>

					<span class="sacw-toolbar-spacer"></span>

					<span class="sacw-result-count" role="status" aria-live="polite"></span>

					<button type="button" class="button" id="sacw-export-csv" disabled>
						<?php \esc_html_e( 'Export CSV', 'silver-assist-cloudwatch-logs' ); ?>
					</button>
					<button type="button" class="button" id="sacw-export-json" disabled>
						<?php \esc_html_e( 'Export JSON', 'silver-assist-cloudwatch-logs' ); ?>
					</button>
				</div>

				<p class="description sacw-search-help" id="sacw-search-help">
					<?php \esc_html_e( 'Text matches the words as typed. Regular expression uses the CloudWatch regex syntax. Filter pattern passes your input to CloudWatch untouched.', 'silver-assist-cloudwatch-logs' ); ?>
				</p>
			</div>

			<div id="sacw-viewer-error" class="notice notice-error inline sacw-viewer-error" role="alert" hidden></div>

			<table class="widefat striped sacw-events">
				<thead>
					<tr>
						<th scope="col" class="sacw-col-time"><?php \esc_html_e( 'Time', 'silver-assist-cloudwatch-logs' ); ?></th>
						<th scope="col" class="sacw-col-stream"><?php \esc_html_e( 'Stream', 'silver-assist-cloudwatch-logs' ); ?></th>
						<th scope="col"><?php \esc_html_e( 'Message', 'silver-assist-cloudwatch-logs' ); ?></th>
					</tr>
				</thead>
				<tbody id="sacw-events-body">
				</tbody>
			</table>

			<p class="sacw-viewer-empty" id="sacw-viewer-empty" hidden>
				<?php \esc_html_e( 'No events matched this search.', 'silver-assist-cloudwatch-logs' ); ?>
			</p>

			<p class="sacw-viewer-actions">
				<button type="button" class="button" id="sacw-load-more" hidden>
					<?php \esc_html_e( 'Load more', 'silver-assist-cloudwatch-logs' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
