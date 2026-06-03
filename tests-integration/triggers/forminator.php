<?php
$env   = get_option( 'bono_test_trigger_env', '{}' );
$env   = is_string( $env ) ? json_decode( $env, true ) : array();
$env   = is_array( $env ) ? $env : array();
$name  = isset( $env['NAME'] )  ? $env['NAME']  : 'Dana Cohen';
$email = isset( $env['EMAIL'] ) ? $env['EMAIL'] : 'dana@example.com';
$phone = isset( $env['PHONE'] ) ? $env['PHONE'] : '0501234567';
$omit  = isset( $env['OMIT_CONTACT'] ) && $env['OMIT_CONTACT'] === '1';

$field_data = array( array( 'name' => 'name-1', 'value' => $name ) );
if ( ! $omit ) {
    $field_data[] = array( 'name' => 'email-1', 'value' => $email );
    $field_data[] = array( 'name' => 'phone-1', 'value' => $phone );
}

do_action( 'forminator_custom_form_submit_before_set_fields', null, 9, $field_data );
echo "fired forminator_custom_form_submit_before_set_fields omit=" . ( $omit ? '1' : '0' ) . "\n";
