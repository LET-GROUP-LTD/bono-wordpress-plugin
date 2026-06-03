<?php
// Read test inputs from the bono_test_trigger_env WP option.
// wpEval() in helpers.mjs writes this option before running the trigger
// because host-process env vars are not visible to PHP inside Docker.
$env   = get_option( 'bono_test_trigger_env', '{}' );
$env   = is_string( $env ) ? json_decode( $env, true ) : array();
$env   = is_array( $env ) ? $env : array();

$first = isset( $env['FIRST'] ) ? $env['FIRST'] : 'Dana';
$last  = isset( $env['LAST'] )  ? $env['LAST']  : 'Cohen';
$email = isset( $env['EMAIL'] ) ? $env['EMAIL'] : 'dana@example.com';
$phone = isset( $env['PHONE'] ) ? $env['PHONE'] : '0501234567';

// Bono_Gravity_Capture::register_hooks() has no plugin-presence guard — it calls
// add_action('gform_after_submission', ...) unconditionally, so the hook is already
// registered by the time the plugin finishes loading. No extra setup is needed here.
//
// Use two separate name fields with labels 'First Name' and 'Last Name' so that
// Bono_Form_Capture::detect_name() can combine them into a single full name.
// (Both '1.3' and '1.6' share base id '1' and would overwrite each other in
// extract_gravity_fields, which uses the base id for label lookup.)
$entry = array(
	'1'          => $first,
	'2'          => $last,
	'3'          => $email,
	'4'          => $phone,
	'source_url' => 'http://localhost:8888/gravity-page',
);
$form = array(
	'id'     => 7,
	'title'  => 'Bono Test Gravity',
	'fields' => array(
		array( 'id' => 1, 'type' => 'name',  'label' => 'First Name' ),
		array( 'id' => 2, 'type' => 'name',  'label' => 'Last Name' ),
		array( 'id' => 3, 'type' => 'email', 'label' => 'Email' ),
		array( 'id' => 4, 'type' => 'phone', 'label' => 'Phone' ),
	),
);

do_action( 'gform_after_submission', $entry, $form );
echo "fired gform_after_submission (simulated)\n";
