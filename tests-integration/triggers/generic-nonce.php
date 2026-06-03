<?php
// Mint a valid nonce for the generic-capture REST route and print it.
$action = Bono_Generic_Capture::NONCE_ACTION; // 'bono_generic_capture'
echo wp_create_nonce( $action );
