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

// $form_data is a flat input-name => value map; the heuristic contact detector
// matches keys 'name', 'email', 'phone' directly.
$form_data = array( 'name' => $name, 'email' => $email, 'phone' => $phone );

// $form may be an object or array; fluent_form_prop() in the handler handles both.
$form = array( 'id' => 5, 'title' => 'Bono Test Fluent' );

do_action( 'fluentform/submission_inserted', 100, $form_data, $form );
echo "fired fluentform/submission_inserted\n";
