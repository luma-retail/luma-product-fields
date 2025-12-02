<?php
/**
 * Ajax class
 *
 * @package Luma\ProductFields
 * @since   1.0.0
 */
namespace Luma\ProductFields\Admin;

defined('ABSPATH') || exit;

use Luma\ProductFields\Admin\FieldOptionsEditor;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Product\FieldRenderer;
use Luma\ProductFields\Frontend\FieldRenderer as FrontEndRenderer;
use Luma\ProductFields\Product\FieldStorage;
use Luma\ProductFields\Registry\FieldTypeRegistry;

/**
* Ajax class
* 
* Handles admin AJAX requests for the plugin, using a custom fk_action switch.
*
* @hook Luma\ProductFields\incoming_ajax_{$action}
*       Fires when `fk_action` does not correspond with an internal method.
*
*       Example usage:
*
*       add_action( 'Luma\ProductFields\incoming_ajax_my_custom_action', function() {
*           // Handle custom AJAX request.
*       } ); 
*
* @hook Luma\ProductFields\allow_external_field_slug
*       Allow external plugins to mark a slug as editable in the ListView,
*       even if it is not registered as a Luma Product Fields field.
*       @param bool   $allowed    Whether this slug should be accepted.
*       @param string $field_slug Requested field slug.
*
*       @return bool
*
* @hook Luma\ProductFields\inline_save_field
*       Fired when inline editor attempts to save a field that is not registered.
*       To allow external plugins to save field with inline editor.
*       @param int $product_id
*       @param string $field_slug
*       @param string $value
*
*/
class Ajax {

    public function __construct() {
        add_action('wp_ajax_luma_product_fields_ajax', [$this, 'handle_request']);        
    }


    /**
     * Request handler for AJAX requests.
     *
     * The value of the `fk_action` POST parameter is used to call a method of the same name
     * within this class. If no matching method exists, a namespaced action hook is fired
     * to allow other classes or modules to handle the request.
     *
     * @hook Luma\ProductFields\incoming_ajax_{$action}
     *       Fires when `fk_action` does not correspond with an internal method.
     *
     *       Example usage:
     *
     *       add_action( 'Luma\ProductFields\incoming_ajax_my_custom_action', function() {
     *           // Handle custom AJAX request.
     *       } );
     *
     * @since 3.x
     *
     * @return void
     */
    public function handle_request() {            
        check_ajax_referer( 'luma_product_fields_admin_nonce', 'nonce' );
        $action = sanitize_text_field( $_POST['lpf_action'] ?? '' );
        if ( method_exists( $this, $action ) ) {
            return $this->$action();
        }
        do_action( 'Luma\ProductFields\incoming_ajax_' . $action );            
        wp_send_json_error( [ 'error' => 'Unknown lpf_action: ' . $action ] );
    }

        
    /** 
    * Update product group fields
    */    
    protected function update_product_group() {
        $post_id    = (int) $_POST['post_id'];
        $group_slug = sanitize_text_field( $_POST['product_group'] );
        $html       = '';
        $product = wc_get_product( $post_id );

        if ( $product && $product->is_type( 'variable' ) ) {
            $reminder_title = esc_html__( 'Reminder:', 'luma-product-fields' );
            $reminder_msg   = esc_html__( 'After changing the product group, please save and update the product to load the correct fields for each variation.', 'luma-product-fields' );

            $notice  = '<div class="notice notice-warning is-dismissible" style="margin:1em;"><p>';
            $notice .= '<strong>' . $reminder_title . '</strong> ' . $reminder_msg;
            $notice .= '</p></div>';

            $html .= $notice;
        }
        $html .= ( new FieldRenderer )->render_form_fields( $group_slug, $post_id );
        wp_send_json_success( [ 'html' => $html ] );
    }
        
    /**
     * Handle autocomplete term search for a given taxonomy.
     *
     * @return void
     */
    public function autocomplete_search(): void
    {
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        $search = sanitize_text_field($_POST['term'] ?? '');

        if (empty($taxonomy)) {
            wp_send_json_error(['message' => 'Missing taxonomy']);
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'name__like' => $search,
            'number' => 20,
        ]);

