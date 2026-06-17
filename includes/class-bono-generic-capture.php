<?php
/**
 * Generic opt-in capture for arbitrary site forms.
 *
 * A small frontend script listens (non-blocking) on the submit event of forms
 * the admin opted in — via CSS selectors in settings or a `data-bono-capture`
 * attribute — and forwards the fields to a plugin REST endpoint. The endpoint
 * runs the same server-side pipeline (validation, dedup, rate limit, queue),
 * so the Bono API key never reaches the browser.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_Generic_Capture extends Bono_Form_Capture {
	const NONCE_ACTION   = 'bono_generic_capture';
	const REST_NAMESPACE = 'bono/v1';
	const REST_ROUTE     = '/capture';
	const SCRIPT_HANDLE  = 'bono-generic-capture';

	/**
	 * Register frontend script + REST endpoint.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Enqueue the capture script and pass it the opt-in selectors + nonce.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$selectors = $this->get_selectors();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			BONO_PLUGIN_URL . 'assets/js/bono-generic-capture.js',
			array(),
			defined( 'BONO_PLUGIN_VERSION' ) ? BONO_PLUGIN_VERSION : false,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'bonoGenericCapture',
			array(
				'restUrl'   => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
				'token'     => $this->mint_token(),
				'selectors' => $selectors,
			)
		);
	}

	/**
	 * TTL (seconds) for a capture token. Long enough to survive full-page caching
	 * (LiteSpeed/Cloudflare), short enough to bound replay. Overridable via constant.
	 *
	 * @return int
	 */
	private function token_ttl() {
		if ( defined( 'BONO_GENERIC_TOKEN_TTL' ) && (int) BONO_GENERIC_TOKEN_TTL > 0 ) {
			return (int) BONO_GENERIC_TOKEN_TTL;
		}

		return 7 * ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
	}

	/**
	 * Per-site signing secret for capture tokens, derived from a WP salt (stable,
	 * no DB write, not session-bound). Falls back gracefully if salts are absent.
	 *
	 * @return string
	 */
	private function token_secret() {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';

		return hash_hmac( 'sha256', 'bono-generic-capture-token', $salt );
	}

	/**
	 * Mint a cache-safe, session-independent capture token: `<expiryEpoch>.<hmac>`.
	 * Unlike a WP nonce it verifies for logged-in AND logged-out visitors and on
	 * cached pages (until expiry), so generic capture never silently drops a lead.
	 *
	 * @param int|null $ttl Optional TTL override (seconds).
	 * @return string
	 */
	public function mint_token( $ttl = null ) {
		$expiry = time() + ( null === $ttl ? $this->token_ttl() : (int) $ttl );
		$sig    = hash_hmac( 'sha256', (string) $expiry, $this->token_secret() );

		return $expiry . '.' . $sig;
	}

	/**
	 * Verify a capture token: signature matches (constant-time) and not expired.
	 *
	 * @param mixed $token Token string.
	 * @return bool
	 */
	public function verify_token( $token ) {
		if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
			return false;
		}

		list( $expiry, $sig ) = explode( '.', $token, 2 );

		if ( '' === $expiry || ! ctype_digit( $expiry ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', (string) (int) $expiry, $this->token_secret() );

		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return false;
		}

		return time() <= (int) $expiry;
	}

	/**
	 * Register the public capture REST route.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_capture' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
	}

	/**
	 * Permission callback: verify the per-page nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_request( $request ) {
		$token = (string) $request->get_param( '_bono_token' );

		if ( '' === $token ) {
			$token = (string) $request->get_header( 'X-Bono-Token' );
		}

		if ( ! $this->verify_token( $token ) ) {
			return new WP_Error(
				'bono_invalid_token',
				__( 'Invalid or expired capture token.', 'bono-leads-connector' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle a generic capture request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_rest_capture( $request ) {
		$raw_fields = $request->get_param( 'fields' );
		$fields     = is_array( $raw_fields ) ? $raw_fields : array();

		$form_id = sanitize_text_field( (string) $request->get_param( 'formId' ) );

		if ( '' === $form_id ) {
			$form_id = 'form_unknown';
		}

		$form_name      = sanitize_text_field( (string) $request->get_param( 'formName' ) );
		$page_url_param = (string) $request->get_param( 'pageUrl' );
		$page_url       = '' !== $page_url_param ? esc_url_raw( $page_url_param ) : null;

		$payload = $this->build_submission_payload(
			'generic',
			$form_id,
			$form_name,
			$fields,
			null,
			$page_url
		);

		$this->log_submission_captured( __( 'Generic submission captured', 'bono-leads-connector' ), $payload );
		$this->send_payload( $payload );

		// Never leak pipeline internals to the public caller.
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Read configured opt-in CSS selectors as an array.
	 *
	 * @return array
	 */
	private function get_selectors() {
		$settings = class_exists( 'Bono_Settings' )
			? Bono_Settings::get_settings()
			: array();

		$raw = isset( $settings['generic_capture_selectors'] )
			? (string) $settings['generic_capture_selectors']
			: '';

		$parts = preg_split( '/[\r\n,]+/', $raw );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$selectors = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' !== $part ) {
				$selectors[] = $part;
			}
		}

		return array_values( array_unique( $selectors ) );
	}
}
