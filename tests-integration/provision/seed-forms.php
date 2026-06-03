<?php
/**
 * Seed representative forms and store their IDs in option `bono_test_form_ids`.
 * Run via: wp eval-file .../seed-forms.php
 */
$ids = get_option( 'bono_test_form_ids', array() );
if ( ! is_array( $ids ) ) { $ids = array(); }

// --- Contact Form 7 ---
if ( empty( $ids['cf7'] ) && class_exists( 'WPCF7_ContactForm' ) ) {
    $cf7 = WPCF7_ContactForm::get_template( array( 'title' => 'Bono Test CF7' ) );
    $cf7->set_properties( array(
        'form' =>
            "[text* your-name]\n[email* your-email]\n[email your-alt-email]\n[tel your-phone]\n[textarea your-message]\n[submit \"Send\"]",
    ) );
    $cf7_id = $cf7->save();
    // Place it on a published page so page_id/page_url resolve.
    $page_id = wp_insert_post( array(
        'post_title'   => 'Bono Test CF7 Page',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '[contact-form-7 id="' . $cf7_id . '"]',
    ) );
    $ids['cf7']      = (string) $cf7_id;
    $ids['cf7_page'] = (string) $page_id;
}

update_option( 'bono_test_form_ids', $ids );
echo "seeded: " . wp_json_encode( $ids ) . "\n";
