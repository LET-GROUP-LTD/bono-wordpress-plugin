<?php
/**
 * Admin settings for Bono Leads Connector.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_Settings {
	const OPTION_KEY = 'bono_leads_connector_settings';

	/**
	 * Envelope prefix marking a value as encrypted at rest.
	 */
	const SECRET_PREFIX = 'bono:enc:v1:';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_bono_connect_site', array( $this, 'handle_connect_site' ) );
		add_action( 'admin_post_bono_save_field_mappings', array( $this, 'handle_save_field_mappings' ) );
		add_action( 'admin_post_bono_test_api_connection', array( $this, 'handle_test_api_connection' ) );
		add_action( 'admin_post_bono_retry_failed_submissions', array( $this, 'handle_retry_failed_submissions' ) );
		add_action( 'admin_post_bono_process_queue_now', array( $this, 'handle_process_queue_now' ) );
		add_action( 'admin_post_bono_delete_sent_queue_rows', array( $this, 'handle_delete_sent_queue_rows' ) );
		add_action( 'admin_post_bono_delete_failed_queue_rows', array( $this, 'handle_delete_failed_queue_rows' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'api_base_url'              => '',
			'api_key'                   => '',
			'site_id'                   => '',
			'enable_debug_log'          => false,
			'generic_capture_selectors' => '',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, self::get_defaults() );

		// Decrypt the API key transparently so all consumers receive plaintext.
		// Legacy plaintext values (no envelope prefix) pass through unchanged and
		// are re-encrypted on the next save.
		if ( isset( $settings['api_key'] ) ) {
			$settings['api_key'] = self::decrypt_secret( $settings['api_key'] );
		}

		return $settings;
	}

	/**
	 * Whether authenticated encryption is available for secret storage.
	 *
	 * @return bool
	 */
	private static function is_encryption_available() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'hash_hkdf' )
			&& function_exists( 'random_bytes' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Derive a 32-byte encryption key from the site's wp-config salts.
	 *
	 * The key material lives in wp-config (or WordPress-generated salts), never
	 * in the database, so a database dump alone does not expose stored secrets.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function get_encryption_key() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : '';

		return hash_hkdf( 'sha256', $salt, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'bono-leads-connector:apikey:v1' );
	}

	/**
	 * Encrypt a secret for storage at rest.
	 *
	 * Returns an empty string unchanged. When encryption is unavailable the
	 * plaintext is returned as-is (graceful degradation, never lose the secret).
	 *
	 * @param string $plaintext Secret value.
	 * @return string Encrypted envelope or original value.
	 */
	public static function encrypt_secret( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext || ! self::is_encryption_available() ) {
			return $plaintext;
		}

		// Encryption is best-effort: on some hosts libsodium / hash_hkdf can throw
		// at runtime (PHP build quirks). Connecting must never fatal over this, so
		// fall back to plaintext storage (the documented MVP behavior).
		try {
			$key    = self::get_encryption_key();
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}

			return self::SECRET_PREFIX . base64_encode( $nonce . $cipher );
		} catch ( \Throwable $e ) {
			return $plaintext;
		}
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * Values without the envelope prefix are treated as legacy plaintext and
	 * returned unchanged. A failed decryption (tampering or rotated salts)
	 * yields an empty string so the admin is prompted to re-enter the key.
	 *
	 * @param string $stored Stored value.
	 * @return string Plaintext secret.
	 */
	public static function decrypt_secret( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored || 0 !== strpos( $stored, self::SECRET_PREFIX ) ) {
			return $stored;
		}

		if ( ! self::is_encryption_available() ) {
			return '';
		}

		try {
			$raw = base64_decode( substr( $stored, strlen( self::SECRET_PREFIX ) ), true );

			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce     = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key       = self::get_encryption_key();
			$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}

			return false === $plaintext ? '' : $plaintext;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Resolve the effective Bono API base URL for the connect flow.
	 *
	 * Priority: wp-config constant BONO_API_BASE_URL > saved setting >
	 * the plugin's BONO_DEFAULT_API_BASE_URL build-time default.
	 *
	 * @return string
	 */
	public static function get_effective_api_base_url() {
		if ( defined( 'BONO_API_BASE_URL' ) && '' !== (string) BONO_API_BASE_URL ) {
			return esc_url_raw( trim( (string) BONO_API_BASE_URL ) );
		}

		$settings = self::get_settings();

		if ( ! empty( $settings['api_base_url'] ) ) {
			return esc_url_raw( trim( (string) $settings['api_base_url'] ) );
		}

		if ( defined( 'BONO_DEFAULT_API_BASE_URL' ) && '' !== (string) BONO_DEFAULT_API_BASE_URL ) {
			return esc_url_raw( trim( (string) BONO_DEFAULT_API_BASE_URL ) );
		}

		return '';
	}

	/**
	 * Determine whether an API base URL is allowed for outbound requests.
	 *
	 * @param string $api_base_url API base URL.
	 * @return bool
	 */
	public static function is_allowed_api_base_url( $api_base_url ) {
		$api_base_url = esc_url_raw( trim( (string) $api_base_url ) );

		if ( '' === $api_base_url ) {
			return false;
		}

		$parts = wp_parse_url( $api_base_url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );

		if ( 'https' === $scheme ) {
			return true;
		}

		if ( 'http' !== $scheme ) {
			return false;
		}

		return in_array(
			$host,
			array( 'localhost', '127.0.0.1', 'host.docker.internal' ),
			true
		);
	}

	/**
	 * Add settings page under Settings.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Bono Leads Connector', 'bono-leads-connector' ),
			__( 'Bono Leads', 'bono-leads-connector' ),
			'manage_options',
			'bono-leads-connector',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'bono_leads_connector',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'bono_leads_connector_api',
			__( 'API Settings', 'bono-leads-connector' ),
			'__return_false',
			'bono-leads-connector'
		);

		add_settings_field(
			'api_base_url',
			__( 'API Base URL', 'bono-leads-connector' ),
			array( $this, 'render_api_base_url_field' ),
			'bono-leads-connector',
			'bono_leads_connector_api'
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'bono-leads-connector' ),
			array( $this, 'render_api_key_field' ),
			'bono-leads-connector',
			'bono_leads_connector_api'
		);

		add_settings_field(
			'site_id',
			__( 'Site ID', 'bono-leads-connector' ),
			array( $this, 'render_site_id_field' ),
			'bono-leads-connector',
			'bono_leads_connector_api'
		);

		add_settings_field(
			'enable_debug_log',
			__( 'Debug Logging', 'bono-leads-connector' ),
			array( $this, 'render_enable_debug_log_field' ),
			'bono-leads-connector',
			'bono_leads_connector_api'
		);

		add_settings_field(
			'generic_capture_selectors',
			__( 'Generic Form Capture', 'bono-leads-connector' ),
			array( $this, 'render_generic_capture_selectors_field' ),
			'bono-leads-connector',
			'bono_leads_connector_api'
		);
	}

	/**
	 * Sanitize settings before storage.
	 *
	 * @param array $input Submitted settings.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input        = is_array( $input ) ? $input : array();
		$existing     = self::get_settings();
		$api_base_url = isset( $input['api_base_url'] ) && is_scalar( $input['api_base_url'] )
			? esc_url_raw( trim( (string) $input['api_base_url'] ) )
			: '';
		$api_key      = isset( $input['api_key'] ) && is_scalar( $input['api_key'] )
			? sanitize_text_field( (string) $input['api_key'] )
			: '';
		$site_id      = isset( $input['site_id'] ) && is_scalar( $input['site_id'] )
			? sanitize_text_field( (string) $input['site_id'] )
			: '';

		if ( '' !== $api_base_url && ! self::is_allowed_api_base_url( $api_base_url ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'bono_insecure_api_base_url',
					__( 'API Base URL must use https://, except http://localhost, http://127.0.0.1, or http://host.docker.internal for local development.', 'bono-leads-connector' ),
					'error'
				);
			}

			$api_base_url = ! empty( $existing['api_base_url'] ) && self::is_allowed_api_base_url( $existing['api_base_url'] )
				? $existing['api_base_url']
				: '';
		}

		// $existing['api_key'] is already decrypted by get_settings(); an empty
		// submission preserves the current key. Encrypt at rest before storage.
		$resolved_api_key = '' !== $api_key ? $api_key : ( isset( $existing['api_key'] ) ? $existing['api_key'] : '' );

		$generic_capture_selectors = isset( $input['generic_capture_selectors'] ) && is_scalar( $input['generic_capture_selectors'] )
			? sanitize_textarea_field( (string) $input['generic_capture_selectors'] )
			: '';

		return array(
			'api_base_url'              => $api_base_url,
			'api_key'                   => self::encrypt_secret( $resolved_api_key ),
			'site_id'                   => $site_id,
			'enable_debug_log'          => ! empty( $input['enable_debug_log'] ),
			'generic_capture_selectors' => $generic_capture_selectors,
		);
	}

	/**
	 * Render API base URL field.
	 *
	 * @return void
	 */
	public function render_api_base_url_field() {
		$settings = self::get_settings();
		?>
		<input
			type="url"
			class="regular-text"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base_url]"
			value="<?php echo esc_attr( $settings['api_base_url'] ); ?>"
			placeholder="<?php echo esc_attr__( 'https://api.example.com', 'bono-leads-connector' ); ?>"
		/>
		<?php
	}

	/**
	 * Render API key field.
	 *
	 * @return void
	 */
	public function render_api_key_field() {
		$settings    = self::get_settings();
		$has_api_key = ! empty( $settings['api_key'] );
		?>
		<input
			type="password"
			class="regular-text"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
			value=""
			placeholder="<?php echo esc_attr( $has_api_key ? '••••••••••••' : '' ); ?>"
			autocomplete="off"
		/>
		<?php if ( $has_api_key ) : ?>
			<p class="description"><?php esc_html_e( 'Leave blank to keep the existing API key.', 'bono-leads-connector' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render site ID field.
	 *
	 * @return void
	 */
	public function render_site_id_field() {
		$settings = self::get_settings();
		?>
		<input
			type="text"
			class="regular-text"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[site_id]"
			value="<?php echo esc_attr( $settings['site_id'] ); ?>"
		/>
		<?php
	}

	/**
	 * Render debug logging field.
	 *
	 * @return void
	 */
	public function render_enable_debug_log_field() {
		$settings = self::get_settings();
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_debug_log]"
				value="1"
				<?php checked( ! empty( $settings['enable_debug_log'] ) ); ?>
			/>
			<?php esc_html_e( 'Log failed Bono submission attempts to the PHP error log.', 'bono-leads-connector' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the generic capture selectors field.
	 *
	 * @return void
	 */
	public function render_generic_capture_selectors_field() {
		$settings = self::get_settings();
		$value    = isset( $settings['generic_capture_selectors'] ) ? (string) $settings['generic_capture_selectors'] : '';
		?>
		<textarea
			class="large-text code"
			rows="3"
			dir="ltr"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[generic_capture_selectors]"
			placeholder="#contact-form, .lead-form"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Optional. CSS selectors (comma or newline separated) for custom/theme forms to capture in addition to the supported form plugins. Forms with a data-bono-capture attribute are always captured. Leave blank to disable generic capture.', 'bono-leads-connector' ); ?>
		</p>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$queue_counts        = $this->get_queue_counts();
		$queue_latest_failed = $this->get_queue_latest_failed();
		$settings_page       = BONO_PLUGIN_PATH . 'admin/settings-page.php';

		if ( file_exists( $settings_page ) ) {
			require $settings_page;
		}
	}

	/**
	 * Handle the "Connect to Bono" admin action: exchange a provisioning token
	 * for a site_id + API key and persist them (API key encrypted at rest).
	 *
	 * @return void
	 */
	public function handle_connect_site() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect this site.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_connect_site' );

		if ( ! class_exists( 'Bono_API_Client' ) ) {
			$this->redirect_after_connect( false, __( 'Bono API client is unavailable.', 'bono-leads-connector' ) );
		}

		$token = isset( $_POST['bono_provisioning_token'] )
			? sanitize_text_field( wp_unslash( $_POST['bono_provisioning_token'] ) )
			: '';

		if ( '' === $token ) {
			$this->redirect_after_connect( false, __( 'Please paste a provisioning token from Bono.', 'bono-leads-connector' ) );
		}

		$api_base_url = self::get_effective_api_base_url();

		if ( '' === $api_base_url ) {
			$this->redirect_after_connect( false, __( 'Set the API Base URL before connecting.', 'bono-leads-connector' ) );
		}

		$client = new Bono_API_Client();
		$result = $client->register_site( $token, $api_base_url );

		if ( empty( $result['success'] ) ) {
			$message = ! empty( $result['error'] )
				? sprintf(
					/* translators: %s: error message from Bono. */
					__( 'Connection failed: %s', 'bono-leads-connector' ),
					sanitize_text_field( $result['error'] )
				)
				: __( 'Connection failed.', 'bono-leads-connector' );
			$this->redirect_after_connect( false, $message );
		}

		// Persist the issued credentials. The API key is encrypted at rest.
		// Wrapped so a server-side failure surfaces as a clean message instead of
		// a fatal/white screen (the provisioning token has already been consumed).
		try {
			$settings                 = self::get_settings();
			$settings['api_base_url'] = $api_base_url;
			$settings['site_id']      = sanitize_text_field( (string) $result['site_id'] );
			$settings['api_key']      = self::encrypt_secret( (string) $result['api_key'] );
			update_option( self::OPTION_KEY, $settings );
		} catch ( \Throwable $e ) {
			$this->redirect_after_connect( false, __( 'Connected to Bono, but saving the credentials failed on this server. Generate a new token and try again, or configure manually.', 'bono-leads-connector' ) );
		}

		$this->redirect_after_connect( true, __( 'Connected to Bono successfully. Leads from this site will now be delivered.', 'bono-leads-connector' ) );
	}

	/**
	 * Redirect back to the settings page with a connect result notice.
	 *
	 * @param bool   $success Whether the connection succeeded.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function redirect_after_connect( $success, $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'                 => 'bono-leads-connector',
				'bono_connect_status'  => $success ? 'success' : 'error',
				'bono_connect_message' => (string) $message,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle saving per-form contact field mappings.
	 *
	 * @return void
	 */
	public function handle_save_field_mappings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save field mappings.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_save_field_mappings' );

		// Nonce verified above; every key and value is sanitized inside
		// Bono_Field_Mapping::save_mappings(), which PHPCS cannot follow.
		$raw = isset( $_POST['bono_field_mappings'] ) && is_array( $_POST['bono_field_mappings'] )
			? wp_unslash( $_POST['bono_field_mappings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_mappings()
			: array();

		if ( class_exists( 'Bono_Field_Mapping' ) ) {
			Bono_Field_Mapping::save_mappings( $raw );
		}

		$redirect_url = add_query_arg(
			array(
				'page'                 => 'bono-leads-connector',
				'bono_mapping_status'  => 'success',
				'bono_mapping_message' => __( 'Field mappings saved.', 'bono-leads-connector' ),
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle the Test API Connection admin action.
	 *
	 * @return void
	 */
	public function handle_test_api_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to test this connection.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_test_api_connection' );

		if ( ! class_exists( 'Bono_API_Client' ) ) {
			$this->redirect_after_test( false, null, __( 'Bono API client is unavailable.', 'bono-leads-connector' ) );
		}

		$client  = new Bono_API_Client();
		$result  = $client->test_connection();
		$success = ! empty( $result['success'] ) && 200 === (int) $result['status_code'];
		$message = $success
			? __( 'Bono API connection succeeded.', 'bono-leads-connector' )
			: $this->get_test_error_message( $result );

		$this->redirect_after_test( $success, isset( $result['status_code'] ) ? $result['status_code'] : null, $message );
	}

	/**
	 * Retry failed queued submissions.
	 *
	 * @return void
	 */
	public function handle_retry_failed_submissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to retry failed submissions.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_retry_failed_submissions' );

		if ( ! class_exists( 'Bono_Submission_Queue' ) || ! class_exists( 'Bono_API_Client' ) ) {
			$this->redirect_after_queue_action( 'error', __( 'Bono queue service is unavailable.', 'bono-leads-connector' ) );
		}

		$queue   = new Bono_Submission_Queue( new Bono_API_Client() );
		$updated = $queue->retry_failed();
		$message = sprintf(
			/* translators: %d: number of submissions moved to retrying. */
			__( 'Marked %d failed submissions for retry.', 'bono-leads-connector' ),
			(int) $updated
		);

		$this->redirect_after_queue_action( 'success', $message );
	}

	/**
	 * Process queued submissions once.
	 *
	 * @return void
	 */
	public function handle_process_queue_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to process the queue.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_process_queue_now' );

		if ( ! class_exists( 'Bono_Submission_Queue' ) || ! class_exists( 'Bono_API_Client' ) ) {
			$this->redirect_after_queue_action( 'error', __( 'Bono queue service is unavailable.', 'bono-leads-connector' ) );
		}

		$queue     = new Bono_Submission_Queue( new Bono_API_Client() );
		$processed = $queue->process_queue();
		$message   = sprintf(
			/* translators: %d: number of queued rows processed. */
			__( 'Processed %d queued submissions.', 'bono-leads-connector' ),
			(int) $processed
		);

		$this->redirect_after_queue_action( 'success', $message );
	}

	/**
	 * Delete sent queue rows.
	 *
	 * @return void
	 */
	public function handle_delete_sent_queue_rows() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete sent queue rows.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_delete_sent_queue_rows' );

		if ( ! class_exists( 'Bono_Submission_Queue' ) || ! class_exists( 'Bono_API_Client' ) ) {
			$this->redirect_after_queue_action( 'error', __( 'Bono queue service is unavailable.', 'bono-leads-connector' ) );
		}

		$queue   = new Bono_Submission_Queue( new Bono_API_Client() );
		$deleted = $queue->delete_sent();
		$message = sprintf(
			/* translators: %d: deleted row count. */
			__( 'Deleted %d sent queue rows.', 'bono-leads-connector' ),
			(int) $deleted
		);

		$this->redirect_after_queue_action( 'success', $message );
	}

	/**
	 * Delete failed queue rows.
	 *
	 * @return void
	 */
	public function handle_delete_failed_queue_rows() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete failed queue rows.', 'bono-leads-connector' ) );
		}

		check_admin_referer( 'bono_delete_failed_queue_rows' );

		if ( ! class_exists( 'Bono_Submission_Queue' ) || ! class_exists( 'Bono_API_Client' ) ) {
			$this->redirect_after_queue_action( 'error', __( 'Bono queue service is unavailable.', 'bono-leads-connector' ) );
		}

		$queue   = new Bono_Submission_Queue( new Bono_API_Client() );
		$deleted = $queue->delete_failed();
		$message = sprintf(
			/* translators: %d: deleted row count. */
			__( 'Deleted %d failed queue rows.', 'bono-leads-connector' ),
			(int) $deleted
		);

		$this->redirect_after_queue_action( 'success', $message );
	}

	/**
	 * Build a user-safe test error message.
	 *
	 * @param array $result API result.
	 * @return string
	 */
	private function get_test_error_message( array $result ) {
		if ( ! empty( $result['error'] ) ) {
			return sprintf(
				/* translators: %s: API error message. */
				__( 'Bono API connection failed: %s', 'bono-leads-connector' ),
				sanitize_text_field( $result['error'] )
			);
		}

		return __( 'Bono API connection failed.', 'bono-leads-connector' );
	}

	/**
	 * Redirect back to the settings page with a test result notice.
	 *
	 * @param bool        $success Whether the test succeeded.
	 * @param int|null    $status_code HTTP status code.
	 * @param string|null $message Notice message.
	 * @return void
	 */
	private function redirect_after_test( $success, $status_code, $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'              => 'bono-leads-connector',
				'bono_test_status'  => $success ? 'success' : 'error',
				'bono_test_code'    => is_null( $status_code ) ? '' : (string) (int) $status_code,
				'bono_test_message' => (string) $message,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Get queue counters for admin visibility.
	 *
	 * @return array
	 */
	private function get_queue_counts() {
		$defaults = array(
			'pending'            => 0,
			'retrying'           => 0,
			'sent'               => 0,
			'failed'             => 0,
			'latest_failed_at'   => '',
			'oldest_pending_age' => null,
			'health'             => array(
				'state'       => 'healthy',
				'label'       => __( 'Healthy', 'bono-leads-connector' ),
				'description' => __( 'No failed submissions and pending queue is under 10.', 'bono-leads-connector' ),
			),
		);

		if ( ! class_exists( 'Bono_Submission_Queue' ) || ! class_exists( 'Bono_API_Client' ) ) {
			return $defaults;
		}

		$queue  = new Bono_Submission_Queue( new Bono_API_Client() );
		$counts = $queue->get_counts();

		return wp_parse_args( is_array( $counts ) ? $counts : array(), $defaults );
	}

	/**
	 * Get latest failed queue rows for admin visibility.
	 *
	 * @return array
	 */
	private function get_queue_latest_failed() {
		if ( ! class_exists( 'Bono_Submission_Queue' ) || ! class_exists( 'Bono_API_Client' ) ) {
			return array();
		}

		$queue = new Bono_Submission_Queue( new Bono_API_Client() );

		return $queue->get_latest_failed( 5 );
	}

	/**
	 * Redirect back to settings page with queue action notice.
	 *
	 * @param string $status  success or error.
	 * @param string $message User-facing message.
	 * @return void
	 */
	private function redirect_after_queue_action( $status, $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'               => 'bono-leads-connector',
				'bono_queue_status'  => 'success' === $status ? 'success' : 'error',
				'bono_queue_message' => (string) $message,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
