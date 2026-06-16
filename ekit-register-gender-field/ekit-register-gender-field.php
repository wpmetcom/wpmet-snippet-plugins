<?php
/**
 * Plugin Name: EKit Register Form – Gender Field
 * Description: Adds a Gender select field (Male / Female / Other) to the ElementsKit Register Form widget. Saves the value as user meta on registration and registers it as a user contact method.
 * Version:     1.1.0
 * Author:      Snippet
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EKit_Register_Gender_Field {

    public function __construct() {
        // Add 'Gender' to the Field Type dropdown in the Elementor editor repeater
        add_filter( 'elementskit/register_form_fields', [ $this, 'add_gender_field_type' ] );

        // Inject the rendered <select> into the widget HTML (bypasses the switch default: break)
        add_filter( 'elementor/widget/render_content', [ $this, 'inject_gender_select' ], 10, 2 );

        // Register 'gender' as a user contact method (same as 'phone')
        add_filter( 'user_contactmethods', [ $this, 'add_gender_contact_method' ] );

        // Save the submitted gender after wp_create_user() — $_POST is still alive here
        add_action( 'user_register', [ $this, 'save_gender_on_register' ] );

        // Safety-net JS: ensures the select value reaches the AJAX handler
        add_action( 'wp_footer', [ $this, 'output_ajaxprefilter' ], 100 );
    }

    // -----------------------------------------------------------------------
    // 1. Editor — add field type option
    // -----------------------------------------------------------------------

    public function add_gender_field_type( array $fields ): array {
        $fields['user_gender'] = esc_html__( 'Gender', 'ekit-register-gender' );
        return $fields;
    }

    // -----------------------------------------------------------------------
    // 2. Front-end — inject the <select> via PHP into the rendered widget HTML
    // -----------------------------------------------------------------------

    /**
     * Elementor filters the complete widget HTML through this hook before echo.
     * We find the gender field config in the widget settings and build the
     * <select> ourselves, then splice it in before the submit button.
     */
    public function inject_gender_select( string $content, $widget ): string {
        if ( $widget->get_name() !== 'elementskit-register-form' ) {
            return $content;
        }

        $settings = $widget->get_settings_for_display();
        $fields   = $settings['ekit_register_form_fields'] ?? [];

        // Find the first gender field configured in the repeater
        $gender_field = null;
        foreach ( $fields as $field ) {
            if ( ( $field['ekit_register_form_field_type'] ?? '' ) === 'user_gender' ) {
                $gender_field = $field;
                break;
            }
        }

        if ( ! $gender_field ) {
            return $content; // Widget has no gender field — do nothing
        }

        $field_id      = $gender_field['_id'] ?? '';
        $label_text    = $gender_field['ekit_register_form_field_label']       ?? __( 'Gender', 'ekit-register-gender' );
        $placeholder   = $gender_field['ekit_register_form_field_placeholder'] ?? __( '-- Select Gender --', 'ekit-register-gender' );
        $show_labels   = $settings['ekit_register_show_labels']   ?? 'yes';
        $field_size    = $settings['ekit_register_field_size']    ?? 'md';
        $required_mark = $settings['ekit_register_required_mark'] ?? 'yes';

        // -- Build label HTML --
        $label_html = '';
        if ( 'yes' === $show_labels ) {
            $required_span = ( 'yes' === $required_mark )
                ? '<span class="ekit-register-form-required">*</span>'
                : '';
            $label_html = sprintf(
                '<label for="user_gender_%s" class="ekit-register-form-label">%s%s</label>',
                esc_attr( $field_id ),
                esc_html( $label_text ),
                $required_span
            );
        }

        // -- Build <select> HTML --
        $options = [
            ''       => esc_html( $placeholder ),
            'male'   => esc_html__( 'Male',   'ekit-register-gender' ),
            'female' => esc_html__( 'Female', 'ekit-register-gender' ),
            'other'  => esc_html__( 'Other',  'ekit-register-gender' ),
        ];

        $options_html = '';
        foreach ( $options as $value => $text ) {
            $options_html .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr( $value ),
                $text
            );
        }

        $select_html = sprintf(
            '<select name="user_gender" id="user_gender_%s" '
            . 'class="ekit-register-gender-select ekit-register-form-input elementor-field-textual elementor-size-%s" '
            . 'required aria-required="true">%s</select>',
            esc_attr( $field_id ),
            esc_attr( $field_size ),
            $options_html
        );

        // -- Wrap in the same field structure the widget uses --
        $field_html = sprintf(
            '<div class="ekit-register-form-field elementor-repeater-item-%s ekit-register-gender-field">'
            . '%s'
            . '<div class="ekit-register-form-input-group">%s</div>'
            . '</div>',
            esc_attr( $field_id ),
            $label_html,
            $select_html
        );

        // -- Splice in before the submit button wrapper --
        $anchor = '<div class="ekit-register-form-button-wrapper">';
        $content = str_replace( $anchor, $field_html . $anchor, $content );

        return $content;
    }

    // -----------------------------------------------------------------------
    // 3. Admin — contact method
    // -----------------------------------------------------------------------

    public function add_gender_contact_method( array $contactmethods ): array {
        $contactmethods['gender'] = esc_html__( 'Gender', 'ekit-register-gender' );
        return $contactmethods;
    }

    // -----------------------------------------------------------------------
    // 4. Save on registration
    // -----------------------------------------------------------------------

    /**
     * `user_register` fires right after wp_create_user() inside the AJAX handler,
     * so $_POST is still fully populated with the submitted form data.
     */
    public function save_gender_on_register( int $user_id ): void {
        if ( empty( $_POST['user_gender'] ) ) {
            return;
        }

        $allowed = [ 'male', 'female', 'other' ];
        $gender  = sanitize_text_field( wp_unslash( $_POST['user_gender'] ) );

        if ( in_array( $gender, $allowed, true ) ) {
            update_user_meta( $user_id, 'gender', $gender );
        }
    }

    // -----------------------------------------------------------------------
    // 5. Safety-net: guarantee the select value is in the AJAX payload
    // -----------------------------------------------------------------------

    /**
     * Some form-serialisation paths read only <input> elements.
     * This tiny prefilter overrides user_gender with the real select value
     * so it always reaches register-ajax-handler.php.
     */
    public function output_ajaxprefilter(): void {
        ?>
        <style id="ekit-register-gender-css">
        /* Match ElementsKit input sizing/look for the gender select */
        .ekit-register-gender-select {
            display: block;
            width: 100%;
            background-color: #fff;
            border: 1px solid #d4d4d4;
            border-radius: 4px;
            font-size: 14px;
            color: #444;
            cursor: pointer;
            box-sizing: border-box;
        }
        .ekit-register-gender-select:focus {
            outline: none;
            border-color: #7a7a7a;
        }
        .ekit-register-gender-select.elementor-size-xs { padding: 4px 8px;   }
        .ekit-register-gender-select.elementor-size-sm { padding: 6px 10px;  }
        .ekit-register-gender-select.elementor-size-md { padding: 8px 12px;  }
        .ekit-register-gender-select.elementor-size-lg { padding: 10px 14px; }
        .ekit-register-gender-select.elementor-size-xl { padding: 12px 16px; }
        </style>

        <script id="ekit-register-gender-js">
        (function ($) {
            'use strict';

            $.ajaxPrefilter(function (options) {
                if (!options.data) { return; }
                if ((options.type || '').toUpperCase() !== 'POST') { return; }

                var isTarget  = false;
                var dataIsObj = (typeof options.data === 'object');

                if (dataIsObj && options.data.action === 'ekit_register_user') {
                    isTarget = true;
                } else if (typeof options.data === 'string' &&
                           options.data.indexOf('action=ekit_register_user') !== -1) {
                    isTarget = true;
                }

                if (!isTarget) { return; }

                var gender = $('select[name="user_gender"]').first().val() || '';

                if (dataIsObj) {
                    options.data.user_gender = gender;
                } else {
                    options.data = options.data.replace(/&?user_gender=[^&]*/g, '');
                    options.data += '&user_gender=' + encodeURIComponent(gender);
                }
            });

        }(jQuery));
        </script>
        <?php
    }
}

new EKit_Register_Gender_Field();
