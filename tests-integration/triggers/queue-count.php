<?php
global $wpdb;
$t = $wpdb->prefix . 'bono_submission_queue';
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
if ( ! $exists ) { echo '0'; return; }
echo (string) (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
