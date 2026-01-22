<?php
/**
 * Plugin Name: CHIPS SMTP Configuration
 * Description: Configure WordPress to send email via SMTP (PHPMailer). Supports wp-config/env secrets, with an optional settings UI.
 * Version: 0.1.0
 * Author: CHIPS
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opinionated security model:
 * - Prefer secrets (SMTP password / API key) in wp-config.php constants or environment variables.
 * - The settings page is optional; if you enter a password there, it will be stored in wp_options (plaintext in DB).
 *   This is normal for many WP plugins, but it's not truly "secure" storage.
 */

// -----------------------------
// Helpers
// -----------------------------

/**
 * Get config from (1) wp-config constant, (2) env var, (3) plugin option, (4) default.
 *
 * Constant / env naming: CHIPS_SMTP_HOST, CHIPS_SMTP_PORT, etc.
 * Options are stored under the `chips_smtp_options` option.
 */
function chips_smtp_get( string $key, $default = null ) {
	$const = 'CHIPS_SMTP_' . strtoupper( $key );

	if ( defined( $const ) ) {
		return constant( $const );
	}

	$env = getenv( $const );
	if ( $env !== false ) {
		return $env;
	}

	$opts = get_option( 'chips_smtp_options', [] );
	if ( is_array( $opts ) && array_key_exists( $key, $opts ) && $opts[ $key ] !== '' && $opts[ $key ] !== null ) {
		return $opts[ $key ];
	}

	return $default;
}

/**
 * Whether SMTP is enabled.
 */
function chips_smtp_is_enabled(): bool {
	$host = chips_smtp_get( 'host' );
	return ! empty( $host );
}

/**
 * Sanitize and normalize the options array.
 */
function chips_smtp_sanitize_options( array $raw ): array {
	$out = [];

	$out['host']      = isset( $raw['host'] ) ? sanitize_text_field( $raw['host'] ) : '';
	$out['port']      = isset( $raw['port'] ) ? (int) $raw['port'] : 587;
	$out['secure']    = isset( $raw['secure'] ) ? sanitize_text_field( $raw['secure'] ) : 'tls'; // tls|ssl|''
	$out['auth']      = isset( $raw['auth'] ) ? (bool) $raw['auth'] : true;
	$out['user']      = isset( $raw['user'] ) ? sanitize_text_field( $raw['user'] ) : '';

	// Password: if left blank in UI, keep the existing stored option.
	// NOTE: Still plaintext in DB. Prefer wp-config/env.
	$existing = get_option( 'chips_smtp_options', [] );
	$existing_pass = is_array( $existing ) && isset( $existing['pass'] ) ? (string) $existing['pass'] : '';
	$new_pass = isset( $raw['pass'] ) ? (string) $raw['pass'] : '';
	$out['pass'] = $new_pass !== '' ? $new_pass : $existing_pass;

	$out['from']      = isset( $raw['from'] ) ? sanitize_email( $raw['from'] ) : '';
	$out['from_name'] = isset( $raw['from_name'] ) ? sanitize_text_field( $raw['from_name'] ) : '';
	$out['timeout']   = isset( $raw['timeout'] ) ? (int) $raw['timeout'] : 10;

	// Optional envelope sender
	$out['sender']    = isset( $raw['sender'] ) ? sanitize_email( $raw['sender'] ) : '';

	$out['debug']     = ! empty( $raw['debug'] );

	return $out;
}

// -----------------------------
// Mail transport override
// -----------------------------

