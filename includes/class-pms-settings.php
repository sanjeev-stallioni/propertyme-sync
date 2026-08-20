<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page: Settings → PropertyMe Sync.
 *
 * Credentials live here (options table), never in code. The client secret is
 * encrypted before saving and is never rendered back into the form — the
 * field stays blank and only overwrites the stored value when filled in.
 */
class PMS_Settings {

	const PAGE = 'propertyme-sync';

	/** The scopes PropertyMe documents for this integration. */
	const SCOPES = array(
		'activity:read'      => 'Activity (read)',
		'communication:read' => 'Communication (read)',
		'contact:read'       => 'Contact (read)',
		'property:read'      => 'Property (read)',
		'transaction:read'   => 'Transaction (read)',
		'offline_access'     => 'Offline access — refresh token for unattended syncs',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_pms_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_pms_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_pms_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'admin_post_pms_import_reaxml', array( __CLASS__, 'handle_import_reaxml' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		// Re-apply the cron schedule whenever settings are first saved or changed.
		add_action( 'add_option_pms_settings', array( 'PMS_Sync', 'reschedule' ) );
		add_action( 'update_option_pms_settings', array( 'PMS_Sync', 'reschedule' ) );
	}

	public static function menu() {
		add_options_page( 'PropertyMe Sync', 'PropertyMe Sync', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( 'pms_settings_group', 'pms_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = get_option( 'pms_settings', array() );
		$old   = is_array( $old ) ? $old : array();

		$out = array(
			'client_id'          => sanitize_text_field( $input['client_id'] ?? '' ),
			'authorize_endpoint' => esc_url_raw( $input['authorize_endpoint'] ?? '' ),
			'token_endpoint'     => esc_url_raw( $input['token_endpoint'] ?? '' ),
			'api_base'           => untrailingslashit( esc_url_raw( $input['api_base'] ?? '' ) ),
			'scope'              => self::sanitize_scope( $input['scope'] ?? array() ),
			'redirect_uri'       => esc_url_raw( $input['redirect_uri'] ?? home_url( '/home/callback' ) ),
			'sync_interval'      => in_array( $input['sync_interval'] ?? '', array( 'pms_6h', 'pms_8h', 'pms_12h' ), true ) ? $input['sync_interval'] : 'pms_12h',
			'sync_enabled'       => empty( $input['sync_enabled'] ) ? 0 : 1,
		);

		// Blank secret field means "keep the stored one"; a value is encrypted.
		// PropertyMe's identity server rejects secrets longer than 100 chars
		// with 400 invalid_request (a typical PDF copy/paste artifact), so
		// refuse to store an over-length value.
		$secret = trim( (string) ( $input['client_secret'] ?? '' ) );
		if ( strlen( $secret ) > 100 ) {
			add_settings_error(
				'pms_settings',
				'pms_secret_too_long',
				sprintf( 'Client Secret not saved: it is %d characters, but PropertyMe accepts at most 100. The pasted value likely includes extra characters (e.g. a joined line from the PDF) — please re-copy it.', strlen( $secret ) )
			);
			$secret = '';
		}
		$out['client_secret'] = '' !== $secret ? PMS_Crypto::encrypt( $secret ) : ( $old['client_secret'] ?? '' );

		return $out;
	}

	/**
	 * Checkbox array → validated space-delimited scope string. Accepts a
	 * legacy string too (normalising "offline access" typos). Falls back to
	 * all documented scopes when nothing valid remains.
	 */
	private static function sanitize_scope( $scope ) {
		if ( is_string( $scope ) ) {
			$scope = preg_split( '/\s+/', str_replace( 'offline access', 'offline_access', $scope ) );
		}
		$chosen = array_values( array_intersect( array_keys( self::SCOPES ), array_map( 'sanitize_text_field', (array) $scope ) ) );
		if ( ! $chosen ) {
			$chosen = array_keys( self::SCOPES );
		}
		return implode( ' ', $chosen );
	}

	/* ---------- Action handlers ---------- */

	public static function handle_connect() {
		current_user_can( 'manage_options' ) || wp_die( 'Forbidden' );
		check_admin_referer( 'pms_connect' );
		$s = pms_get_settings();
		if ( '' === $s['client_id'] || '' === $s['client_secret'] ) {
			wp_safe_redirect( add_query_arg( 'pms_status', 'missing_credentials', self::url() ) );
			exit;
		}
		wp_redirect( PMS_OAuth::authorize_url() ); // external Identity Service
		exit;
	}

	public static function handle_disconnect() {
		current_user_can( 'manage_options' ) || wp_die( 'Forbidden' );
		check_admin_referer( 'pms_disconnect' );
		PMS_OAuth::disconnect();
		wp_safe_redirect( add_query_arg( 'pms_status', 'disconnected', self::url() ) );
		exit;
	}

	public static function handle_sync_now() {
		current_user_can( 'manage_options' ) || wp_die( 'Forbidden' );
		check_admin_referer( 'pms_sync_now' );
		PMS_Sync::run();
		wp_safe_redirect( add_query_arg( 'pms_status', 'sync_done', self::url() ) );
		exit;
	}

	public static function handle_import_reaxml() {
		current_user_can( 'manage_options' ) || wp_die( 'Forbidden' );
		check_admin_referer( 'pms_import_reaxml' );
		PMS_REAXML::import_dir();
		wp_safe_redirect( add_query_arg( 'pms_status', 'reaxml_done', self::url() ) );
		exit;
	}

	/* ---------- Rendering ---------- */

	private static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public static function notices() {
		if ( empty( $_GET['pms_status'] ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		$map = array(
			'connected'             => array( 'success', 'Connected to PropertyMe.' ),
			'disconnected'          => array( 'info', 'Disconnected from PropertyMe.' ),
			'sync_done'             => array( 'success', 'Sync run finished — see the log below.' ),
			'reaxml_done'           => array( 'success', 'REA XML import finished — see the log below.' ),
			'missing_credentials'   => array( 'error', 'Enter the Client ID and Client Secret first.' ),
			'oauth_error'           => array( 'error', 'PropertyMe returned an OAuth error — see the log below.' ),
			'oauth_missing_code'    => array( 'error', 'OAuth callback did not include a code.' ),
			'oauth_state_mismatch'  => array( 'error', 'OAuth state check failed — please try connecting again.' ),
			'oauth_exchange_failed' => array( 'error', 'Exchanging the code for tokens failed — see the log below.' ),
		);
		$status = sanitize_key( wp_unslash( $_GET['pms_status'] ) );
		if ( isset( $map[ $status ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $status ][0] ), esc_html( $map[ $status ][1] ) );
		}
	}

	public static function render() {
		current_user_can( 'manage_options' ) || wp_die( 'Forbidden' );
		$s          = pms_get_settings();
		$connected  = PMS_OAuth::is_connected();
		$tokens     = PMS_OAuth::tokens();
		$last_sync  = get_option( 'pms_last_sync', '' );
		$next_cron  = wp_next_scheduled( PMS_Sync::CRON_HOOK );
		$has_secret = '' !== $s['client_secret'];
		?>
		<div class="wrap">
			<h1>PropertyMe Sync</h1>

			<h2 class="title">Status</h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr><td><strong>Connection</strong></td>
						<td><?php echo $connected ? '<span style="color:green">&#9679;</span> Connected' : '<span style="color:#d63638">&#9679;</span> Not connected'; ?></td></tr>
					<tr><td><strong>Access token expires</strong></td>
						<td><?php echo ! empty( $tokens['expires_at'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', $tokens['expires_at'] ) ) : '&mdash;'; ?></td></tr>
					<tr><td><strong>Properties in sync table</strong></td>
						<td><?php echo esc_html( PMS_DB::count() ); ?></td></tr>
					<tr><td><strong>Last sync</strong></td>
						<td><?php echo $last_sync ? esc_html( $last_sync ) : 'Never'; ?></td></tr>
					<tr><td><strong>Next scheduled sync</strong></td>
						<td><?php echo $next_cron ? esc_html( wp_date( 'Y-m-d H:i:s', $next_cron ) ) : 'Not scheduled'; ?></td></tr>
				</tbody>
			</table>

			<p style="margin-top:12px">
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pms_connect' ), 'pms_connect' ) ); ?>">
					<?php echo $connected ? 'Re-connect to PropertyMe' : 'Connect to PropertyMe'; ?>
				</a>
				<?php if ( $connected ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pms_sync_now' ), 'pms_sync_now' ) ); ?>">Sync now</a>
					<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pms_disconnect' ), 'pms_disconnect' ) ); ?>">Disconnect</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pms_import_reaxml' ), 'pms_import_reaxml' ) ); ?>">Import REA XML now</a>
			</p>
			<p class="description">REA XML drop directory: <code><?php echo esc_html( PMS_REAXML::feed_dir() ); ?></code> — files are archived to <code>processed/</code> after import. Photos referenced by <code>file=</code> must sit next to the XML.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'pms_settings_group' ); ?>

				<h2 class="title">API credentials</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pms_client_id">Client ID</label></th>
						<td><input name="pms_settings[client_id]" id="pms_client_id" type="text" class="regular-text code" value="<?php echo esc_attr( $s['client_id'] ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pms_client_secret">Client Secret</label></th>
						<td>
							<input name="pms_settings[client_secret]" id="pms_client_secret" type="password" class="regular-text code" value="" autocomplete="new-password"
								placeholder="<?php echo $has_secret ? '•••••••• (saved — leave blank to keep)' : 'Enter client secret'; ?>">
							<p class="description">Stored encrypted in the database; never shown again after saving.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">OAuth &amp; API endpoints</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pms_auth_ep">Authorize endpoint</label></th>
						<td><input name="pms_settings[authorize_endpoint]" id="pms_auth_ep" type="url" class="large-text code" value="<?php echo esc_attr( $s['authorize_endpoint'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pms_token_ep">Token endpoint</label></th>
						<td><input name="pms_settings[token_endpoint]" id="pms_token_ep" type="url" class="large-text code" value="<?php echo esc_attr( $s['token_endpoint'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pms_api_base">API base URL</label></th>
						<td><input name="pms_settings[api_base]" id="pms_api_base" type="url" class="large-text code" value="<?php echo esc_attr( $s['api_base'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Scopes</th>
						<td>
							<fieldset>
							<?php $active_scopes = preg_split( '/\s+/', (string) $s['scope'] ); ?>
							<?php foreach ( self::SCOPES as $scope_value => $scope_label ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="pms_settings[scope][]" value="<?php echo esc_attr( $scope_value ); ?>" <?php checked( in_array( $scope_value, $active_scopes, true ) ); ?>>
									<code><?php echo esc_html( $scope_value ); ?></code> &mdash; <?php echo esc_html( $scope_label ); ?>
								</label>
							<?php endforeach; ?>
							</fieldset>
							<p class="description"><code>offline_access</code> is required for the refresh token that keeps unattended cron syncs working.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pms_redirect">Redirect URI</label></th>
						<td>
							<input name="pms_settings[redirect_uri]" id="pms_redirect" type="url" class="large-text code" value="<?php echo esc_attr( $s['redirect_uri'] ); ?>">
							<p class="description">Must exactly match the redirect URL registered with PropertyMe.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Synchronisation</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Scheduled sync</th>
						<td><label><input name="pms_settings[sync_enabled]" type="checkbox" value="1" <?php checked( $s['sync_enabled'] ); ?>> Enable automatic sync via cron</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pms_interval">Interval</label></th>
						<td>
							<select name="pms_settings[sync_interval]" id="pms_interval">
								<option value="pms_6h" <?php selected( $s['sync_interval'], 'pms_6h' ); ?>>Every 6 hours</option>
								<option value="pms_8h" <?php selected( $s['sync_interval'], 'pms_8h' ); ?>>Every 8 hours</option>
								<option value="pms_12h" <?php selected( $s['sync_interval'], 'pms_12h' ); ?>>Every 12 hours</option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h2 class="title">Recent log</h2>
			<table class="widefat striped">
				<thead><tr><th style="width:160px">Time</th><th style="width:70px">Level</th><th>Message</th></tr></thead>
				<tbody>
				<?php $rows = PMS_Logger::tail( 30 ); ?>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="3">No log entries yet.</td></tr>
				<?php else : foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['time'] ); ?></td>
						<td><?php echo 'error' === $row['level'] ? '<span style="color:#d63638">error</span>' : 'info'; ?></td>
						<td>
							<?php echo esc_html( $row['message'] ); ?>
							<?php if ( ! empty( $row['context'] ) ) : ?>
								<details><summary>context</summary><pre style="white-space:pre-wrap"><?php echo esc_html( $row['context'] ); ?></pre></details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
