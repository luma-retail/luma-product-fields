<?php
/**
 * Admin field renderer class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Product;

use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Product\FieldStorage;
use Luma\ProductFields\Registry\FieldTypeRegistry;


defined('ABSPATH') || exit;


/**
 * Admin field renderer class
 * 
 * Handles rendering and saving of custom product fields in the WooCommerce product editor.
 *
 *
 * @hook Luma\ProductFields\allow_external_field_slug
 *      Filter to allow external plugins to define non-HPF slugs that should
 *      be rendered as simple text fields in the inline editor.
 *      @param bool   $allowed Whether the slug is allowed.
 *      @param string $slug    Requested field slug.
 */
class FieldRenderer
{


    /**
     * Add custom WooCommerce product data tab.
     *
     * @param array $tabs Existing product tabs.
     *
     * @return array Modified tabs.
     */
    public function add_product_data_tab(array $tabs): array {
        $tabs['lpf-product-data'] = [
            'label'    => __('Product Fields', 'luma-product-fields'),
            'target'   => 'lpf_product_data',
            'priority' => 80,
        ];
        return $tabs;
    }


    /**
     * Display custom product data panel content.
     *
     * @return void
     */
    public function display_product_data_fields_content(): void {
    
        global $post;
        ?>
        <div id="lpf_product_data" class="panel woocommerce_options_panel">
            <div class="toolbar toolbar-top options-group">
                <p class="form-field">
                    <label><?php _e('Product group', 'luma-product-fields'); ?></label>
                    <?php echo (new Admin())->get_product_group_select('lpf-product-group-select', Helpers::get_product_group_slug($post->ID), __('No group set', 'luma-product-fields') ); ?>
                </p>
                <div id="lpf-product-group-fields">
                    <?php echo $this->render_form_fields(Helpers::get_product_group_slug($post->ID), $post->ID); ?>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Render all fields for the given product group or "general".
     *
     * "general" = product has no group → show global fields only.
     *
     *
     * @param string $slug    Product group slug or "general".
     * @param int    $post_id Product ID.
     * @return string Rendered HTML.
     */
    public function render_form_fields( string $slug, int $post_id ): string
    {
        $fields = Helpers::get_fields_for_group( $slug );    
        ob_start();

        if ( ! empty( $fields ) ) {
            foreach ( $fields as $field ) {
                echo $this->render_field_by_type( $field, $post_id );
            }
        } else {
            // When slug == "general": product has no group → no message.
            if ( $slug !== 'general' ) {
                echo '<p class="form-field">' .
                    esc_html__( 'No fields defined yet for this product group', 'luma-product-fields' ) .
                '</p>';
            }
        }

        return ob_get_clean();
    }


    /**
     * Render a single form field for a given slug and product ID.
     *
     * @param string $slug     Field slug.
     * @param int    $post_id  Product ID.
     *
     * @return string Rendered HTML.
     */
public function render_form_field( string $slug, int $post_id ): string {                
    $field = Helpers::get_field_definition_by_slug( $slug );

    if ( ! $field ) {

        /**
         * Allow external plugins to define non-HPF slugs that should
         * be rendered as simple text fields in the inline editor.
         *
         * @filter Luma\ProductFields\allow_external_field_slug
         *
         * @param bool   $allowed Whether the slug is allowed.
         * @param string $slug    Requested field slug.
         */
        $allowed = apply_filters(
            'Luma\ProductFields\allow_external_field_slug',
            false,
            $slug
        );

        if ( ! $allowed ) {
            return '<p class="form-field"><em>' . __FUNCTION__ . esc_html__( ' Unknown field.', 'luma-product-fields' ) . '</em></p>';
        }

        // Minimal external text-field definition
        $field = [
            'slug'  => $slug,
            'label' => ucfirst( $slug ),
            'type'  => 'text',
            'unit'  => '',
        ];
    }

    return $this->render_field_by_type( $field, $post_id );
}


    /**
     * Render a product field based on its type.
     *
     * This method delegates rendering to an internal method for core field types
     * (e.g., render_text_field, render_number_field). For non-core types, it attempts
     * to resolve a custom rendering callback via the 'render_product_form_cb' key
     * in the FieldTypeRegistry and invokes it if available.
     *
     * @param array $field    Field definition array.
     * @param int   $post_id  Post ID of the product.
     *
     * @return string Rendered HTML for the field.
     */
    protected function render_field_by_type(array $field, int $post_id): string {            
        $type = $field['type'] ?? 'text';
        if ( FieldTypeRegistry::is_core_type( $type ) ) {
            $method = "render_{$type}_field";
            return method_exists($this, $method)
                ? $this->$method($field, $post_id)
                : $this->render_text_field($field, $post_id); // fallback for unknown core
        }
        $cb = FieldTypeRegistry::get_callback( $type, 'admin_edit_cb' );
        
        if ( is_callable( $cb ) ) {
            return call_user_func( $cb, $field['slug'], $post_id, $field );
        }

        return '<p class="form-field"><em>' . __FUNCTION__ .  '&nbsp;' . esc_html__( ' Unsupported field type: ', 'luma-product-fields' ) . '</em>' . $type . '</p>';
    }

    /**
     * Render a text field.
     */
    protected function render_text_field(array $field, int $post_id): string {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $unit = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );
        ob_start();        
        echo "<p class='form-field lpf-fieldtype-text'>";
        echo "<label>{$field['label']}";
        echo ! empty( $field['description'] ) ? wc_help_tip($field['description']) : '';
        echo "</label>";
        echo "<input type='text' name='lpf-{$field['slug']}' value='" . esc_attr($value) . "' />";
        echo $unit;
        echo "</p>";
        return ob_get_clean();
    }
    

