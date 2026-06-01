<?php
/**
 * Structured logging + status endpoint payload.
 *
 * @package BonoLeadsConnector
 */

use PHPUnit\Framework\TestCase;

/** Exposes the protected log formatter. */
class LoggingCapture extends Bono_Form_Capture {
    public function fmt($message, array $context = array()) {
        return $this->format_log_entry($message, $context);
    }
}

class ObservabilityTest extends TestCase {
    protected function setUp(): void {
        bono_test_reset_store();
    }

    public function test_log_entry_is_valid_json_with_expected_shape() {
        $cap = new LoggingCapture(new Bono_API_Client());
        $line = $cap->fmt('Bono submission failed.', array(
            'provider' => 'cf7',
            'sourceKey' => 'cf7:5:page_2',
            'status_code' => 502,
        ));

        $this->assertStringStartsWith('[Bono Leads Connector] ', $line);
        $json = substr($line, strlen('[Bono Leads Connector] '));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, 'log payload must be valid JSON');
        $this->assertSame('bono-leads-connector', $decoded['plugin']);
        $this->assertSame('debug', $decoded['level']);
        $this->assertSame('Bono submission failed.', $decoded['message']);
        $this->assertSame('cf7', $decoded['context']['provider']);
        // normalize_log_context stringifies scalar values.
        $this->assertSame('502', $decoded['context']['status_code']);
    }

    public function test_log_context_is_restricted_to_allowlist() {
        $cap = new LoggingCapture(new Bono_API_Client());
        $line = $cap->fmt('Captured', array(
            'sourceKey' => 'cf7:5:page_2',
            'email' => 'lead@example.com',  // not on the allow-list
            'name' => 'Secret Person',      // not on the allow-list
        ));

        $this->assertStringNotContainsString('lead@example.com', $line, 'PII must not be logged');
        $this->assertStringNotContainsString('Secret Person', $line);
        $this->assertStringContainsString('cf7:5:page_2', $line);
    }

    public function test_status_payload_shape() {
        $endpoint = new Bono_Status_Endpoint();
        $settings = array('site_id' => 'site_abc', 'api_key' => 'k-123');
        $counts = array(
            'pending' => 3,
            'retrying' => 1,
            'sent' => 42,
            'failed' => 2,
            'latest_failed_at' => '2026-06-01 10:00:00',
            'health' => array('state' => 'warning'),
        );

        $payload = $endpoint->build_status_payload($settings, $counts, true);

        $this->assertTrue($payload['connected']);
        $this->assertSame('site_abc', $payload['site_id']);
        $this->assertSame(3, $payload['queue']['pending']);
        $this->assertSame(42, $payload['queue']['sent']);
        $this->assertSame('warning', $payload['queue']['health']);
        $this->assertTrue($payload['async_processing']);
        // No lead data leaks into the status payload.
        $this->assertArrayNotHasKey('fields', $payload);
        $this->assertArrayNotHasKey('contact', $payload);
    }

    public function test_status_payload_reports_disconnected_when_unconfigured() {
        $endpoint = new Bono_Status_Endpoint();
        $payload = $endpoint->build_status_payload(array(), array(), false);

        $this->assertFalse($payload['connected']);
        $this->assertSame('', $payload['site_id']);
        $this->assertSame(0, $payload['queue']['pending']);
        $this->assertSame('unknown', $payload['queue']['health']);
        $this->assertFalse($payload['async_processing']);
    }
}
