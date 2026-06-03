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

$ids     = get_option( 'bono_test_form_ids', array() );
$form_id = isset( $ids['wpforms'] ) ? (int) $ids['wpforms'] : 0;

// $fields keys are field IDs; each item uses 'name' as the label (used by
// extract_wpforms_fields() as the dict key) and 'value' as the submitted value.
// The base-class heuristic then lowercases 'Name'→'name', 'Email'→'email',
// 'Phone'→'phone' which hit the exact aliases for contact detection.
$fields = array(
    '1' => array( 'name' => 'Name',  'value' => $name,  'id' => '1', 'type' => 'name' ),
    '2' => array( 'name' => 'Email', 'value' => $email, 'id' => '2', 'type' => 'email' ),
    '3' => array( 'name' => 'Phone', 'value' => $phone, 'id' => '3', 'type' => 'phone' ),
);
$entry     = array( 'fields' => $fields );
$form_data = array( 'id' => $form_id, 'settings' => array( 'form_title' => 'Bono Test WPForms' ) );

do_action( 'wpforms_process_complete', $fields, $entry, $form_data, 999 );
echo "fired wpforms_process_complete form_id={$form_id}\n";