    /**
     * Render a localized float (decimal) input field.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string HTML markup for the field.
     */
    protected function render_number_field(array $field, int $post_id): string {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $unit = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );
        $tip   = ! empty( $field['description'] ) ? wc_help_tip( $field['description'] ) : '';

        ob_start();
        ?>
        <p class="form-field lpf-fieldtype-number">
            <label>
                <?= esc_html( $field['label'] ) ?>
                <?= $tip ?>
            </label>
            <input
                type="text"
                name="lpf-<?= esc_attr( $field['slug'] ) ?>"
                value="<?= esc_attr( $value ) ?>"
                inputmode="decimal"
                pattern="[0-9]+([.,][0-9]+)?"
            />
            <?= $unit ?>
        </p>
        <?php
        return ob_get_clean();
    }


    /**
     * Render an integer-only input field.
     */
    protected function render_integer_field(array $field, int $post_id): string {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $unit = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );
        $tip   = ! empty( $field['description'] ) ? wc_help_tip( $field['description'] ) : '';
        ob_start();
        ?>
        <p class="form-field lpf-fieldtype-integer">
            <label>
                <?= esc_html($field['label']) ?>
                <?= $tip ?>
            </label>
            <input
                type="text"
                name="lpf-<?= esc_attr($field['slug']) ?>"
                value="<?= esc_attr($value) ?>"
                inputmode="numeric"
                pattern="\d+"
            />
            <?= $unit ?>
        </p>
        <?php
        return ob_get_clean();
    }


    /**
     *
     *
     * Render a min/max field supporting localized floats.
     *
     */
    protected function render_minmax_field(array $field, int $post_id): string {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $min = $value['min'] ?? '';
        $max = $value['max'] ?? '';
        $tip   = ! empty( $field['description'] ) ? wc_help_tip( $field['description'] ) : '';
        $unit = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );

        ob_start();
        ?>
        <p class="form-field lpf-fieldtype-minmax">
            <label>
                <?= esc_html($field['label']) ?>
                <?= $tip ?>
            </label>
            <span class="lpf-minmax-wrapper">
                <span class="label">Min:</span>
                <input
                    type="text"
                    name="lpf-<?= esc_attr($field['slug']) ?>[min]"
                    value="<?= esc_attr($min) ?>"
                    inputmode="decimal"
                    pattern="[0-9]+([.,][0-9]+)?"
                />
                <span style="margin-left:.5em;" class="label">Max:</span>
                <input
                    type="text"
                    name="lpf-<?= esc_attr($field['slug']) ?>[max]"
                    value="<?= esc_attr($max) ?>"
                    inputmode="decimal"
                    pattern="[0-9]+([.,][0-9]+)?"
                />
                <?= $unit ?>
            </span>
        </p>
        <?php
        return ob_get_clean();
    }



    /**
     *
     * Render a select dropdown for single taxonomy term.
     *
     */
    protected function render_single_field(array $field, int $post_id): string {
        $value = Helpers::get_field_value($post_id, $field['slug']);
        $terms = get_terms(['taxonomy' => $field['slug'], 'hide_empty' => false]);
        $tip   = ! empty( $field['description'] ) ? wc_help_tip( $field['description'] ) : '';

        $options = array_map(fn($term) =>
            sprintf(
                "<option value='%s'%s>%s</option>",
                esc_attr($term->slug),
                selected($term->slug, $value, false),
                esc_html($term->name)
            ), $terms
        );

        if ($value === '' || $value === null) {
            array_unshift($options, sprintf(
                "<option value='' selected>%s</option>",
                esc_html__('Value not set', 'luma-product-fields')
            ));
        } else {
            array_unshift($options, sprintf(
                "<option value=''>%s</option>",
                esc_html__('Value not set', 'luma-product-fields')
            ));
        }

        return sprintf(
            "<p class='form-field lpf-fieldtype-single'><label>%s%s</label><select name='lpf-%s'>%s</select></p>",
            esc_html($field['label']),
            $tip,
            esc_attr($field['slug']),
            implode('', $options)
        );
    }


    /**
     * Render checkboxes for multiple taxonomy terms.
     */
    protected function render_multiple_field(array $field, int $post_id): string {
        $values = (array) Helpers::get_field_value($post_id, $field['slug']);
        $terms = get_terms(['taxonomy' => $field['slug'], 'hide_empty' => false]);
        $tip   = ! empty( $field['description'] ) ? wc_help_tip( $field['description'] ) : '';


        $options = array_map(fn($term) =>
            sprintf("<span class='label'><input type='checkbox' name='lpf-%s[]' value='%s'%s /> %s</span><br>",
                esc_attr($term->taxonomy),
                esc_attr($term->slug),
                in_array($term->slug, $values, true) ? ' checked' : '',
                esc_html($term->name)
            ), $terms
        );
        return "<p class='form-field lpf-fieldtype-multiple'><label>{$field['label']}{$tip}</label>" . implode('', $options) . "</p>";
    }


    /**
     * Render an autocomplete taxonomy field input.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string HTML markup for the field.
     */
    protected function render_autocomplete_field(array $field, int $post_id): string {
        $slug = $field['slug'];
        $selected_terms = wp_get_post_terms($post_id, $slug, ['fields' => 'all']);
        $tip   = ! empty( $field['description'] ) ? wc_help_tip( $field['description'] ) : '';

        $options_html = '';
        foreach ($selected_terms as $term) {
            $options_html .= sprintf(
                '<option value="%s" selected="selected">%s</option>',
                esc_attr($term->slug),
                esc_html($term->name)
            );
        }

        return sprintf(
            '<p class="form-field lpf-fieldtype-%s">
                <label>%s %s</label>
                <select name="lpf-%s[]" multiple="multiple" class="lpf-autocomplete-select" data-taxonomy="%s" style="width: 100%%;">%s</select>
            </p>',
            esc_attr($field['type']),
            esc_html($field['label']),
            $tip,
            esc_attr($slug),
            esc_attr($slug),
            $options_html
        );
    }


    /**
     * Save all custom fields for a product when it is saved in the admin.
     *
     *
     * @param int $post_id Product ID.
     * @return void
     */
    public function save_the_fields(int $post_id): void
    {
        //
        // 1. Save (or clear) product group assignment
        //
        $group_slug = sanitize_text_field($_POST['lpf-product-group-select'] ?? '');
        $group_term = $group_slug
            ? get_term_by('slug', $group_slug, 'lpf_product_group')
            : null;

        if ($group_term && ! is_wp_error($group_term)) {
            wp_set_post_terms($post_id, [$group_term->term_id], 'lpf_product_group');
            $effective_group = $group_term->slug;
        } else {
            // Clear groups and treat as "general"
            wp_set_post_terms($post_id, [], 'lpf_product_group');
            $effective_group = 'general';
        }

        //
        // 2. Get fields applicable for this product (global + group-specific)
        //
        $fields = Helpers::get_fields_for_group($effective_group);

        //
        // 3. Save posted values for each field
        //
        foreach ($fields as $field) {
            $slug = $field['slug'];
            $key  = 'lpf-' . $slug;

            if (isset($_POST[$key])) {
                FieldStorage::save_field($post_id, $slug, $_POST[$key]);
            }
        }
    }


}