        $results = array_map(function ($term) {
            return [
                'slug' => $term->slug,
                'name' => $term->name,
            ];
        }, $terms);

        wp_send_json_success(['results' => $results]);
    }
    
    
    
    /**
     * Return capability flags for a given field type.
     *
     * Used to dynamically show/hide fields like "unit" and "show taxonomy links"
     * based on the selected field type's supported features.
     *
     * Expects POST parameters:
     * - field_type (string): The field type slug.
     *
     * Returns a JSON response with:
     * - success (bool)
     * - data (array):
     *     - supports_unit (bool): Whether the field type supports unit selection.
     *     - supports_links (bool): Whether the field type supports taxonomy term linking.
     *
     * @return void
     */
    protected function get_field_type_capabilities() {
        $type = sanitize_text_field($_POST['field_type'] ?? '');

        if (!\Luma\ProductFields\Registry\FieldTypeRegistry::get($type)) {
            wp_send_json_error(['message' => 'Invalid field type']);
        }

        wp_send_json_success([
            'supports_unit'  => \Luma\ProductFields\Registry\FieldTypeRegistry::supports($type, 'unit'),
            'supports_links' => \Luma\ProductFields\Registry\FieldTypeRegistry::supports($type, 'link'),
            'supports_variations' => \Luma\ProductFields\Registry\FieldTypeRegistry::supports($type, 'variations'),
        ]);
    }


	/**
	 * Handles AJAX request to load variation rows for a variable product.
	 *
	 * @return void
	 */
	public function load_variations() {
		$product_id         = absint( $_POST['product_id'] ?? 0 );
        $product_group_slug = Helpers::get_product_group_slug( $product_id );

        if ( ! $product_group_slug ) {
            wp_send_json_error( [ 'error' => 'Could not determine product group.' ] );
        }



		if ( ! $product_id || ! $product_group_slug ) {
			wp_send_json_error( [ 'error' => 'Missing product_id or product_group.' ] );
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
    public function inline_edit_render() {
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $field_slug = sanitize_key( $_POST['field_slug'] ?? '' );

        if ( ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $field = \Luma\ProductFields\Utils\Helpers::get_field_definition_by_slug( $field_slug );
        if ( ! $field ) {

            /**
             * Allow external plugins to mark a slug as editable in the ListView,
             * even if it is not registered as a Luma Product Fields field.
             *
             * @filter Luma\ProductFields\allow_external_field_slug
             *
             * @param bool   $allowed    Whether this slug should be accepted.
             * @param string $field_slug Requested field slug.
             *
             * @return bool
             */
            $allowed = apply_filters(
                'Luma\ProductFields\allow_external_field_slug',
                false,
                $field_slug
            );

            if ( ! $allowed ) {
                wp_send_json_error( 'Unknown field.' );
            }
        }

        $product = wc_get_product( $product_id );
        $product_name = $product ? $product->get_name() : '';
        $renderer = new FieldRenderer();
        
        ob_start();
        echo '<div class="lpf-floating-editor-inner" style="position:relative;top:0;left:0;">';
        echo '<form>';
        echo '<h4>';
        echo $product ? $product->get_name() : '';
        echo '</h4>';
        
        echo $renderer->render_form_field( $field_slug, $product_id );

        // Add save + cancel buttons
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
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $field_slug = sanitize_key( $_POST['field_slug'] ?? '' );
        $value      = $_POST['value'] ?? '';
        

        if ( ! $product_id || ! $field_slug || ! current_user_can( 'edit_post', $product_id ) ) {
            wp_send_json_error( 'Permission denied or invalid data.' );
        }

        $field = Helpers::get_field_definition_by_slug( $field_slug );
        if ( ! $field ) {
            do_action( 'Luma\ProductFields\inline_save_field', $product_id, $field_slug, $value );
            wp_send_json_error( 'Unknown field.' );
        }
        

        $ok = FieldStorage::save_field( $product_id, $field_slug, $value );

        if ( ! $ok ) {
            wp_send_json_error( 'Could not save field value.' );
        }

        $updated_html = ListViewTable::render_field_cell( $product_id, $field );
        wp_send_json_success( [ 'html' => $updated_html ] );
    }
}
