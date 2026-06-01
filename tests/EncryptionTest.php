<?php
/**
 * @package BonoLeadsConnector
 */

use PHPUnit\Framework\TestCase;

class EncryptionTest extends TestCase {
    protected function setUp(): void {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available.');
        }
        bono_test_reset_store();
    }

    public function test_round_trip_returns_original() {
        $secret = 'super-secret-api-key-123456';
        $envelope = Bono_Settings::encrypt_secret($secret);

        $this->assertNotSame($secret, $envelope, 'stored value must not be plaintext');
        $this->assertStringStartsWith(Bono_Settings::SECRET_PREFIX, $envelope);
        $this->assertSame($secret, Bono_Settings::decrypt_secret($envelope));
    }

    public function test_empty_passes_through() {
        $this->assertSame('', Bono_Settings::encrypt_secret(''));
        $this->assertSame('', Bono_Settings::decrypt_secret(''));
    }

    public function test_legacy_plaintext_decrypts_unchanged() {
        // Values stored before encryption was added have no envelope prefix.
        $this->assertSame('legacy-plain-key', Bono_Settings::decrypt_secret('legacy-plain-key'));
    }

    public function test_tampered_ciphertext_yields_empty() {
        $envelope = Bono_Settings::encrypt_secret('another-secret');
        // Corrupt the first base64 char of the body after the prefix.
        $body = substr($envelope, strlen(Bono_Settings::SECRET_PREFIX));
        $replacement = 'A' === $body[0] ? 'B' : 'A';
        $tampered = Bono_Settings::SECRET_PREFIX . $replacement . substr($body, 1);

        $this->assertSame('', Bono_Settings::decrypt_secret($tampered), 'tampering must not yield a secret');
    }

    public function test_each_encryption_uses_a_fresh_nonce() {
        $a = Bono_Settings::encrypt_secret('same-input');
        $b = Bono_Settings::encrypt_secret('same-input');

        $this->assertNotSame($a, $b, 'random nonce should make ciphertexts differ');
        $this->assertSame('same-input', Bono_Settings::decrypt_secret($a));
        $this->assertSame('same-input', Bono_Settings::decrypt_secret($b));
    }
}
