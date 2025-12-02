<?php
/**
 * Admin field renderer class for variations
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Product;

use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Product\FieldStorage;

defined('ABSPATH') || exit;

/**
 * Handles rendering and saving of custom product fields in variation edit view.
 */
class VariationFieldRenderer {


    /**
     * Render custom fields in each variation tab.
     *
     * @param int     $loop       Loop index.
     * @param array   $variation_data Variation data array.
     * @param WP_Post $variation  Variation post object.
     */
    public function render_variation_fields($loop, $variation_data, $post): void {            
        $product_id = $post->post_parent;        
        $group_slug = Helpers::get_product_group_slug( $product_id );

        echo '<fieldset class="lpf-variation-fields">';
        foreach (Helpers::get_fields_for_group($group_slug) as $field) {
                        
            if (!($field['variation'] ?? false)) {                
                continue;
            }
            if ( !FieldTypeRegistry::supports( $field['type'], 'variations' ) ) {
                continue;
            }            
            echo $this->render_field_by_type($field, $loop, $post->ID );
        }
        echo '</fieldset>';
    }
  

    /**
     * Render a variation field based on its type.
     *
     * For core field types, this delegates to an internal render method (e.g., render_text_field).
     * For external field types, it looks for a 'render_variation_form_cb' callback registered
     * in the FieldTypeRegistry and calls it if available.
     *
     * @param array $field    Field definition.
     * @param int   $loop     Index of the variation in the loop.
     * @param int   $post_id  Post ID of the variation.
     *
     * @return string Rendered HTML for the variation field.
     */
    protected function render_field_by_type(array $field, int $loop, int $post_id): string {
        $type = $field['type'] ?? 'text';

        if ( FieldTypeRegistry::is_core_type( $type ) ) {
            $method = "render_{$type}_field";
            return method_exists($this, $method)
                ? $this->$method($field, $loop, $post_id)
                : $this->render_text_field($field, $loop, $post_id);
        }

        $cb = FieldTypeRegistry::get_callback( $type, 'admin_render_variation_callback' );
        if ( is_callable( $cb ) ) {
            return call_user_func( $cb, $field['slug'], $loop, $post_id, $field );
        }

        return '<p class="form-row"><em>' . esc_html__( 'Unsupported variation field type.', 'luma-product-fields' ) . '</em></p>';
    }


