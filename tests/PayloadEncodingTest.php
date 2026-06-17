<?php
/**
 * Guards the JSON wire shape of map-typed payload fields ('utm', 'fields').
 *
 * The Bono API expects objects ({}) for these fields. PHP encodes an empty array
 * as [], and the durable-queue round-trip (json_decode(payload, true)) turns a
 * stored {} into an empty PHP array — which would re-send as [] and be rejected
 * with a 400, silently dropping the queued lead.
 *
 * @package BonoLeadsConnector
 */

use PHPUnit\Framework\TestCase;

class PayloadEncodingTest extends TestCase {
    public function test_empty_utm_encodes_as_object_after_normalization() {
        $payload = array( 'utm' => array(), 'fields' => array( 'name' => 'Alice' ) );
        $normalized = Bono_API_Client::normalize_map_fields( $payload );
        $this->assertSame( '{}', wp_json_encode( $normalized['utm'] ) );
    }

    public function test_empty_fields_encodes_as_object_after_normalization() {
        $payload = array( 'utm' => array( 'utm_source' => 'fb' ), 'fields' => array() );
        $normalized = Bono_API_Client::normalize_map_fields( $payload );
        $this->assertSame( '{}', wp_json_encode( $normalized['fields'] ) );
    }

    public function test_populated_maps_are_preserved() {
        $payload = array(
            'utm'    => array( 'utm_source' => 'fb' ),
            'fields' => array( 'name' => 'Alice', 'email' => 'a@example.com' ),
        );
        $normalized = Bono_API_Client::normalize_map_fields( $payload );
        $this->assertSame( array( 'utm_source' => 'fb' ), $normalized['utm'] );
        $this->assertSame(
            array( 'name' => 'Alice', 'email' => 'a@example.com' ),
            $normalized['fields']
        );
    }

    public function test_queue_round_trip_no_longer_corrupts_empty_utm() {
        // Simulate the queue: an empty object is stored, then re-read with json_decode(..., true).
        $stored  = wp_json_encode( array( 'utm' => new stdClass(), 'fields' => array( 'name' => 'Bob' ) ) );
        $decoded = json_decode( $stored, true ); // utm is now an empty PHP array
        $normalized = Bono_API_Client::normalize_map_fields( $decoded );
        $this->assertSame( '{}', wp_json_encode( $normalized['utm'] ) );
    }
}
