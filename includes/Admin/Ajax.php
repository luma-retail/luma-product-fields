<?php
/**
 * Ajax class
 *
 * @package Luma\ProductFields
 * @since   1.0.0
 */
namespace Luma\ProductFields\Admin;

defined( 'ABSPATH' ) || exit;

use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Product\FieldRenderer;
use Luma\ProductFields\Product\FieldStorage;
use Luma\ProductFields\Registry\FieldTypeRegistry;

/**
 * Ajax class
 *
 * Handles admin AJAX requests for the plugin, using a custom lpf_action switch.
 *
 * @hook luma_product_fields_incoming_ajax_{$action}
 *       Fires when `lpf_action` does not correspond with an internal method.
 *
 * @hook luma_product_fields_allow_external_field_slug
 *       Allow external plugins to mark a slug as editable in the ListView,
 *       even if it is not registered as a Luma Product Fields field.
 *
 * @hook luma_product_fields_inline_save_field
 *       Fired when inline editor attempts to save a field that is not registered.
 *       To allow external plugins to save fields via the inline editor.
 */
class Ajax {


    public function __construct() {
        add_action( 'wp_ajax_luma_product_fields_ajax', [ $this, 'handle_request' ] );
    }


    /**
     * Request handler for AJAX requests.
     *
     * The value of the `lpf_action` POST parameter is used to call a method of the same name
     * within this class. If no matching method exists, an action hook is fired to allow other
     * classes or modules to handle the request.
     *
     * @since 3.x
     *
     * @return void
     */
    public function handle_request(): void {
        check_ajax_referer( 'luma_product_fields_admin_nonce', 'nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $action_raw = isset( $_POST['lpf_action'] ) ? wp_unslash( $_POST['lpf_action'] ) : '';   // phpcs:ignore 
        $action     = sanitize_text_field( $action_raw );

        if ( method_exists( $this, $action ) ) {
            $this->{$action}();
            return;
        }

        do_action( 'luma_product_fields_incoming_ajax_' . $action );

        wp_send_json_error( [ 'error' => 'Unknown lpf_action: ' . $action ] );
    }


    /**
     * Update product group fields in the product editor.
     *
     * @return void
     */
    protected function update_product_group(): void {
        // Nonce is verified in handle_request().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $group_raw  = isset( $_POST['product_group'] ) ? wp_unslash( $_POST['product_group'] ) : '';   // phpcs:ignore 
        $group_slug = sanitize_text_field( $group_raw );

        if ( ! $post_id || '' === $group_slug ) {
            wp_send_json_error( [ 'error' => 'Missing post_id or product_group.' ] );
        }

        $html    = '';
        $product = wc_get_product( $post_id );

        if ( $product && $product->is_type( 'variable' ) ) {
            $reminder_title = esc_html__( 'Reminder:', 'luma-product-fields' );
            $reminder_msg   = esc_html__( 'After changing the product group, please save and update the product to load the correct fields for each variation.', 'luma-product-fields' );

            $notice  = '<div class="notice notice-warning is-dismissible" style="margin:1em;"><p>';
            $notice .= '<strong>' . $reminder_title . '</strong> ' . $reminder_msg;
            $notice .= '</p></div>';

            $html .= $notice;
        }

        $html .= ( new FieldRenderer() )->render_form_fields( $group_slug, $post_id );

        wp_send_json_success( [ 'html' => $html ] );
    }


    /**
     * Handle autocomplete term search for a given taxonomy.
     *
     * @return void
     */
    public function autocomplete_search(): void {
        // Nonce is verified in handle_request().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $taxonomy_raw = isset( $_POST['taxonomy'] ) ? wp_unslash( $_POST['taxonomy'] ) : '';  // phpcs:ignore 
        $taxonomy     = sanitize_text_field( $taxonomy_raw );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $search_raw = isset( $_POST['term'] ) ? wp_unslash( $_POST['term'] ) : '';   // phpcs:ignore 
        $search     = sanitize_text_field( $search_raw );

        if ( '' === $taxonomy ) {
            wp_send_json_error( [ 'message' => 'Missing taxonomy' ] );
        }

        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'name__like' => $search,
                'number'     => 20,
            ]
        );

        $results = array_map(
            static function ( $term ) {
                return [
                    'slug' => $term->slug,
                    'name' => $term->name,
                ];
            },
            is_array( $terms ) ? $terms : []
        );

