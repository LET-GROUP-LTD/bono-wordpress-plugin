<?php
/**
 * Configure the Bono plugin to send to the recording mock backend.
 * Run via: wp eval-file .../configure-plugin.php
 */
$port = getenv( 'BONO_MOCK_PORT' ) ? getenv( 'BONO_MOCK_PORT' ) : '3001';
$settings = get_option( 'bono_leads_connector_settings', array() );
if ( ! is_array( $settings ) ) { $settings = array(); }
$settings['api_base_url']     = 'http://host.docker.internal:' . $port . '/api';
$settings['api_key']          = 'integration-test-key';
$settings['site_id']          = 'integration-test-site';
$settings['enable_debug_log'] = true;
update_option( 'bono_leads_connector_settings', $settings );

// Sanity: confirm the URL passes the plugin's allowlist guard.
$client = new Bono_API_Client();
$ref = new ReflectionMethod( 'Bono_API_Client', 'is_allowed_api_base_url' );
$ref->setAccessible( true );
$ok = $ref->invoke( $client, $settings['api_base_url'] );
echo "configured api_base_url=" . $settings['api_base_url'] . " allowed=" . ( $ok ? '1' : '0' ) . "\n";
if ( ! $ok ) { fwrite( STDERR, "api_base_url rejected by guard\n" ); exit( 1 ); }
