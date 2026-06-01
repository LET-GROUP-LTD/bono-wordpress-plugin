<?php
/**
 * WPForms capture integration.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_WPForms_Capture extends Bono_Form_Capture {
	/**
	 * Register WPForms hooks when WPForms is available.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! function_exists( 'wpforms' ) && ! class_exists( 'WPForms' ) ) {
			return;
		}

		add_action( 'wpforms_process_complete', array( $this, 'handle_process_complete' ), 10, 4 );
	}

	/**
	 * Handle completed WPForms submission processing.
	 *
	 * @param array $fields Submitted WPForms fields.
	 * @param array $entry Submitted entry data.
	 * @param array $form_data Form metadata.
	 * @param int   $entry_id Entry ID.
	 * @return void
	 */
	public function handle_process_complete( $fields, $entry, $form_data, $entry_id ) {
		$fields    = is_array( $fields ) ? $fields : array();
		$entry     = is_array( $entry ) ? $entry : array();
		$form_data = is_array( $form_data ) ? $form_data : array();

		$form_id          = $this->get_wpforms_form_id( $form_data, $entry );
		$form_name        = $this->get_wpforms_form_name( $form_data );
		$submitted_fields = $this->extract_wpforms_fields( $fields );
		$page_id          = $this->get_wpforms_page_id( $entry );
		$page_url         = $this->get_wpforms_page_url( $entry );

		$payload = $this->build_submission_payload(
			'wpforms',
			$form_id,
			$form_name,
			$submitted_fields,
			$page_id,
			$page_url
		);

		$this->log_submission_captured( __( 'WPForms submission captured', 'bono-leads-connector' ), $payload );
		$this->send_payload( $payload );
	}

	/**
	 * Extract WPForms form ID.
	 *
	 * @param array $form_data Form metadata.
	 * @param array $entry Entry data.
	 * @return string
	 */
	private function get_wpforms_form_id( array $form_data, array $entry ) {
		if ( ! empty( $form_data['id'] ) ) {
			return sanitize_text_field( (string) $form_data['id'] );
		}

		if ( ! empty( $entry['form_id'] ) ) {
			return sanitize_text_field( (string) $entry['form_id'] );
		}

		return 'form_unknown';
	}

	/**
	 * Extract WPForms form name.
	 *
	 * @param array $form_data Form metadata.
	 * @return string
	 */
	private function get_wpforms_form_name( array $form_data ) {
		if ( ! empty( $form_data['settings']['form_title'] ) ) {
			return sanitize_text_field( (string) $form_data['settings']['form_title'] );
		}

		if ( ! empty( $form_data['post_title'] ) ) {
			return sanitize_text_field( (string) $form_data['post_title'] );
		}

		return '';
	}

	/**
	 * Convert WPForms field records to simple key/value fields.
	 *
	 * @param array $fields WPForms fields.
	 * @return array
	 */
	private function extract_wpforms_fields( array $fields ) {
		$submitted = array();

		foreach ( $fields as $key => $field ) {
			$field_key = sanitize_text_field( (string) $key );

			if ( is_array( $field ) ) {
				if ( ! empty( $field['name'] ) ) {
					$field_key = sanitize_text_field( (string) $field['name'] );
				} elseif ( ! empty( $field['id'] ) ) {
					$field_key = sanitize_text_field( (string) $field['id'] );
				}

				if ( array_key_exists( 'value', $field ) ) {
					$submitted[ $field_key ] = $field['value'];
					continue;
				}
			}

			$submitted[ $field_key ] = $field;
		}

		return $submitted;
	}

	/**
	 * Get WPForms page ID when available.
	 *
	 * @param array $entry Entry data.
	 * @return string|null
	 */
	private function get_wpforms_page_id( array $entry ) {
		if ( ! empty( $entry['post_id'] ) ) {
			return sanitize_text_field( (string) $entry['post_id'] );
		}

		if ( ! empty( $entry['page_id'] ) ) {
			return sanitize_text_field( (string) $entry['page_id'] );
		}

		if ( isset( $_POST['wpforms'] ) && is_array( $_POST['wpforms'] ) && isset( $_POST['wpforms']['page_id'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['wpforms']['page_id'] ) );
		}

		return null;
	}

	/**
	 * Get WPForms page URL when available.
	 *
	 * @param array $entry Entry data.
	 * @return string|null
	 */
	private function get_wpforms_page_url( array $entry ) {
		if ( ! empty( $entry['page_url'] ) ) {
			return esc_url_raw( $entry['page_url'] );
		}

		if ( isset( $_POST['wpforms'] ) && is_array( $_POST['wpforms'] ) && isset( $_POST['wpforms']['page_url'] ) ) {
			return esc_url_raw( wp_unslash( $_POST['wpforms']['page_url'] ) );
		}

		return null;
	}
}
