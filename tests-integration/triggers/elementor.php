<?php
// Read test inputs from the bono_test_trigger_env WP option.
// wpEval() in helpers.mjs writes this option before running the trigger
// because host-process env vars are not visible to PHP inside Docker.
$env   = get_option( 'bono_test_trigger_env', '{}' );
$env   = is_string( $env ) ? json_decode( $env, true ) : array();
$env   = is_array( $env ) ? $env : array();

$name  = isset( $env['NAME'] )  ? $env['NAME']  : 'Dana Cohen';
$email = isset( $env['EMAIL'] ) ? $env['EMAIL'] : 'dana@example.com';
$phone = isset( $env['PHONE'] ) ? $env['PHONE'] : '0501234567';

// Define the Elementor Pro version constant so that Bono_Elementor_Capture::register_hooks()
// passes its guard check and attaches the listener. Elementor Pro is not installed in the
// test environment, so we simulate its presence with this constant.
if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	define( 'ELEMENTOR_PRO_VERSION', '3.0.0-test' );
}

// Instantiate and register the capture class now that the guard will pass.
// plugins_loaded has already fired, so we wire it up manually here.
if ( class_exists( 'Bono_Elementor_Capture' ) && class_exists( 'Bono_API_Client' ) ) {
	$elementor_capture = new Bono_Elementor_Capture( new Bono_API_Client() );
	$elementor_capture->register_hooks();
}

/**
 * Minimal stand-in for \ElementorPro\Modules\Forms\Classes\Form_Record.
 *
 * The Bono handler calls:
 *   - $record->get('fields')       — expects array keyed by field id, each entry is
 *                                    an array with 'id', 'value' (and optionally 'title').
 *   - $record->get_form_settings($key) / $record->get('form_settings')
 *                                  — returns form_name and form_id (used as 'id' key).
 *   - $record->get('page_id')      — numeric page id.
 *   - $record->get('post_id')      — fallback page id.
 *   - $record->get('meta')         — array that may contain 'page_url'.
 */
class Bono_Test_Elementor_Record {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get( $key ) { return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null; }
	public function get_form_settings( $key ) {
		$s = $this->get( 'form_settings' );
		return is_array( $s ) && isset( $s[ $key ] ) ? $s[ $key ] : null;
	}
}

$record = new Bono_Test_Elementor_Record( array(
	'fields' => array(
		'name'  => array( 'id' => 'name',  'value' => $name,  'title' => 'Name' ),
		'email' => array( 'id' => 'email', 'value' => $email, 'title' => 'Email' ),
		'phone' => array( 'id' => 'phone', 'value' => $phone, 'title' => 'Phone' ),
	),
	'form_settings' => array( 'form_name' => 'Bono Test Elementor', 'id' => 'abc123' ),
	'page_id'       => 12,
	'post_id'       => 12,
	'meta'          => array(),
) );

do_action( 'elementor_pro/forms/new_record', $record, null );
echo "fired elementor_pro/forms/new_record (simulated)\n";
