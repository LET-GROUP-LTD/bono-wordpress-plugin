<?php
/**
 * @package BonoLeadsConnector
 */

use PHPUnit\Framework\TestCase;

class FieldMappingTest extends TestCase {
    protected function setUp(): void {
        bono_test_reset_store();
    }

    public function test_record_form_stores_keys_only_and_skips_unchanged_writes() {
        Bono_Field_Mapping::record_form('cf7', '5', 'Contact', array('your-name', 'your-email', 'phone-1'));
        $forms = Bono_Field_Mapping::get_known_forms();

        $this->assertCount(1, $forms);
        $this->assertSame('cf7:5', $forms[0]['key']);
        $this->assertSame(array('your-name', 'your-email', 'phone-1'), $forms[0]['fields']);

        $snapshot = $GLOBALS['__bono_options'][Bono_Field_Mapping::FORMS_OPTION];
        Bono_Field_Mapping::record_form('cf7', '5', 'Contact', array('your-name', 'your-email', 'phone-1'));
        $this->assertSame(
            $snapshot,
            $GLOBALS['__bono_options'][Bono_Field_Mapping::FORMS_OPTION],
            'Identical re-record must not change the registry.'
        );
    }

    public function test_record_form_updates_when_field_set_changes() {
        Bono_Field_Mapping::record_form('cf7', '5', 'Contact', array('your-name'));
        Bono_Field_Mapping::record_form('cf7', '5', 'Contact', array('your-name', 'company'));
        $forms = Bono_Field_Mapping::get_known_forms();

        $this->assertContains('company', $forms[0]['fields']);
    }

    public function test_record_form_is_bounded() {
        for ($i = 0; $i < Bono_Field_Mapping::MAX_FORMS + 10; $i++) {
            Bono_Field_Mapping::record_form('cf7', (string) $i, 'F' . $i, array('a', 'b'));
        }
        $this->assertLessThanOrEqual(
            Bono_Field_Mapping::MAX_FORMS,
            count(Bono_Field_Mapping::get_known_forms())
        );
    }

    public function test_apply_overrides_only_mapped_present_roles() {
        $GLOBALS['__bono_options'][Bono_Field_Mapping::MAP_OPTION] = array(
            'cf7:5' => array('name' => 'your-name', 'email' => '', 'phone' => 'phone-1'),
        );
        $detected = array('name' => 'WRONG GUESS', 'email' => 'guess@example.com', 'phone' => '');
        $fields = array('your-name' => 'Dana Cohen', 'your-email' => 'dana@example.com', 'phone-1' => '050-1234567');

        $out = Bono_Field_Mapping::apply('cf7', '5', $detected, $fields);

        $this->assertSame('Dana Cohen', $out['name'], 'mapped name overrides heuristic');
        $this->assertSame('050-1234567', $out['phone'], 'mapped phone overrides heuristic');
        $this->assertSame('guess@example.com', $out['email'], 'unmapped role keeps heuristic');
    }

    public function test_apply_keeps_heuristic_when_mapped_field_missing_or_empty() {
        $GLOBALS['__bono_options'][Bono_Field_Mapping::MAP_OPTION] = array(
            'cf7:5' => array('name' => 'your-name', 'email' => '', 'phone' => 'phone-1'),
        );
        $out = Bono_Field_Mapping::apply('cf7', '5', array('name' => 'Heur', 'email' => '', 'phone' => 'H'), array('your-name' => ''));

        $this->assertSame('Heur', $out['name']);
        $this->assertSame('H', $out['phone']);
    }

    public function test_apply_without_mapping_is_noop() {
        $contact = array('name' => 'X', 'email' => 'y', 'phone' => 'z');
        $this->assertSame($contact, Bono_Field_Mapping::apply('gravity', '9', $contact, array()));
    }

    public function test_save_mappings_accepts_only_known_forms_and_valid_fields() {
        Bono_Field_Mapping::record_form('cf7', '5', 'Contact', array('your-name', 'your-email', 'phone-1'));

        Bono_Field_Mapping::save_mappings(array(
            'cf7:5' => array('name' => 'your-name', 'email' => 'nonexistent-field', 'phone' => 'phone-1'),
            'unknown:999' => array('name' => 'whatever'),
            'cf7:5extra' => array('name' => '', 'email' => '', 'phone' => ''),
        ));
        $saved = Bono_Field_Mapping::get_all_mappings();

        $this->assertArrayHasKey('cf7:5', $saved);
        $this->assertArrayNotHasKey('unknown:999', $saved);
        $this->assertArrayNotHasKey('cf7:5extra', $saved);
        $this->assertSame('your-name', $saved['cf7:5']['name']);
        $this->assertSame('phone-1', $saved['cf7:5']['phone']);
        $this->assertSame('', $saved['cf7:5']['email'], 'field key not on the form is dropped');
    }

    public function test_get_mapping_round_trips() {
        Bono_Field_Mapping::record_form('cf7', '5', 'Contact', array('your-name', 'phone-1'));
        Bono_Field_Mapping::save_mappings(array(
            'cf7:5' => array('name' => 'your-name', 'email' => '', 'phone' => 'phone-1'),
        ));
        $m = Bono_Field_Mapping::get_mapping('cf7', '5');

        $this->assertSame('your-name', $m['name']);
        $this->assertSame('phone-1', $m['phone']);
    }
}