add_action( 'phpmailer_init', function ( $phpmailer ) {
	$host = chips_smtp_get( 'host' );
	if ( empty( $host ) ) {
		// No SMTP configured; let WP fall back to default transport.
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host       = $host;
	$phpmailer->Port       = (int) chips_smtp_get( 'port', 587 );
	$phpmailer->SMTPAuth   = (bool) chips_smtp_get( 'auth', true );
	$phpmailer->Username   = (string) chips_smtp_get( 'user', '' );
	$phpmailer->Password   = (string) chips_smtp_get( 'pass', '' );

	$secure = (string) chips_smtp_get( 'secure', 'tls' );
	$phpmailer->SMTPSecure = in_array( $secure, [ 'tls', 'ssl', '' ], true ) ? $secure : 'tls';

	$phpmailer->Timeout    = (int) chips_smtp_get( 'timeout', 10 );

	// Consistent From headers.
	$from_email = (string) chips_smtp_get( 'from', get_option( 'admin_email' ) );
	$from_name  = (string) chips_smtp_get( 'from_name', get_bloginfo( 'name' ) );

	try {
		$phpmailer->setFrom( $from_email, $from_name, false );
	} catch ( \Exception $e ) {
		// Ignore.
	}

	$sender = (string) chips_smtp_get( 'sender', '' );
	if ( ! empty( $sender ) ) {
		$phpmailer->Sender = $sender;
	}
} );

add_filter( 'wp_mail_from', function ( $email ) {
	$from = chips_smtp_get( 'from' );
	return ! empty( $from ) ? $from : $email;
} );

add_filter( 'wp_mail_from_name', function ( $name ) {
	$from_name = chips_smtp_get( 'from_name' );
	return ! empty( $from_name ) ? $from_name : $name;
} );

// -----------------------------
// Debug (optional)
// -----------------------------

add_action( 'wp_mail_failed', function ( $wp_error ) {
	// Only record failures when debug is enabled.
	if ( ! (bool) chips_smtp_get( 'debug', false ) ) {
		return;
	}

	if ( ! is_wp_error( $wp_error ) ) {
		return;
	}

	$payload = [
		'time'    => current_time( 'mysql' ),
		'code'    => (string) $wp_error->get_error_code(),
		'message' => (string) $wp_error->get_error_message(),
		'data'    => $wp_error->get_error_data(),
	];

	// Keep it short-lived (10 minutes) and small.
	set_transient( 'chips_smtp_last_error', $payload, 10 * MINUTE_IN_SECONDS );
}, 10, 1 );

// -----------------------------
// Admin UI (optional)
// -----------------------------

add_action( 'admin_menu', function () {
	add_options_page(
		'CHIPS SMTP Configuration',
		'CHIPS SMTP',
		'manage_options',
		'chips-smtp-configuration',
		'chips_smtp_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting(
		'chips_smtp_group',
		'chips_smtp_options',
		[ 'sanitize_callback' => 'chips_smtp_sanitize_options' ]
	);
} );

function chips_smtp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle test email action.
	$notice = null;
	$notice_type = 'updated';

	// Optional: clear the last stored error.
	if ( isset( $_GET['chips_smtp_clear_error'] ) ) {
		check_admin_referer( 'chips_smtp_clear_error' );
		delete_transient( 'chips_smtp_last_error' );
		$notice = 'Cleared the last SMTP debug error.';
		$notice_type = 'updated';
	}

	if ( isset( $_POST['chips_smtp_send_test'] ) ) {
		check_admin_referer( 'chips_smtp_send_test' );

		$to = isset( $_POST['chips_smtp_test_to'] ) ? sanitize_email( wp_unslash( $_POST['chips_smtp_test_to'] ) ) : '';
		if ( empty( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$subject = 'CHIPS SMTP Test — ' . get_bloginfo( 'name' );
		$body    = "If you're reading this, SMTP is working.\n\nSent at: " . current_time( 'mysql' );

		$ok = wp_mail( $to, $subject, $body );
		if ( $ok ) {
			$notice = 'Test email sent successfully.';
		} else {
			$notice_type = 'error';
			$notice = 'Test email failed to send. Check your SMTP credentials and server logs.';
		}
	}

	$opts = get_option( 'chips_smtp_options', [] );
	$opts = is_array( $opts ) ? $opts : [];

	$last_error = get_transient( 'chips_smtp_last_error' );
	$last_error = is_array( $last_error ) ? $last_error : null;

	// Values as displayed in the UI should reflect the *effective* config, but
	// we also show whether a constant/env overrides each value.
	$fields = [
		'host'      => 'SMTP Host',
		'port'      => 'SMTP Port',
		'secure'    => 'Encryption (tls/ssl/blank)',
		'auth'      => 'Use SMTP Authentication',
		'user'      => 'SMTP Username',
		'pass'      => 'SMTP Password (leave blank to keep existing)',
		'from'      => 'From Email',
		'from_name' => 'From Name',
		'sender'    => 'Envelope Sender (optional)',
		'timeout'   => 'Timeout (seconds)',
	];

	// Helper to detect override source
	$override = function ( string $key ): string {
		$const = 'CHIPS_SMTP_' . strtoupper( $key );
		if ( defined( $const ) ) {
			return 'wp-config constant';
		}
		$env = getenv( $const );
		if ( $env !== false ) {
			return 'environment variable';
		}
		return '';
	};

	?>
	<div class="wrap">
		<h1>CHIPS SMTP Configuration</h1>

		<?php if ( $notice ) : ?>
			<div class="notice <?php echo esc_attr( $notice_type === 'error' ? 'notice-error' : 'notice-success' ); ?> is-dismissible">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
		<?php endif; ?>

		<p>
			<strong>Recommended:</strong> Store secrets in <code>wp-config.php</code> or environment variables (so they never live in the database or repo).
			This plugin will use, in order: <code>CHIPS_SMTP_*</code> constants, then <code>CHIPS_SMTP_*</code> env vars, then these settings.
		</p>

		<?php if ( ! empty( $opts['debug'] ?? false ) && $last_error ) : ?>
			<div class="notice notice-warning" style="padding: 8px 12px;">
				<p style="margin: 0 0 8px;"><strong>Last email error (debug):</strong></p>
				<p style="margin: 0 0 6px;"><code><?php echo esc_html( $last_error['time'] ?? '' ); ?></code></p>
				<p style="margin: 0 0 6px;"><code><?php echo esc_html( $last_error['code'] ?? '' ); ?></code> — <?php echo esc_html( $last_error['message'] ?? '' ); ?></p>
				<?php if ( ! empty( $last_error['data'] ) ) : ?>
					<details>
						<summary>Details</summary>
						<pre style="white-space: pre-wrap;"><?php echo esc_html( print_r( $last_error['data'], true ) ); ?></pre>
					</details>
				<?php endif; ?>
				<p style="margin: 10px 0 0;">
					<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=chips-smtp-configuration&chips_smtp_clear_error=1' ), 'chips_smtp_clear_error' ) ); ?>">Clear error</a>
				</p>
			</div>
		<?php endif; ?>

		<h2>Quick config (wp-config.php)</h2>
		<pre style="background:#fff; border:1px solid #ccd0d4; padding:12px; overflow:auto;">
// Example (prefer this for production secrets)
// define('CHIPS_SMTP_HOST', 'smtp.example.com');
// define('CHIPS_SMTP_PORT', 587);
// define('CHIPS_SMTP_SECURE', 'tls'); // tls|ssl|''
// define('CHIPS_SMTP_AUTH', true);
// define('CHIPS_SMTP_USER', 'no-reply@example.com');
// define('CHIPS_SMTP_PASS', 'your-password');
// define('CHIPS_SMTP_FROM', 'no-reply@example.com');
// define('CHIPS_SMTP_FROM_NAME', 'Your Site');
// define('CHIPS_SMTP_SENDER', 'bounce@example.com'); // optional
// define('CHIPS_SMTP_TIMEOUT', 10);
		</pre>

		<hr />

		<form method="post" action="options.php">
			<?php settings_fields( 'chips_smtp_group' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">Status</th>
						<td>
							<?php if ( chips_smtp_is_enabled() ) : ?>
								<span style="font-weight:600; color:#1d2327;">Enabled</span>
							<?php else : ?>
								<span style="font-weight:600; color:#b32d2e;">Disabled</span>
								<p class="description">Set <code>CHIPS_SMTP_HOST</code> (constant/env/setting) to enable.</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">SMTP Host</th>
						<td>
							<input type="text" class="regular-text" name="chips_smtp_options[host]" value="<?php echo esc_attr( $opts['host'] ?? '' ); ?>" />
							<?php $o = $override('host'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">SMTP Port</th>
						<td>
							<input type="number" class="small-text" name="chips_smtp_options[port]" value="<?php echo esc_attr( $opts['port'] ?? 587 ); ?>" />
							<?php $o = $override('port'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Encryption</th>
						<td>
							<select name="chips_smtp_options[secure]">
								<?php
								$secure_val = $opts['secure'] ?? 'tls';
								foreach ( [ 'tls' => 'TLS (recommended)', 'ssl' => 'SSL', '' => 'None' ] as $val => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $val ),
										selected( $secure_val, $val, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
							<?php $o = $override('secure'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Use SMTP Authentication</th>
						<td>
							<label>
								<input type="checkbox" name="chips_smtp_options[auth]" value="1" <?php checked( ! empty( $opts['auth'] ?? true ) ); ?> />
								Enabled
							</label>
							<?php $o = $override('auth'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">SMTP Username</th>
						<td>
							<input type="text" class="regular-text" name="chips_smtp_options[user]" value="<?php echo esc_attr( $opts['user'] ?? '' ); ?>" autocomplete="username" />
							<?php $o = $override('user'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">SMTP Password</th>
						<td>
							<input type="password" class="regular-text" name="chips_smtp_options[pass]" value="" autocomplete="current-password" />
							<p class="description">
								Leave blank to keep the existing saved password. If you set <code>CHIPS_SMTP_PASS</code> in <code>wp-config.php</code> or env, that will override anything saved here.
							</p>
							<?php $o = $override('pass'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">From Email</th>
						<td>
							<input type="email" class="regular-text" name="chips_smtp_options[from]" value="<?php echo esc_attr( $opts['from'] ?? '' ); ?>" />
							<?php $o = $override('from'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">From Name</th>
						<td>
							<input type="text" class="regular-text" name="chips_smtp_options[from_name]" value="<?php echo esc_attr( $opts['from_name'] ?? '' ); ?>" />
							<?php $o = $override('from_name'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Envelope Sender (optional)</th>
						<td>
							<input type="email" class="regular-text" name="chips_smtp_options[sender]" value="<?php echo esc_attr( $opts['sender'] ?? '' ); ?>" />
							<p class="description">Sometimes helps deliverability with certain providers. Leave blank if unsure.</p>
							<?php $o = $override('sender'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Timeout (seconds)</th>
						<td>
							<input type="number" class="small-text" name="chips_smtp_options[timeout]" value="<?php echo esc_attr( $opts['timeout'] ?? 10 ); ?>" />
							<?php $o = $override('timeout'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Debug mode</th>
						<td>
							<label>
								<input type="checkbox" name="chips_smtp_options[debug]" value="1" <?php checked( ! empty( $opts['debug'] ?? false ) ); ?> />
								Record last email send error (stores a short-lived transient)
							</label>
							<p class="description">Useful during setup. Leave off in steady-state unless you’re troubleshooting.</p>
							<?php $o = $override('debug'); if ( $o ) : ?><p class="description">Overridden by <?php echo esc_html( $o ); ?>.</p><?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( 'Save SMTP Settings' ); ?>
		</form>

		<hr />

		<h2>Send a test email</h2>
		<form method="post">
			<?php wp_nonce_field( 'chips_smtp_send_test' ); ?>
			<p>
				<label for="chips_smtp_test_to"><strong>Send to:</strong></label>
				<input id="chips_smtp_test_to" type="email" name="chips_smtp_test_to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text" />
				<button type="submit" name="chips_smtp_send_test" class="button button-secondary">Send Test Email</button>
			</p>
			<p class="description">If this fails, check your SMTP provider logs and your server error logs. This plugin intentionally keeps logging minimal.</p>
		</form>

	</div>
	<?php
}

// -----------------------------
// Make the stored option non-autoloading (minor perf hygiene)
// -----------------------------

register_activation_hook( __FILE__, function () {
	// Create option if missing, with autoload = no.
	if ( get_option( 'chips_smtp_options', null ) === null ) {
		add_option( 'chips_smtp_options', [], '', 'no' );
	}
} );