     /**
     * Render a text field for variation.
     *
     * @param array $field
     * @param int $loop
     * @param int $post_id 
     * @return string
     */
    protected function render_text_field(array $field, int $loop, int $post_id): string
    {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $name_attr = 'variable_' . $field['slug'] . '[' . $loop . ']';
        $unit  = $field['unit'] ?? '';

        ob_start();        
        echo "<p class='form-row lpf-fieldtype-text'>";
        echo "<label>{$field['label']}</label>";
        echo ! empty( $field['description'] ) ? wc_help_tip($field['description']) : '';
        echo "<input type='text' name='{$name_attr}' value='" . esc_attr($value) . "' />";
        echo empty($unit) ? '' : ' ' . esc_html($unit);
        echo '</p>';
        return ob_get_clean();
    }
    
    
    /**
     * Render a number field for variation (locale-aware float).
     *
     * @param array $field
     * @param int   $loop
     * @param int   $post_id
     * @return string
     */
    protected function render_number_field(array $field, int $loop, int $post_id): string
    {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $name_attr = 'variable_' . $field['slug'] . '[' . $loop . ']';
        $unit = $field['unit'] ?? '';

        ob_start();
        echo "<p class='form-row lpf-fieldtype-number'>";
        echo "<label>{$field['label']}</label>";
        echo ! empty( $field['description'] ) ? wc_help_tip($field['description']) : '';
        echo "<input type='text' name='{$name_attr}' value='" . esc_attr($value) . "' inputmode='decimal' pattern='[0-9]+([.,][0-9]+)?' />";
        echo empty($unit) ? '' : ' ' . esc_html($unit);
        echo '</p>';
        return ob_get_clean();
    }

    
    /**
     * Render an integer field for variation.
     *
     * @param array $field
     * @param int   $loop
     * @param int   $post_id
     * @return string
     */
    protected function render_integer_field(array $field, int $loop, int $post_id): string
    {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $name_attr = 'variable_' . $field['slug'] . '[' . $loop . ']';
        $unit = $field['unit'] ?? '';

        ob_start();
        echo "<p class='form-row lpf-fieldtype-integer'>";
        echo "<label>{$field['label']}</label>";
        echo ! empty( $field['description'] ) ? wc_help_tip($field['description']) : '';
        echo "<input type='text' name='{$name_attr}' value='" . esc_attr($value) . "' inputmode='numeric' pattern='\\d+' />";
        echo empty($unit) ? '' : ' ' . esc_html($unit);
        echo '</p>';
        return ob_get_clean();
    }

            
    /**
     * Render a min/max field for variation.
     *
     * @param array $field
     * @param int   $loop
     * @param int   $post_id
     * @return string
     */
    protected function render_minmax_field(array $field, int $loop, int $post_id): string
    {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $min = $value['min'] ?? '';
        $max = $value['max'] ?? '';
        $unit = $field['unit'] ?? '';

        $base_name = 'variable_' . $field['slug'] . '[' . $loop . ']';

        ob_start();
        echo "<p class='form-row lpf-fieldtype-minmax'>";
        echo "<label>{$field['label']}</label>";
        echo ! empty( $field['description'] ) ? wc_help_tip($field['description']) : '';
        echo "<span class='lpf-minmax-wrapper'>";
        echo "<span class='label'>Min:</span>";
        echo "<input type='text' name='{$base_name}[min]' value='" . esc_attr($min) . "' inputmode='decimal' pattern='[0-9]+([.,][0-9]+)?' />";
        echo "<span style='margin-left:.5em;' class='label'>Max:</span>";
        echo "<input type='text' name='{$base_name}[max]' value='" . esc_attr($max) . "' inputmode='decimal' pattern='[0-9]+([.,][0-9]+)?' />";
        echo empty($unit) ? '' : ' ' . esc_html($unit);
        echo '</span>';
        echo '</p>';
        return ob_get_clean();
    }


    /**
     * Render a single-select taxonomy field for a variation.
     *
     * @param array $field Field definition.
     * @param int $loop Index of the variation in the loop.
     * @param int $post_id 
     * @return string
     */
    protected function render_single_field(array $field, int $loop, int $post_id ): string {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $terms = get_terms(['taxonomy' => $field['slug'], 'hide_empty' => false]);
        $desc_tip = empty( $field['description'] ) ? false: true;

        ob_start();
        woocommerce_wp_select([
            'id'            => "variable_{$field['slug']}[$loop]",
            'label'         => $field['label'],
            'wrapper_class' => 'form-row form-row-full',
            'options'       => ['' => __('Select', 'luma-product-fields')] + wp_list_pluck($terms, 'name', 'slug'),
            'value'         => $value,
            'desc_tip'      => $desc_tip,
            'description' => $field['description'], 
        ]);
        return ob_get_clean();
    }


    /**
     * Save custom field values for a product variation.
     *
     *
     * @param int $variation_id ID of the variation being saved.
     * @param int $loop         Index of the variation in the variations loop.
     * @return void
     */
    public function save_the_fields(int $variation_id, int $loop): void
    {
        $product = wc_get_product($variation_id);
        $parent_id = $product->get_parent_id();

        if (! $parent_id) {
            return;
        }

        $parent_group_slug = Helpers::get_product_group_slug($parent_id) ?: 'general';
        $fields = Helpers::get_fields_for_group($parent_group_slug);
        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';

            if (! FieldTypeRegistry::supports($type, 'variations')) {
                continue;
            }

            $slug        = $field['slug'];
            $input_name  = 'variable_' . $slug;

            if (isset($_POST[$input_name][$loop])) {
                FieldStorage::save_field($variation_id, $slug, $_POST[$input_name][$loop]);
            }
        }
    }


}




