<?php
/**
 * Cache-safe capture token for generic opt-in capture.
 *
 * Replaces the session-bound WP nonce (which 403s for logged-in users and on
 * full-page-cached pages older than the nonce lifetime) with an HMAC token that
 * verifies regardless of session, so generic capture never silently drops a lead.
 *
 * @package BonoLeadsConnector
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bono-generic-capture.php';

class GenericCaptureTokenTest extends TestCase {
    private function capture(): Bono_Generic_Capture {
        return new Bono_Generic_Capture( new Bono_API_Client() );
    }

    public function test_freshly_minted_token_verifies() {
        $cap = $this->capture();
        $this->assertTrue( $cap->verify_token( $cap->mint_token() ) );
    }

    public function test_expired_token_is_rejected() {
        $cap = $this->capture();
        // TTL in the past → already expired.
        $this->assertFalse( $cap->verify_token( $cap->mint_token( -10 ) ) );
    }

    public function test_tampered_signature_is_rejected() {
        $cap   = $this->capture();
        $token = $cap->mint_token();
        list( $expiry, $sig ) = explode( '.', $token, 2 );
        $tampered = $expiry . '.' . strrev( $sig );
        $this->assertFalse( $cap->verify_token( $tampered ) );
    }

    public function test_tampered_expiry_is_rejected() {
        $cap   = $this->capture();
        $token = $cap->mint_token();
        list( , $sig ) = explode( '.', $token, 2 );
        // Push expiry far into the future while keeping the old signature.
        $forged = ( time() + 999999 ) . '.' . $sig;
        $this->assertFalse( $cap->verify_token( $forged ) );
    }

    public function test_malformed_tokens_are_rejected() {
        $cap = $this->capture();
        $this->assertFalse( $cap->verify_token( '' ) );
        $this->assertFalse( $cap->verify_token( 'no-dot' ) );
        $this->assertFalse( $cap->verify_token( 'abc.def' ) );
        $this->assertFalse( $cap->verify_token( null ) );
    }

    public function test_token_is_not_session_bound_two_instances_interoperate() {
        // A token minted on one request must verify on another (different object,
        // same salt) — this is what makes it survive caching + logged-out visitors.
        $minted = $this->capture()->mint_token();
        $this->assertTrue( $this->capture()->verify_token( $minted ) );
    }
}
