<?php
/**
 * Exercises the real capture pipeline: normalize -> detect contact -> apply
 * field mapping -> record form, plus provider field extraction.
 *
 * @package BonoLeadsConnector
 */

use PHPUnit\Framework\TestCase;

/** Exposes the protected payload builder for direct assertions. */
class TestableCapture extends Bono_Form_Capture {
    public function build($provider, $form_id, $form_name, array $fields) {
        return $this->build_submission_payload($provider, $form_id, $form_name, $fields);
    }
}

/** Spy that captures the payload each provider would send. */
class SpyGravity extends Bono_Gravity_Capture {
    public $sent;
    protected function send_payload(array $payload) { $this->sent = $payload; }
}
class SpyFluent extends Bono_Fluent_Capture {
    public $sent;
    protected function send_payload(array $payload) { $this->sent = $payload; }
}
class SpyForminator extends Bono_Forminator_Capture {
    public $sent;
    protected function send_payload(array $payload) { $this->sent = $payload; }
}

class CapturePipelineTest extends TestCase {
    /** @var TestableCapture */
    private $capture;

    protected function setUp(): void {
        bono_test_reset_store();
        $this->capture = new TestableCapture(new Bono_API_Client());
    }

    public function test_detects_name_email_phone_from_aliases() {
        $payload = $this->capture->build('generic', '1', 'Lead', array(
            'name' => 'Dana Cohen',
            'email' => 'dana@example.com',
            'phone' => '050-1234567',
        ));

        $this->assertSame('Dana Cohen', $payload['contact']['name']);
        $this->assertSame('dana@example.com', $payload['contact']['email']);
        $this->assertSame('0501234567', $payload['contact']['phone'], 'phone formatting stripped');
        $this->assertTrue($payload['validation']['isValid']);
    }

    public function test_concatenates_first_and_last_name() {
        $payload = $this->capture->build('generic', '2', 'Lead', array(
            'first-name' => 'Dana',
            'last-name' => 'Cohen',
            'email' => 'd@example.com',
        ));

        $this->assertSame('Dana Cohen', $payload['contact']['name']);
    }

    public function test_detects_hebrew_labelled_fields() {
        $payload = $this->capture->build('generic', '3', 'Lead', array(
            'שם' => 'דנה כהן',
            'אימייל' => 'dana@example.com',
            'טלפון' => '050-1234567',
        ));

        $this->assertSame('דנה כהן', $payload['contact']['name']);
        $this->assertSame('dana@example.com', $payload['contact']['email']);
        $this->assertSame('0501234567', $payload['contact']['phone']);
    }

    public function test_invalid_contact_marked_not_valid() {
        // Single-word, non-name/email/phone value: nothing should be detected.
        $payload = $this->capture->build('generic', '4', 'Lead', array(
            'subject' => 'hello',
        ));

        $this->assertFalse($payload['validation']['isValid']);
        $this->assertContains('name', $payload['validation']['missing']);
        $this->assertContains('phone_or_email', $payload['validation']['missing']);
    }

    public function test_field_mapping_overrides_heuristic_end_to_end() {
        $fields = array(
            'full-name' => 'Auto Picked',
            'company' => 'Acme Corp Ltd',
            'email' => 'x@example.com',
        );

        // First capture: heuristic picks the fullname alias, and the form is learned.
        $first = $this->capture->build('generic', '7', 'Lead', $fields);
        $this->assertSame('Auto Picked', $first['contact']['name']);

        $known = Bono_Field_Mapping::get_known_forms();
        $this->assertSame('generic:7', $known[0]['key']);
        $this->assertContains('company', $known[0]['fields']);

        // Admin maps the name role to the "company" field.
        Bono_Field_Mapping::save_mappings(array(
            'generic:7' => array('name' => 'company', 'email' => '', 'phone' => ''),
        ));

        $second = $this->capture->build('generic', '7', 'Lead', $fields);
        $this->assertSame('Acme Corp Ltd', $second['contact']['name'], 'mapping overrides detection');
        $this->assertSame('x@example.com', $second['contact']['email'], 'unmapped role still auto-detected');
    }

    public function test_registry_records_field_keys_not_values() {
        $this->capture->build('generic', '8', 'Lead', array(
            'name' => 'Secret Person',
            'email' => 'secret@example.com',
        ));

        $serialized = wp_json_encode($GLOBALS['__bono_options'][Bono_Field_Mapping::FORMS_OPTION]);
        $this->assertStringContainsString('name', $serialized);
        $this->assertStringContainsString('email', $serialized);
        $this->assertStringNotContainsString('Secret Person', $serialized, 'values must never be stored');
        $this->assertStringNotContainsString('secret@example.com', $serialized);
    }

    public function test_gravity_extraction_maps_field_ids_to_labels() {
        $form = array(
            'id' => 5,
            'title' => 'Contact',
            'fields' => array(
                (object) array('id' => 1, 'label' => 'Full Name'),
                (object) array('id' => 2, 'label' => 'Email'),
                (object) array('id' => 3, 'label' => 'Phone'),
            ),
        );
        $entry = array(
            'id' => '100', 'form_id' => '5', 'source_url' => 'https://site.example/contact',
            '1' => 'Dana Cohen', '2' => 'dana@example.com', '3' => '050-1234567',
        );

        $spy = new SpyGravity(new Bono_API_Client());
        $spy->handle_after_submission($entry, $form);

        $this->assertSame('gravity', $spy->sent['provider']);
        $this->assertSame('Dana Cohen', $spy->sent['fields']['Full Name']);
        $this->assertSame('Dana Cohen', $spy->sent['contact']['name']);
        $this->assertSame('dana@example.com', $spy->sent['contact']['email']);
    }

    public function test_fluent_forwards_submitted_data() {
        $spy = new SpyFluent(new Bono_API_Client());
        $form = (object) array('id' => 7, 'title' => 'Lead Form');
        $spy->handle_submission_inserted(100, array(
            'name' => 'Dana Cohen',
            'email' => 'dana@example.com',
            'phone' => '0501234567',
        ), $form);

        $this->assertSame('fluent', $spy->sent['provider']);
        $this->assertSame('Dana Cohen', $spy->sent['contact']['name']);
        $this->assertSame('0501234567', $spy->sent['contact']['phone']);
    }

    public function test_forminator_flattens_name_value_pairs() {
        $spy = new SpyForminator(new Bono_API_Client());
        $field_data = array(
            array('name' => 'name-1', 'value' => 'Dana Cohen'),
            array('name' => 'email-1', 'value' => 'dana@example.com'),
            array('name' => '', 'value' => 'skip'),
        );
        $spy->handle_before_set_fields(null, 9, $field_data);

        $this->assertSame('forminator', $spy->sent['provider']);
        $this->assertSame('Dana Cohen', $spy->sent['fields']['name-1']);
        $this->assertArrayNotHasKey('', $spy->sent['fields']);
    }
}
