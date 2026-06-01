<?php
/**
 * Per-form contact field mapping.
 *
 * The capture classes detect name/email/phone heuristically. When the heuristic
 * guesses wrong for an unusually-labelled form, an admin can override it here by
 * mapping a specific submitted field to a contact role. The override is applied
 * to the `contact` object the plugin already sends — no API contract change.
 *
 * Two options back this:
 *  - bono_known_forms:    a learned registry of forms the plugin has captured,
 *                         storing only field KEYS (never values) so the settings
 *                         UI can present a mapping table. Populated as traffic
 *                         arrives.
 *  - bono_field_mappings: the admin-chosen role -> field-key overrides.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_Field_Mapping {
	/**
	 * Option holding the learned form registry.
	 */
	const FORMS_OPTION = 'bono_known_forms';

	/**
	 * Option holding the admin-chosen field mappings.
	 */
	const MAP_OPTION = 'bono_field_mappings';

	/**
	 * Contact roles that can be mapped.
	 */
	const ROLES = array( 'name', 'email', 'phone' );

	/**
	 * Cap the number of remembered forms to keep the option bounded.
	 */
	const MAX_FORMS = 50;

	/**
	 * Cap remembered field keys per form.
	 */
	const MAX_FIELDS_PER_FORM = 60;

	/**
	 * Build the stable registry/mapping key for a form.
	 *
	 * @param string $provider Provider slug.
	 * @param string $form_id Form ID.
	 * @return string
	 */
	public static function form_key( $provider, $form_id ) {
		return sanitize_key( (string) $provider ) . ':' . sanitize_text_field( (string) $form_id );
	}

	/**
	 * Remember a captured form and its field keys (keys only, no values).
	 *
	 * Writes only when the recorded signature changes, to avoid an option write
	 * on every submission.
	 *
	 * @param string $provider Provider slug.
	 * @param string $form_id Form ID.
	 * @param string $form_name Form display name.
	 * @param array  $field_keys Normalized field keys present in the submission.
	 * @return void
	 */
	public static function record_form( $provider, $form_id, $form_name, array $field_keys ) {
		$key = self::form_key( $provider, $form_id );

		if ( ':' === $key || '' === trim( $key, ':' ) ) {
			return;
		}

		$clean_keys = array();

		foreach ( $field_keys as $field_key ) {
			$field_key = sanitize_text_field( (string) $field_key );

			if ( '' !== $field_key && ! in_array( $field_key, $clean_keys, true ) ) {
				$clean_keys[] = $field_key;
			}

			if ( count( $clean_keys ) >= self::MAX_FIELDS_PER_FORM ) {
				break;
			}
		}

		$record = array(
			'provider'  => sanitize_key( (string) $provider ),
			'form_id'   => sanitize_text_field( (string) $form_id ),
			'form_name' => sanitize_text_field( (string) $form_name ),
			'fields'    => $clean_keys,
		);

		$forms = self::get_known_forms_raw();

		if (
			isset( $forms[ $key ] ) &&
			$forms[ $key ]['form_name'] === $record['form_name'] &&
			$forms[ $key ]['fields'] === $record['fields']
		) {
			// No change — skip the write.
			return;
		}

		// Re-insert at the end so the most recently seen forms are kept on prune.
		unset( $forms[ $key ] );
		$forms[ $key ] = $record;

		// Prune oldest entries (front of the array) down to the cap.
		$overflow = count( $forms ) - self::MAX_FORMS;

		for ( $i = 0; $i < $overflow; $i++ ) {
			array_shift( $forms );
		}

		update_option( self::FORMS_OPTION, $forms, false );
	}

	/**
	 * Get the learned form registry as a list (newest last).
	 *
	 * @return array[] List of records: provider, form_id, form_name, fields, key.
	 */
	public static function get_known_forms() {
		$forms = self::get_known_forms_raw();
		$list  = array();

		foreach ( $forms as $key => $record ) {
			$record['key'] = $key;
			$list[]        = $record;
		}

		return $list;
	}

	/**
	 * Get the saved mapping for a form.
	 *
	 * @param string $provider Provider slug.
	 * @param string $form_id Form ID.
	 * @return array{name:string,email:string,phone:string}
	 */
	public static function get_mapping( $provider, $form_id ) {
		$all = self::get_all_mappings();
		$key = self::form_key( $provider, $form_id );

		return isset( $all[ $key ] ) ? $all[ $key ] : self::empty_mapping();
	}

	/**
	 * Get all saved mappings keyed by form key.
	 *
	 * @return array
	 */
	public static function get_all_mappings() {
		$stored = get_option( self::MAP_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Apply a saved mapping over a detected contact, overriding only roles that
	 * are mapped to a field present (and non-empty) in the submission.
	 *
	 * @param string $provider Provider slug.
	 * @param string $form_id Form ID.
	 * @param array  $contact Detected contact (name/email/phone).
	 * @param array  $fields Normalized submitted fields.
	 * @return array
	 */
	public static function apply( $provider, $form_id, array $contact, array $fields ) {
		$mapping = self::get_mapping( $provider, $form_id );

		foreach ( self::ROLES as $role ) {
			$mapped_key = isset( $mapping[ $role ] ) ? (string) $mapping[ $role ] : '';

			if ( '' === $mapped_key ) {
				continue;
			}

			if ( isset( $fields[ $mapped_key ] ) && '' !== (string) $fields[ $mapped_key ] ) {
				$contact[ $role ] = $fields[ $mapped_key ];
			}
		}

		return $contact;
	}

	/**
	 * Sanitize and persist raw mappings submitted from the settings form.
	 *
	 * Expected shape: $raw[form_key][role] = field_key.
	 *
	 * @param array $raw Raw POST mappings.
	 * @return void
	 */
	public static function save_mappings( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$known = self::get_known_forms_raw();
		$clean = array();

		foreach ( $raw as $form_key => $roles ) {
			$form_key = sanitize_text_field( (string) $form_key );

			// Only accept mappings for forms we actually know about, and only
			// field keys that exist on that form.
			if ( ! isset( $known[ $form_key ] ) || ! is_array( $roles ) ) {
				continue;
			}

			$valid_fields = $known[ $form_key ]['fields'];
			$entry        = array();

			foreach ( self::ROLES as $role ) {
				$field_key = isset( $roles[ $role ] ) ? sanitize_text_field( (string) $roles[ $role ] ) : '';

				if ( '' !== $field_key && in_array( $field_key, $valid_fields, true ) ) {
					$entry[ $role ] = $field_key;
				} else {
					$entry[ $role ] = '';
				}
			}

			// Skip forms with no override at all.
			if ( '' !== $entry['name'] || '' !== $entry['email'] || '' !== $entry['phone'] ) {
				$clean[ $form_key ] = $entry;
			}

			if ( count( $clean ) >= self::MAX_FORMS ) {
				break;
			}
		}

		update_option( self::MAP_OPTION, $clean, false );
	}

	/**
	 * Raw registry option as an associative array.
	 *
	 * @return array
	 */
	private static function get_known_forms_raw() {
		$stored = get_option( self::FORMS_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * An empty mapping with all roles unset.
	 *
	 * @return array{name:string,email:string,phone:string}
	 */
	private static function empty_mapping() {
		return array(
			'name'  => '',
			'email' => '',
			'phone' => '',
		);
	}
}
