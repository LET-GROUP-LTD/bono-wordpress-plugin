<?php
/**
 * Bono API client.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_API_Client {
	/**
	 * Force map-typed payload fields ('utm', 'fields') to encode as JSON objects.
	 *
	 * An empty PHP array encodes as `[]`, but the Bono API expects an object (`{}`).
	 * This matters on the durable-queue retry path: a stored `{}` becomes an empty PHP
	 * array after json_decode( $payload, true ) and would otherwise re-send as `[]`,
	 * which the API rejects with a 400 — silently dropping the queued lead.
	 *
	 * @param array $payload Submission payload.
	 * @return array
	 */
	public static function normalize_map_fields( array $payload ) {
		foreach ( array( 'utm', 'fields' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) && empty( $payload[ $key ] ) ) {
				$payload[ $key ] = new stdClass();
			}
		}

		return $payload;
	}

	/**
	 * Send a normalized submission payload to Bono.
	 *
	 * @param array $payload Normalized submission payload.
	 * @return array
	 */
	public function send_submission( array $payload ) {
		$settings = $this->get_settings();

		if ( empty( $settings['api_base_url'] ) || empty( $settings['api_key'] ) || empty( $settings['site_id'] ) ) {
			return $this->result( false, null, null, __( 'Missing Bono API settings.', 'bono-leads-connector' ) );
		}

		if ( ! $this->is_allowed_api_base_url( $settings['api_base_url'] ) ) {
			return $this->result( false, null, null, __( 'Insecure API Base URL is not allowed.', 'bono-leads-connector' ) );
		}

		$endpoint = $this->build_endpoint( $settings['api_base_url'], '/wordpress/submissions' );

		if ( empty( $endpoint ) ) {
			return $this->result( false, null, null, __( 'Invalid Bono API base URL.', 'bono-leads-connector' ) );
		}

		return $this->post_json( $endpoint, $payload, $settings, 'submission', false );
	}

	/**
	 * Send a test request to Bono.
	 *
	 * @return array
	 */
	public function test_connection() {
		$settings = $this->get_settings();

		if ( empty( $settings['api_base_url'] ) || empty( $settings['api_key'] ) || empty( $settings['site_id'] ) ) {
			return $this->result( false, null, null, __( 'Missing Bono API settings.', 'bono-leads-connector' ) );
		}

		if ( ! $this->is_allowed_api_base_url( $settings['api_base_url'] ) ) {
			return $this->result( false, null, null, __( 'Insecure API Base URL is not allowed.', 'bono-leads-connector' ) );
		}

		$endpoint = $this->build_endpoint( $settings['api_base_url'], '/wordpress/test' );

		if ( empty( $endpoint ) ) {
			return $this->result( false, null, null, __( 'Invalid Bono API base URL.', 'bono-leads-connector' ) );
		}

		return $this->post_json(
			$endpoint,
			array(
				'test'           => true,
				'timestamp'      => current_time( 'c' ),
				'plugin_version' => defined( 'BONO_PLUGIN_VERSION' ) ? BONO_PLUGIN_VERSION : '',
			),
			$settings,
			'test',
			true
		);
	}

	/**
	 * Exchange a single-use provisioning token for a site_id + API key.
	 *
	 * Authenticated by the provisioning token in the body (no API key header).
	 *
	 * @param string $provisioning_token Provisioning token from the Bono app.
	 * @param string $api_base_url       Bono API base URL to register against.
	 * @return array { success, site_id, api_key, status_code, error }
	 */
	public function register_site( $provisioning_token, $api_base_url ) {
		$provisioning_token = trim( (string) $provisioning_token );

		if ( '' === $provisioning_token ) {
			return $this->register_result( false, null, null, null, __( 'Provisioning token is required.', 'bono-leads-connector' ) );
		}

		if ( ! $this->is_allowed_api_base_url( $api_base_url ) ) {
			return $this->register_result( false, null, null, null, __( 'Insecure or invalid API Base URL is not allowed.', 'bono-leads-connector' ) );
		}

		$endpoint = $this->build_endpoint( $api_base_url, '/wordpress/sites/register' );

		if ( empty( $endpoint ) ) {
			return $this->register_result( false, null, null, null, __( 'Invalid Bono API base URL.', 'bono-leads-connector' ) );
		}

		$payload = array(
			'provisioningToken' => $provisioning_token,
			'site'              => array(
				'url'  => home_url(),
				'name' => get_bloginfo( 'name' ),
			),
			'pluginVersion'     => defined( 'BONO_PLUGIN_VERSION' ) ? BONO_PLUGIN_VERSION : '',
		);

		$reject_unsafe_urls = ! $this->is_allowed_local_development_api_base_url( $api_base_url );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'            => 15,
				'redirection'        => 0,
				'blocking'           => true,
				'reject_unsafe_urls' => $reject_unsafe_urls,
				'headers'            => array(
					'Content-Type'          => 'application/json',
					'X-Bono-Plugin-Version' => defined( 'BONO_PLUGIN_VERSION' ) ? BONO_PLUGIN_VERSION : '',
				),
				'body'               => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->register_result( false, null, null, null, $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$site_id     = is_array( $body ) && isset( $body['site_id'] ) ? sanitize_text_field( (string) $body['site_id'] ) : '';
		$api_key     = is_array( $body ) && isset( $body['api_key'] ) ? sanitize_text_field( (string) $body['api_key'] ) : '';
		$succeeded   = 200 === $status_code && is_array( $body ) && ! empty( $body['success'] ) && '' !== $site_id && '' !== $api_key;

		if ( ! $succeeded ) {
			$message = is_array( $body ) && ! empty( $body['message'] )
				? sanitize_text_field( (string) $body['message'] )
				: __( 'Bono rejected the connection request.', 'bono-leads-connector' );

			return $this->register_result( false, $status_code, null, null, $message );
		}

		return $this->register_result( true, $status_code, $site_id, $api_key, null );
	}

	/**
	 * Build a structured register_site result.
	 *
	 * @param bool        $success     Whether registration succeeded.
	 * @param int|null    $status_code HTTP status code.
	 * @param string|null $site_id     Issued site ID.
	 * @param string|null $api_key     Issued API key.
	 * @param string|null $error       Error message.
	 * @return array
	 */
	private function register_result( $success, $status_code, $site_id, $api_key, $error ) {
		return array(
			'success'     => (bool) $success,
			'status_code' => $status_code,
			'site_id'     => $site_id,
			'api_key'     => $api_key,
			'error'       => $error,
		);
	}

	/**
	 * Get settings from the settings class when available.
	 *
	 * @return array
	 */
	private function get_settings() {
		if ( class_exists( 'Bono_Settings' ) ) {
			return Bono_Settings::get_settings();
		}

		$settings = get_option( 'bono_leads_connector_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args(
			$settings,
			array(
				'api_base_url'     => '',
				'api_key'          => '',
				'site_id'          => '',
				'enable_debug_log' => false,
			)
		);
	}

	/**
	 * Build an API endpoint.
	 *
	 * @param string $api_base_url API base URL.
	 * @param string $path Endpoint path.
	 * @return string
	 */
	private function build_endpoint( $api_base_url, $path ) {
		$api_base_url = esc_url_raw( trim( $api_base_url ) );

		if ( empty( $api_base_url ) || ! $this->is_allowed_api_base_url( $api_base_url ) ) {
			return '';
		}

		return untrailingslashit( $api_base_url ) . $path;
	}

	/**
	 * Determine whether an API base URL is allowed for outbound requests.
	 *
	 * @param string $api_base_url API base URL.
	 * @return bool
	 */
	private function is_allowed_api_base_url( $api_base_url ) {
		if ( class_exists( 'Bono_Settings' ) && method_exists( 'Bono_Settings', 'is_allowed_api_base_url' ) ) {
			return Bono_Settings::is_allowed_api_base_url( $api_base_url );
		}

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
	 * Determine whether an API base URL is an allowed local development URL.
	 *
	 * @param string $api_base_url API base URL.
	 * @return bool
	 */
	private function is_allowed_local_development_api_base_url( $api_base_url ) {
		$api_base_url = esc_url_raw( trim( (string) $api_base_url ) );

		if ( '' === $api_base_url ) {
			return false;
		}

		$parts = wp_parse_url( $api_base_url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( 'http' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}

		return in_array(
			strtolower( (string) $parts['host'] ),
			array( 'localhost', '127.0.0.1', 'host.docker.internal' ),
			true
		);
	}

	/**
	 * Send a JSON POST request to Bono.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param array  $payload Request payload.
	 * @param array  $settings Bono settings.
	 * @param string $request_type Request type for logs.
	 * @param bool   $require_http_200 Whether only HTTP 200 is considered successful.
	 * @return array
	 */
	private function post_json( $endpoint, array $payload, array $settings, $request_type, $require_http_200 ) {
		$this->debug_log(
			__( 'Sending Bono API request.', 'bono-leads-connector' ),
			array(
				'request_type'   => $request_type,
				'idempotencyKey' => isset( $payload['idempotencyKey'] ) ? $payload['idempotencyKey'] : '',
			)
		);

		$headers = array(
			'Content-Type'          => 'application/json',
			'X-Bono-Api-Key'        => $settings['api_key'],
			'X-Bono-Site-Id'        => $settings['site_id'],
			'X-Bono-Plugin-Version' => defined( 'BONO_PLUGIN_VERSION' ) ? BONO_PLUGIN_VERSION : '',
		);

		if ( ! empty( $payload['idempotencyKey'] ) ) {
			$headers['X-Bono-Idempotency-Key'] = $payload['idempotencyKey'];
		}

		$reject_unsafe_urls = ! $this->is_allowed_local_development_api_base_url(
			isset( $settings['api_base_url'] ) ? $settings['api_base_url'] : ''
		);

		// Keep map-typed fields as JSON objects ({}), even after a queue round-trip flattened them.
		$payload = self::normalize_map_fields( $payload );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'            => 10,
				'redirection'        => 0,
				'blocking'           => true,
				'reject_unsafe_urls' => $reject_unsafe_urls,
				'headers'            => $headers,
				'body'               => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();

			$this->debug_log(
				__( 'Bono API request failed.', 'bono-leads-connector' ),
				array(
					'request_type'   => $request_type,
					'error'          => $error,
					'idempotencyKey' => isset( $payload['idempotencyKey'] ) ? $payload['idempotencyKey'] : '',
				)
			);

			return $this->result( false, null, null, $error );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$success     = $require_http_200 ? 200 === $status_code : $status_code >= 200 && $status_code < 300;

		$this->debug_log(
			__( 'Received Bono API response.', 'bono-leads-connector' ),
			array(
				'request_type'   => $request_type,
				'status_code'    => $status_code,
				'success'        => $success ? 'true' : 'false',
				'idempotencyKey' => isset( $payload['idempotencyKey'] ) ? $payload['idempotencyKey'] : '',
			)
		);

		return $this->result(
			$success,
			$status_code,
			is_string( $body ) ? $body : null,
			$success ? null : __( 'Bono API returned a non-success response.', 'bono-leads-connector' )
		);
	}

	/**
	 * Log debug messages only when explicitly enabled.
	 *
	 * @param string $message Message.
	 * @param array  $context Non-sensitive context.
	 * @return void
	 */
	private function debug_log( $message, array $context = array() ) {
		$settings = $this->get_settings();

		if ( empty( $settings['enable_debug_log'] ) ) {
			return;
		}

		$line = '[Bono Leads Connector] ' . sanitize_text_field( $message );

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $this->normalize_log_context( $context ) );
		}

		error_log( $line );
	}

	/**
	 * Normalize debug log context.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function normalize_log_context( array $context ) {
		$normalized   = array();
		$allowed_keys = array(
			'provider',
			'sourceKey',
			'formId',
			'pageId',
			'status_code',
			'success',
			'error',
			'idempotencyKey',
		);

		foreach ( $context as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				continue;
			}

			if ( 'idempotencyKey' === $key ) {
				$normalized[ sanitize_key( $key ) ] = substr( sanitize_text_field( (string) $value ), 0, 8 );
				continue;
			}

			$normalized[ sanitize_key( $key ) ] = is_scalar( $value ) || is_null( $value )
				? sanitize_text_field( (string) $value )
				: '[non_scalar]';
		}

		return $normalized;
	}

	/**
	 * Build a structured result.
	 *
	 * @param bool        $success Whether the request succeeded.
	 * @param int|null    $status_code HTTP status code.
	 * @param string|null $body Response body.
	 * @param string|null $error Error message.
	 * @return array
	 */
	private function result( $success, $status_code, $body, $error ) {
		return array(
			'success'     => (bool) $success,
			'status_code' => $status_code,
			'body'        => $body,
			'error'       => $error,
		);
	}
}
