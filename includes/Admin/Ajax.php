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
 * Handles admin AJAX requests for the plugin, using a custom luma_product_fields_action switch.
 *
 * @hook luma_product_fields_incoming_ajax_{$action}
 *       Fires when `luma_product_fields_action` does not correspond with an internal method.
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


    
	/**
	 * WP AJAX action (admin-ajax.php router).
	 *
	 * @var string
	 */
	public const WP_AJAX_ACTION = 'luma_product_fields_ajax';


	/**
	 * Nonce action used with wp_create_nonce() and check_ajax_referer().
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'luma_product_fields_admin_nonce';


	/**
	 * Dispatcher key posted from JS (method name / action key).
	 *
	 * @var string
	 */
	public const DISPATCH_KEY = 'luma_product_fields_action';


    
    public function __construct() {
        add_action( 'wp_ajax_luma_product_fields_ajax', [ $this, 'handle_request' ] );
    }


    /**
     * Request handler for AJAX requests.
     *
     * The value of the `luma_product_fields_action` POST parameter is used to call a method of the same name
     * within this class. If no matching method exists, an action hook is fired to allow other
     * classes or modules to handle the request.
     *
     * @return void
     */
    public function handle_request(): void {

        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'error' => 'Not allowed.' ], 403 );
        }

        // Read only the dispatch key here; handlers are responsible for reading/sanitizing
        // any additional request parameters they need.
        $action = isset( $_POST[ self::DISPATCH_KEY ] )
            ? sanitize_key( (string) wp_unslash( $_POST[ self::DISPATCH_KEY ] ) )
            : '';

        if ( '' === $action || str_starts_with( $action, '__' ) ) {
            wp_send_json_error( [ 'error' => 'Invalid action.' ], 400 );
        }

        if ( method_exists( $this, $action ) && is_callable( [ $this, $action ] ) ) {
            $this->{$action}();
            return;
        }

        /**
         * Fires when `luma_product_fields_action` does not correspond with an internal method.
         *
         * Runs after nonce verification and capability checks in the dispatcher.
         *
         * IMPORTANT: This hook does not receive raw request payload.
         * Handlers should read from wp_unslash( $_POST ) and sanitize expected keys.
         *
         * @hook luma_product_fields_incoming_ajax_{$action}
         *
         * @param string $action Action key.
         */
        do_action( LUMA_PRODUCT_FIELDS_PREFIX . '_incoming_ajax_' . $action, $action );

        wp_send_json_error( [ 'error' => 'Unknown action.' ], 400 );
    }


    /**
     * Update product group fields in the product editor.
     *
     * If product_group is an empty string, this means "No product group set" and
     * should return fields that are not assigned to any product group.
     *
     * @return void
     */
    protected function update_product_group(): void {
        // Nonce verified in handle_request() via check_ajax_referer().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

        if ( ! array_key_exists( 'product_group', $_POST ) ) {
            wp_send_json_error( [ 'error' => 'Missing product_group.' ] );
        }

        $group_slug_raw = isset( $_POST['product_group'] ) ? wp_unslash( $_POST['product_group'] ) : '';
        $group_slug_raw = is_string( $group_slug_raw ) ? $group_slug_raw : '';
        $group_slug     = '' !== $group_slug_raw ? sanitize_key( $group_slug_raw ) : '';

        if ( ! $post_id ) {
            wp_send_json_error( [ 'error' => 'Missing Post ID.' ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'error' => 'Not allowed.' ], 403 );
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'error' => 'Invalid product.' ] );
        }

        $html = '';

        if ( $product->is_type( 'variable' ) ) {
            $reminder_title = esc_html__( 'Reminder:', 'luma-product-fields' );
            $reminder_msg   = esc_html__(
                'After changing the product group, please save and update the product to load the correct fields for each variation.',
                'luma-product-fields'
            );

            $html .= '<div class="notice notice-warning is-dismissible" style="margin:1em;"><p>';
            $html .= '<strong>' . $reminder_title . '</strong> ' . $reminder_msg;
            $html .= '</p></div>';
        }

        $form_html = ( new FieldRenderer() )->render_form_fields( $group_slug, $post_id );

        $html .= wp_kses(
            $form_html,
            wp_kses_allowed_html( 'luma_product_fields_admin_fields' )
        );

        wp_send_json_success( [ 'html' => $html ] );

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }



    /**
     * Handle autocomplete term search for a given taxonomy.
     *
     * @return void
     */
    public function autocomplete_search(): void {
        // Nonce verified in handle_request() via check_ajax_referer().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        $search   = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

        if ( '' === $taxonomy ) {
            wp_send_json_success( [ 'message' => 'Missing taxonomy' ] );
        }

        if ( '' === $search ) {
            wp_send_json_success( [ 'results' => [] ] );
        }

        if ( ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => 'Invalid taxonomy' ] );
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

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }


    /**
     * Return capability flags for a given field type.
     *
     * Used to dynamically show/hide fields like "unit" and "show taxonomy links"
     * based on the selected field type's supported features.
     *
     * @return void
     */
    protected function get_field_type_capabilities(): void {
        // Nonce verified in handle_request() via check_ajax_referer().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $type = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : '';

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

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }


    /**
     * Handles AJAX request to load variation rows for a variable product.
     *
     * @return void
     */
    public function load_variations(): void {
        // Nonce verified in handle_request() via check_ajax_referer().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error( [ 'error' => 'Missing product_id.' ] );
        }

        if ( ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( [ 'error' => 'Not allowed.' ], 403 );
        }

        $product_group_slug = Helpers::get_product_group_slug( $product_id );

        if ( ! $product_group_slug ) {
            wp_send_json_error( [ 'error' => 'Could not determine product group.' ] );
        }

        $table = new ListViewTable( $product_group_slug );
        $html  = $table->load_variations( $product_id );

        wp_send_json_success( $html );

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }

    
    /**
     * AJAX: Render inline edit field for a product/field combination.
     *
     * @return void
     */
    public function inline_edit_render(): void {
        // Nonce verified in handle_request() via check_ajax_referer().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        $field_slug = isset( $_POST['field_slug'] ) ? sanitize_key( wp_unslash( $_POST['field_slug'] ) ) : '';

        if ( ! $product_id || ! $field_slug || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // If the slug is not one of ours, only allow it if explicitly whitelisted.
        $field = Helpers::get_field_definition_by_slug( $field_slug );
        if ( ! $field ) {
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
        if ( ! $product ) {
            wp_send_json_error( 'Invalid product.' );
        }

        $product_name = $product ? $product->get_name() : '';

        $renderer = new \Luma\ProductFields\Product\FieldRenderer();
        $form_html = $renderer->render_form_field( $field_slug, $product_id );

        ob_start();

        echo '<div class="lpf-floating-editor-inner" style="position:relative;top:0;left:0;">';
        echo '<form>';
        echo '<h4>' . esc_html( $product_name ) . '</h4>';

        echo wp_kses( $form_html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );

        echo '<div class="lpf-edit-controls">';
        echo '<button type="button" class="lpf-edit-cancel" aria-label="' . esc_attr__( 'Cancel', 'luma-product-fields' ) . '">&#10005;</button>';
        echo '<button type="button" class="lpf-edit-save" aria-label="' . esc_attr__( 'Save', 'luma-product-fields' ) . '">&#10003;</button>';
        echo '</div>';

        echo '</form></div>';

        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }



    /**
     * AJAX: Save or clear a single custom product field and return refreshed display HTML.
     *
     * @return void
     */
    public function inline_save_field(): void {
        // Nonce verified in handle_request() via check_ajax_referer().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
        $field_slug = isset( $_POST['field_slug'] ) ? sanitize_key( wp_unslash( $_POST['field_slug'] ) ) : '';
        $value      = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

        if ( ! $product_id || ! $field_slug || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( 'Permission denied or invalid data.' );
        }

        $field = Helpers::get_field_definition_by_slug( $field_slug );

        if ( ! $field ) {
            /**
             * Allow external plugins to handle saving for unknown fields.
             *
             * IMPORTANT: This is an action (not a filter). External handlers MUST terminate the request
             * using wp_send_json_success() / wp_send_json_error(). If the handler returns normally,
             * this method will continue and send an "Unknown field" error response.
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

        $updated_html = ListViewTable::render_field_cell_inner( $product_id, $field );
        $safe_html = wp_kses( $updated_html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );

        wp_send_json_success( [ 'html' => $safe_html ] );

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    }
}
