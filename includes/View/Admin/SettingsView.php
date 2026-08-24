<?php
/**
 * Silver Assist CloudWatch Logs - Settings screen
 *
 * @package SilverAssist\CloudWatchLogs\View\Admin
 * @since 1.0.0
 */

namespace SilverAssist\CloudWatchLogs\View\Admin;

use SilverAssist\CloudWatchLogs\Model\ConnectionStatus;
use SilverAssist\CloudWatchLogs\Repository\SettingsRepository;

\defined( 'ABSPATH' ) || exit;

/**
 * Renders the plugin's settings screen.
 *
 * @since 1.0.0
 */
class SettingsView {

	/**
	 * Render the whole screen.
	 *
	 * @param SettingsRepository $settings Current settings.
	 * @param ConnectionStatus   $status   Latest known connection status.
	 * @return void
	 * @since 1.0.0
	 */
	public static function render( SettingsRepository $settings, ConnectionStatus $status ): void {
		$auth_mode = $settings->get_auth_mode();
		?>
		<?php \settings_errors( 'silver_assist_cloudwatch_messages' ); ?>

		<div class="sacw-grid">
				<?php StatusTableView::render( $status ); ?>

				<div class="status-card">
					<div class="card-header">
						<span class="dashicons dashicons-admin-settings"></span>
						<h3><?php \esc_html_e( 'AWS configuration', 'silver-assist-cloudwatch-logs' ); ?></h3>
					</div>
					<div class="card-content">
						<form method="post" action="">
							<?php \wp_nonce_field( 'silver_assist_cloudwatch_settings', 'silver_assist_cloudwatch_nonce' ); ?>

							<table class="form-table" role="presentation">
								<tbody>
									<?php
									self::render_text_row(
										'log_group',
										\__( 'Log group', 'silver-assist-cloudwatch-logs' ),
										$settings->get_string( 'log_group' ),
										\__( 'The exact CloudWatch Logs group name, for example /ecs/my-site.', 'silver-assist-cloudwatch-logs' ),
										$settings->is_constant_defined( 'log_group' ),
										'sacw-log-group'
									);

									self::render_text_row(
										'region',
										\__( 'Region', 'silver-assist-cloudwatch-logs' ),
										$settings->get_string( 'region' ),
										\__( 'The AWS region the log group lives in.', 'silver-assist-cloudwatch-logs' ),
										$settings->is_constant_defined( 'region' )
									);
									?>

									<tr>
										<th scope="row"><?php \esc_html_e( 'Authentication', 'silver-assist-cloudwatch-logs' ); ?></th>
										<td>
											<fieldset>
												<legend class="screen-reader-text">
													<?php \esc_html_e( 'Authentication mode', 'silver-assist-cloudwatch-logs' ); ?>
												</legend>
												<?php
												self::render_auth_choice(
													'auto',
													$auth_mode,
													\__( 'Use the host IAM role', 'silver-assist-cloudwatch-logs' ),
													\__( 'No credentials are stored. Recommended when WordPress runs on ECS, EC2 or any host with an attached role.', 'silver-assist-cloudwatch-logs' )
												);
												self::render_auth_choice(
													'keys',
													$auth_mode,
													\__( 'Use an access key and secret', 'silver-assist-cloudwatch-logs' ),
													\__( 'The secret is encrypted before it is stored.', 'silver-assist-cloudwatch-logs' )
												);
												self::render_auth_choice(
													'secret',
													$auth_mode,
													\__( 'Read the credentials from AWS Secrets Manager', 'silver-assist-cloudwatch-logs' ),
													\__( 'A single secret name or ARN replaces the key fields.', 'silver-assist-cloudwatch-logs' )
												);
												?>
											</fieldset>
										</td>
									</tr>
								</tbody>
							</table>

							<table class="form-table sacw-auth-fields" data-auth-mode="keys" role="presentation"
								<?php echo 'keys' === $auth_mode ? '' : 'hidden'; ?>>
								<tbody>
									<?php
									self::render_text_row(
										'access_key_id',
										\__( 'Access key ID', 'silver-assist-cloudwatch-logs' ),
										$settings->get_string( 'access_key_id' ),
										'',
										$settings->is_constant_defined( 'access_key_id' )
									);
									self::render_secret_row( $settings );
									?>
								</tbody>
							</table>

							<table class="form-table sacw-auth-fields" data-auth-mode="secret" role="presentation"
								<?php echo 'secret' === $auth_mode ? '' : 'hidden'; ?>>
								<tbody>
									<?php
									self::render_text_row(
										'secret_id',
										\__( 'Secret name or ARN', 'silver-assist-cloudwatch-logs' ),
										$settings->get_string( 'secret_id' ),
										\__( 'The secret must contain JSON with "accessKeyId" and "secretAccessKey" fields.', 'silver-assist-cloudwatch-logs' ),
										$settings->is_constant_defined( 'secret_id' )
									);
									?>
								</tbody>
							</table>

							<h4><?php \esc_html_e( 'Viewer defaults', 'silver-assist-cloudwatch-logs' ); ?></h4>
							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row">
											<label for="sacw-poll-interval"><?php \esc_html_e( 'Tail refresh interval', 'silver-assist-cloudwatch-logs' ); ?></label>
										</th>
										<td>
											<input type="number" class="small-text" id="sacw-poll-interval" name="poll_interval"
												value="<?php echo \esc_attr( (string) $settings->get_int( 'poll_interval' ) ); ?>"
												min="<?php echo \esc_attr( (string) SettingsRepository::MIN_POLL_INTERVAL ); ?>"
												max="<?php echo \esc_attr( (string) SettingsRepository::MAX_POLL_INTERVAL ); ?>" />
											<p class="description">
												<?php
												\printf(
													/* translators: %d: the shortest allowed interval, in seconds. */
													\esc_html__( 'Seconds between refreshes while tailing. CloudWatch allows five searches per second across the whole AWS account, so the minimum is %d seconds.', 'silver-assist-cloudwatch-logs' ),
													(int) SettingsRepository::MIN_POLL_INTERVAL
												);
												?>
											</p>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="sacw-page-size"><?php \esc_html_e( 'Events per page', 'silver-assist-cloudwatch-logs' ); ?></label>
										</th>
										<td>
											<input type="number" class="small-text" id="sacw-page-size" name="page_size"
												value="<?php echo \esc_attr( (string) $settings->get_int( 'page_size' ) ); ?>"
												min="1" max="<?php echo \esc_attr( (string) SettingsRepository::MAX_PAGE_SIZE ); ?>" />
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="sacw-default-range"><?php \esc_html_e( 'Default time range', 'silver-assist-cloudwatch-logs' ); ?></label>
										</th>
										<td>
											<select id="sacw-default-range" name="default_range">
												<?php foreach ( self::ranges() as $value => $label ) : ?>
													<option value="<?php echo \esc_attr( $value ); ?>"
														<?php \selected( $settings->get_string( 'default_range' ), $value ); ?>>
														<?php echo \esc_html( $label ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								</tbody>
							</table>

							<p class="sacw-actions">
								<?php \submit_button( \__( 'Save settings', 'silver-assist-cloudwatch-logs' ), 'primary', 'silver_assist_cloudwatch_save', false ); ?>
								<button type="button" class="button" id="sacw-test-connection">
									<?php \esc_html_e( 'Validate connection', 'silver-assist-cloudwatch-logs' ); ?>
								</button>
								<span class="spinner sacw-spinner"></span>
							</p>
							<div id="sacw-test-result" class="sacw-test-result" role="status" aria-live="polite"></div>
						</form>
					</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single-line text setting.
	 *
	 * @param string $name        Setting name, used as the field name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $description Help text shown under the field.
	 * @param bool   $locked      Whether a wp-config.php constant supplies the value.
	 * @param string $css_id      Optional element id, defaulting to one derived from the name.
	 * @return void
	 * @since 1.0.0
	 */
	private static function render_text_row(
		string $name,
		string $label,
		string $value,
		string $description = '',
		bool $locked = false,
		string $css_id = ''
	): void {
		$id           = '' !== $css_id ? $css_id : 'sacw-' . \str_replace( '_', '-', $name );
		$has_datalist = 'log_group' === $name;
		$datalist_id  = $id . '-options';
		?>
		<tr>
			<th scope="row"><label for="<?php echo \esc_attr( $id ); ?>"><?php echo \esc_html( $label ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="<?php echo \esc_attr( $id ); ?>"
					name="<?php echo \esc_attr( $name ); ?>"
					value="<?php echo \esc_attr( $value ); ?>"
					<?php
					if ( $has_datalist ) :
						?>
						list="<?php echo \esc_attr( $datalist_id ); ?>"<?php endif; ?>
					<?php \disabled( $locked ); ?> />
				<?php if ( $has_datalist ) : ?>
					<datalist id="<?php echo \esc_attr( $datalist_id ); ?>"></datalist>
				<?php endif; ?>
				<?php if ( $locked ) : ?>
					<p class="description">
						<?php \esc_html_e( 'Defined in wp-config.php; edit it there.', 'silver-assist-cloudwatch-logs' ); ?>
					</p>
				<?php elseif ( '' !== $description ) : ?>
					<p class="description"><?php echo \esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the masked secret access key field.
	 *
	 * The stored value is never sent to the browser: an empty submission keeps
	 * whatever is already saved.
	 *
	 * @param SettingsRepository $settings Current settings.
	 * @return void
	 * @since 1.0.0
	 */
	private static function render_secret_row( SettingsRepository $settings ): void {
		$locked    = $settings->is_constant_defined( 'secret_access_key' );
		$has_value = '' !== $settings->get_string( 'secret_access_key' );
		$broken    = $settings->has_unreadable_secret_access_key();
		?>
		<tr>
			<th scope="row">
				<label for="sacw-secret-access-key"><?php \esc_html_e( 'Secret access key', 'silver-assist-cloudwatch-logs' ); ?></label>
			</th>
			<td>
				<input type="password" class="regular-text" id="sacw-secret-access-key"
					name="secret_access_key" value="" autocomplete="new-password"
					placeholder="<?php echo \esc_attr( $has_value ? '••••••••••••••••' : '' ); ?>"
					<?php \disabled( $locked ); ?> />
				<?php if ( $locked ) : ?>
					<p class="description"><?php \esc_html_e( 'Defined in wp-config.php; edit it there.', 'silver-assist-cloudwatch-logs' ); ?></p>
				<?php elseif ( $broken ) : ?>
					<p class="description sacw-status-error">
						<?php \esc_html_e( 'The stored key can no longer be decrypted, which happens when the site security salts change. Enter it again.', 'silver-assist-cloudwatch-logs' ); ?>
					</p>
				<?php elseif ( $has_value ) : ?>
					<p class="description"><?php \esc_html_e( 'A key is stored. Leave this blank to keep it, or type a new one to replace it.', 'silver-assist-cloudwatch-logs' ); ?></p>
				<?php else : ?>
					<p class="description"><?php \esc_html_e( 'Encrypted before it is stored.', 'silver-assist-cloudwatch-logs' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one authentication mode choice.
	 *
	 * @param string $value       Mode this choice selects.
	 * @param string $current     Currently selected mode.
	 * @param string $label       Choice label.
	 * @param string $description Help text shown under the choice.
	 * @return void
	 * @since 1.0.0
	 */
	private static function render_auth_choice( string $value, string $current, string $label, string $description ): void {
		?>
		<label class="sacw-auth-choice">
			<input type="radio" name="auth_mode" value="<?php echo \esc_attr( $value ); ?>"
				<?php \checked( $current, $value ); ?> />
			<span><?php echo \esc_html( $label ); ?></span>
			<span class="description"><?php echo \esc_html( $description ); ?></span>
		</label>
		<?php
	}

	/**
	 * The selectable default time ranges.
	 *
	 * @return array<string, string> Range identifiers mapped to labels.
	 * @since 1.0.0
	 */
	public static function ranges(): array {
		return [
			'5m'  => \__( 'Last 5 minutes', 'silver-assist-cloudwatch-logs' ),
			'15m' => \__( 'Last 15 minutes', 'silver-assist-cloudwatch-logs' ),
			'1h'  => \__( 'Last hour', 'silver-assist-cloudwatch-logs' ),
			'3h'  => \__( 'Last 3 hours', 'silver-assist-cloudwatch-logs' ),
			'12h' => \__( 'Last 12 hours', 'silver-assist-cloudwatch-logs' ),
			'24h' => \__( 'Last 24 hours', 'silver-assist-cloudwatch-logs' ),
		];
	}
}
