<?php
// Mint a valid cache-safe capture token for the generic-capture REST route and print it.
$cap = new Bono_Generic_Capture( new Bono_API_Client() );
echo $cap->mint_token();
