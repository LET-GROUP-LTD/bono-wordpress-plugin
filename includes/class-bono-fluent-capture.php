<?php
/**
 * Fluent Forms capture integration.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_Fluent_Capture extends Bono_Form_Capture {
	/**
	 * Register Fluent Forms hooks.
	 *
	 * The hook only fires when Fluent Forms is active, so registering it
	 * unconditionally is safe.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'fluentform/submission_inserted', array( $this, 'handle_submission_inserted' ), 10, 3 );
	}

	/**
	 * Handle an inserted Fluent Forms submission.
	 *
	 * @param int          $entry_id  Inserted entry id.
	 * @param array        $form_data Submitted data (input name => value).
	 * @param object|array $form      Form model.
	 * @return void
	 */
	public function handle_submission_inserted( $entry_id, $form_data, $form ) {
		$form_data = is_array( $form_data ) ? $form_data : array();

		$form_id   = $this->fluent_form_prop( $form, 'id' );
		$form_id   = '' !== $form_id ? sanitize_text_field( $form_id ) : 'form_unknown';
		$form_name = $this->fluent_form_prop( $form, 'title' );
		$form_name = sanitize_text_field( $form_name );

		$payload = $this->build_submission_payload(
			'fluent',
			$form_id,
			$form_name,
			$form_data,
			null,
			null
		);

		$this->log_submission_captured( __( 'Fluent Forms submission captured', 'bono-leads-connector' ), $payload );
		$this->send_payload( $payload );
	}

	/**
	 * Read a property from a Fluent form model that may be object or array.
	 *
	 * @param mixed  $form Form model.
	 * @param string $prop Property name.
	 * @return string
	 */
	private function fluent_form_prop( $form, $prop ) {
		if ( is_object( $form ) && isset( $form->$prop ) ) {
			return (string) $form->$prop;
		}

		if ( is_array( $form ) && isset( $form[ $prop ] ) ) {
			return (string) $form[ $prop ];
		}

		return '';
	}
}