        wp_send_json_success( [ 'results' => $results ] );
    }


    /**
     * Return capability flags for a given field type.
     *
     * Used to dynamically show/hide fields like "unit" and "show taxonomy links"
     * based on the selected field type's supported features.
     *
     * Expects POST:
     * - field_type (string)
     *
     * @return void
     */
    protected function get_field_type_capabilities(): void {
        // Nonce is verified in handle_request().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $type_raw = isset( $_POST['field_type'] ) ? wp_unslash( $_POST['field_type'] ) : '';  // phpcs:ignore 
        $type     = sanitize_text_field( $type_raw );

        if ( ! FieldTypeRegistry::get( $type ) ) {
            wp_send_json_error( [ 'message' => 'Invalid field type' ] );
        }

        wp_send_json_success(
            [
                'supports_unit'       => FieldTypeRegistry::supports( $type, 'unit' ),
                'supports_links'      => FieldTypeRegistry::supports( $type, 'link' ),
                'supports_variations' => FieldTypeRegistry::supports( $type, 'variations' ),
            ]
        );
    }


    /**
     * Handles AJAX request to load variation rows for a variable product.
     *
     * @return void
     */
    public function load_variations(): void {
        // Nonce is verified in handle_request().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error( [ 'error' => 'Missing product_id.' ] );
        }

        $product_group_slug = Helpers::get_product_group_slug( $product_id );

        if ( ! $product_group_slug ) {
            wp_send_json_error( [ 'error' => 'Could not determine product group.' ] );
        }

        $table = new ListViewTable( $product_group_slug );
        $html  = $table->load_variations( $product_id );

        wp_send_json_success( $html );
    }


    /**
     * AJAX: Render inline edit field for a product/field combination.
     *
     * @return void
     */
    public function inline_edit_render(): void {
        // Nonce is verified in handle_request().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $field_slug_raw = isset( $_POST['field_slug'] ) ? wp_unslash( $_POST['field_slug'] ) : '';   // phpcs:ignore 
        $field_slug     = sanitize_key( $field_slug_raw );

        if ( ! $product_id || ! $field_slug || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $field = Helpers::get_field_definition_by_slug( $field_slug );

        if ( ! $field ) {
            /**
             * Allow external plugins to mark a slug as editable in the ListView,
             * even if it is not registered as a Luma Product Fields field.
             *
             * @filter luma_product_fields_allow_external_field_slug
             *
             * @param bool   $allowed    Whether this slug should be accepted.
             * @param string $field_slug Requested field slug.
             *
             * @return bool
             */
            $allowed = apply_filters(
                'luma_product_fields_allow_external_field_slug',
                false,
                $field_slug
            );

            if ( ! $allowed ) {
                wp_send_json_error( 'Unknown field.' );
            }
        }

        $product      = wc_get_product( $product_id );
        $product_name = $product ? $product->get_name() : '';

        $renderer = new FieldRenderer();

        ob_start();
        echo '<div class="lpf-floating-editor-inner" style="position:relative;top:0;left:0;">';
        echo '<form>';
        echo '<h4>';
        echo esc_html( $product_name );
        echo '</h4>';

        // This returns safe, fully-escaped HTML.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $renderer->render_form_field( $field_slug, $product_id );

        echo '<div class="lpf-edit-controls">';
        echo '<button class="lpf-edit-cancel" aria-label="Cancel">&#10005;</button>';
        echo '<button class="lpf-edit-save" aria-label="Save">&#10003;</button>';
        echo '</div>';
        echo '</form></div>';

        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }


    /**
     * AJAX: Save or clear a single custom product field and return refreshed display HTML.
     *
     * Expects:
     * - $_POST['product_id'] (int)
     * - $_POST['field_slug'] (string)
     * - $_POST['value'] (mixed) optional
     *
     * @since 3.x
     * @return void
     */
    public function inline_save_field(): void {
        // Nonce is verified in handle_request().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $field_slug_raw = isset( $_POST['field_slug'] ) ? wp_unslash( $_POST['field_slug'] ) : '';   // phpcs:ignore 
        $field_slug     = sanitize_key( $field_slug_raw );

        // Value is unslashed only; type-specific sanitization is handled inside FieldStorage::save_field().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

        if ( ! $product_id || ! $field_slug || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( 'Permission denied or invalid data.' );
        }

        $field = Helpers::get_field_definition_by_slug( $field_slug );

        if ( ! $field ) {
            /**
             * Allow external plugins to handle saving for unknown fields.
             *
             * @hook luma_product_fields_inline_save_field
             *
             * @param int    $product_id
             * @param string $field_slug
             * @param mixed  $value
             */
            do_action( 'luma_product_fields_inline_save_field', $product_id, $field_slug, $value );
            wp_send_json_error( 'Unknown field.' );
        }

        $ok = FieldStorage::save_field( $product_id, $field_slug, $value );

        if ( ! $ok ) {
            wp_send_json_error( 'Could not save field value.' );
        }

        // This returns safe, fully-escaped HTML.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $updated_html = ListViewTable::render_field_cell( $product_id, $field );

        wp_send_json_success( [ 'html' => $updated_html ] );
    }
}
