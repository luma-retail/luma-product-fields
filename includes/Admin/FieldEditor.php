<?php
/**
 * Admin interface for editing a single product field (meta or taxonomy)
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Meta\MetaManager;
use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Registry\FieldTypeRegistry;

defined('ABSPATH') || exit;


 /**
 * Field Editor class
 *
 * Manages the admin UI for editing field definitions (name, type, visibility, etc.).
 *
 * @hook Luma\ProductFields\FieldEditor\after_label
 *      Fires directly below label input in the field editor.
 *      To add additional data to the field editor.
 *      @param array $field 
 *
 * @hook Luma\ProductFields\FieldEditor\form_bottom
 *      Fires at the bottom of the form, inside the table and before the submit button.
 *      To add additional data to the field editor.
 *      @param array $field 
 *
 *
 * @hook Luma\ProductFields\FieldEditor\form_data
 *      Filters the data sent to TaxonomyManager or MetaManager for saving.
 *      @param array $data 
 *
 *
 * @hook Luma\ProductFields\FieldEditor\success_message
 *      Filters the success message shown after a field is created or updated.
 *      @param string $message  The success message to be displayed.
 *      @param string $action   The action performed: 'created' or 'updated'.
 *      @param array  $data     The field data that was saved.
 *      @param bool   $is_tax   Whether the field is a taxonomy field.
 *      @return string Filtered success message.
 */
class FieldEditor
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_editor_page']);
        add_action('admin_init', [$this, 'maybe_save']);
    }


    /**

     * Registers the hidden submenu page for editing fields
     *
     * @return void
     */
    public function register_editor_page(): void
    {
        add_submenu_page(
            null,
            __('Edit Field', 'luma-product-fields'),
            __('Edit Field', 'luma-product-fields'),
            'manage_woocommerce',
            'lpf-new-field',
            [$this, 'render_editor']
        );
    }


    /**
     * Renders the editor UI
     *
     * @return void
     */
    public function render_editor(): void
    {        
        $slug  = sanitize_title($_GET['edit'] ?? '');
        $field = MetaManager::get_field($slug) ?? TaxonomyManager::get_field($slug);
        $field_defaults = [
            'type'             => '',
            'label'            => '',
            'schema_prop'      => '',
            'description'      => '',
            'frontend_desc'    => '',
            'unit'             => '',
            'groups'           => ['general'],
            'hide_in_frontend' => false,
            'variation'        => false,
            'show_links'       => false,
        ];
        $field = wp_parse_args($field, $field_defaults);
        $types = FieldTypeRegistry::get_all();
        $current_type = $field['type'] ?? null;
        $supports_unit = $current_type ? FieldTypeRegistry::supports($current_type, 'unit') : false;
        $supports_links = $current_type ? FieldTypeRegistry::supports($current_type, 'link') : false;
        $supports_variations = $current_type ? FieldTypeRegistry::supports($current_type, 'variations') : false;
        $unit_row_class = 'field-unit-row' . ($supports_unit ? '' : ' hidden');
        $links_row_class = 'field-show-tax-links-row' . ($supports_links ? '' : ' hidden');
        $variations_row_class = 'field-variations-row' . ($supports_variations ? '' : ' hidden');        
        
        $types_desc = '<ul class="lpf-types-desc">';
        foreach ( $types as $slug => $type ) {
            $types_desc .= '<li id="lpf-type-' . esc_attr( $slug ) . '" data-type="' .  esc_attr( $slug ) . '"><strong>' . esc_html( $type['label'] ) . ':</strong> ' . esc_html( $type['description'] ) . '</li>';
        }
        $types_desc .= '</ul>';
                
        Admin::show_back_button();

        echo '<div class="wrap"><h1>' . esc_html($slug ? __('Edit Field', 'luma-product-fields') : __('Add New Field', 'luma-product-fields')) . '</h1>';

        echo '<form method="post" class="lpf-field-editor">';
        wp_nonce_field('lpf_fieldssave_field_editor');

        echo '<table class="form-table">';
        
        echo '<tr><th><label>' . __('Type', 'luma-product-fields') . '</label>';
        echo '</th>';
        echo '<td>';
        echo '<div class="field-types">';
        echo '<select name="lpf_fields[type]" id="lpf_fields_type_selector">';
        foreach ($types as $type => $info) {
            echo '<option value="' . esc_attr($type) . '"' . selected($field['type'] ?? '', $type, false) . '>' . esc_html($info['label']) . '</option>';
        }
        
        echo '</select>';
        echo $types_desc;

        echo '</div></td></tr>';
        echo '<tr><th><label>' . __('Label', 'luma-product-fields') . '</label></th>';
        echo '<td><input name="lpf_fields[label]" type="text" value="' . esc_attr($field['label'] ?? '') . '" class="regular-text"></td></tr>';
        
        do_action( 'Luma\ProductFields\FieldEditor\after_label', $field );
        
        echo '<tr><th><label>' . __('Tooltip (front end)', 'luma-product-fields') . '</label></th>';
        echo '<td>';
        wp_editor( $field['frontend_desc'], 'lpf_fields_frontend_desc', [ 'textarea_rows' => 5 , 'media_buttons' => false ] );        
        echo '<p>' . __("A tooltip that pops up by the label on the frontend. Just leave this field empty to omit.", 'luma-product-fields') . '</p>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . __('Tooltip (for admin)', 'luma-product-fields') . '</label></th>';
        echo '<td><textarea name="lpf_fields[description]" rows="3" class="large-text">' . esc_textarea( stripslashes( $field['description'] ?? '' ) ) . '</textarea>';
        echo '<p>' . __("A tooltip for the shop manager to better understand what to do. Just leave this field emtpy to omit.", 'luma-product-fields') . '</p>';
        echo '</td></tr>';

        echo '<tr class="' . esc_attr($unit_row_class) . '"><th><label>' . __('Unit', 'luma-product-fields') . '</label></th>';
        echo '<td><select name="lpf_fields[unit]">';
        echo '<option value="">' . __('None', 'luma-product-fields') . '</option>';
        foreach (\Luma\ProductFields\Registry\FieldTypeRegistry::get_units() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($field['unit'] ?? '', $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
    
        echo '<tr><th><label>' . __('Product Groups', 'luma-product-fields') . '</label></th>';
        echo '<td>' . (new Admin())->get_product_group_checkboxes('lpf_fields[groups]', $field['groups'] ?? ['general']);
        echo '<p>' . __('Leave empty to show across all products', 'luma-product-fields') . '</p>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . __('Frontend Visibility', 'luma-product-fields') . '</label></th>';
        echo '<td><label><input type="checkbox" name="lpf_fields[hide_in_frontend]" value="1"' . checked($field['hide_in_frontend'] ?? false, true, false) . '> ' . __('Hide in frontend', 'luma-product-fields') . '</label></td></tr>';

        echo '<tr class="' . esc_attr($variations_row_class) . '"><th><label>' . __('Use in variations', 'luma-product-fields') . '</label></th>';
        echo '<td><label><input type="checkbox" name="lpf_fields[variation]" value="1"' . checked($field['variation'] ?? false, true, false) . '> ' . __('Yes', 'luma-product-fields') . '</label></td></tr>';

        echo '</td></tr>';
        echo '<tr><th><label>' . __('Schema Property', 'luma-product-fields') . '</label></th>';
        echo '<td><input name="lpf_fields[schema_prop]" type="text" value="' . esc_attr($field['schema_prop'] ?? '') . '" class="regular-text"><br>';
                    
        echo __("Schema Property controls how the value is included in your product’s structured data (schema.org). Adding a valid schema property (e.g. weight, brand, material) helps Google and other search engines better understand your product, which may improve how it appears in search results.", 'luma-product-fields');
        echo ' <a target="_blank" href="https://schema.org/Product">' .  __("See list of available schema.org properties for products", 'luma-product-fields' ) . '</a>';
        echo '</td></tr>';

        echo '<tr class="' . esc_attr($links_row_class) . '"><th><label>' . __('Show Taxonomy Links', 'luma-product-fields') . '</label></th>';
        echo '<td><label><input type="checkbox" name="lpf_fields[show_links]" value="1"' . checked($field['show_links'] ?? false, true, false) . '> ' . __('Link to products with same value in front end', 'luma-product-fields') . '</label></td></tr>';
        
        do_action( 'Luma\ProductFields\FieldEditor\form_bottom', $field );
        
        echo '</table>';
        submit_button(__('Save Field', 'luma-product-fields'));
        echo '</form></div>';
    }



    /**
     * Handles saving the field definition.
     *
     *
     * @return void
     */
    public function maybe_save(): void
    {
        if (
            ! isset($_POST['lpf_fields']) ||
            ! check_admin_referer('lpf_fieldssave_field_editor')
        ) {
            return;
        }

        $field_input   = $_POST['lpf_fields'];
        $original_slug = sanitize_title($_GET['edit'] ?? '');

        $label  = sanitize_text_field($field_input['label'] ?? '');
        $type   = sanitize_text_field($field_input['type'] ?? '');
        $is_tax = FieldTypeRegistry::get($type)['storage'] === 'taxonomy';

        $slug = $original_slug ?: sanitize_title($label);

        // Prevent creating new fields with conflicting slugs
        if (empty($original_slug) && $this->slug_conflicts($slug)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' .
                     esc_html__('A field or taxonomy with this name already exists. Please choose another name.', 'luma-product-fields') .
                     '</p></div>';
            });
            return;
        }
        $before = null;
        if ( $is_tax && ! empty($original_slug) ) {
            $before = TaxonomyManager::get_field( $original_slug );
        }
        
        // Groups: allow empty array = global field
        if (isset($field_input['groups'])) {
            $groups = array_map(
                'sanitize_title',
                array_filter((array) $field_input['groups'])
            );
        } else {
            $groups = [];
        }

        $data = [
            'label'           => $label,
            'description'     => sanitize_textarea_field($field_input['description'] ?? ''),
            'frontend_desc'   => wp_kses_post($_POST['lpf_fields_frontend_desc']),
            'slug'            => $slug,
            'type'            => $type,
            'unit'            => sanitize_text_field($field_input['unit'] ?? ''),
            'schema_prop'     => sanitize_text_field($field_input['schema_prop'] ?? ''),
            'groups'          => $groups, // <- CORRECTED
            'hide_in_frontend'=> ! empty($field_input['hide_in_frontend']),
            'variation'       => ! empty($field_input['variation']),
            'show_links'      => ! empty($field_input['show_links']),
        ];

        $data = apply_filters('Luma\ProductFields\FieldEditor\form_data', $data);

        // Save field data
        if ($is_tax) {
            TaxonomyManager::save_field($data);
        } else {
            MetaManager::save_field($data);
        }

        // Check whether to set rewrite flush flag
        if ($this->should_flag_rewrite_flush($before, $data)) {
            $this->flag_rewrite_flush();
            }
                
        // Add notice
        $action = $original_slug ? 'updated' : 'created';
        if ( $is_tax ) {
            $message = ( $action === 'created' )
                ? __('Field created successfully. You can now add terms via the Manage Terms button.', 'luma-product-fields')
                : __('Field updated successfully. Manage Terms is available for editing values.', 'luma-product-fields');
        } else {
            $message = ( $action === 'created' )
                ? __('Field created successfully.', 'luma-product-fields')
                : __('Field updated successfully.', 'luma-product-fields');
        }

        /**
         * Filters the success message shown after a field is created or updated.
         *
         * Allows developers to modify the confirmation text displayed on the
         * Product Fields overview page after saving a field definition.
         *
         * @hook Luma\ProductFields\FieldEditor\success_message
         *
         * @param string $message  The success message to be displayed.
         * @param string $action   The action performed: 'created' or 'updated'.
         * @param array  $data     The field data that was saved.
         * @param bool   $is_tax   Whether the field is a taxonomy field.
         *
         * @return string Filtered success message.
         */
        $message = apply_filters(
            'Luma\ProductFields\FieldEditor\success_message',
            $message,
            $action,
            $data,
            $is_tax
        );
        NotificationManager::add_notice([
            'type'    => 'success',
            'message' => $message,
            'context' => 'field_editor',
        ]);

        wp_redirect(admin_url('edit.php?post_type=product&page=lpf-fields'));
        exit;
    }

    
    
    /**
     * Check if a slug conflicts with any existing taxonomy or meta field.
     *
     * @param string $slug Slug to check.
     * @return bool True if slug already exists.
     */
    protected function slug_conflicts(string $slug): bool {
        // Check this plugin's taxonomies
        $plugin_tax_slugs = array_column( TaxonomyManager::get_all(), 'slug' );

        // Check this plugin's meta fields
        $plugin_meta_slugs = array_column( MetaManager::get_all(), 'slug' );

        // Check registered WordPress taxonomies
        $wp_tax_slugs = get_taxonomies([], 'names');

        return in_array($slug, $plugin_tax_slugs, true)
            || in_array($slug, $plugin_meta_slugs, true)
            || in_array($slug, $wp_tax_slugs, true);
    }



    /**
     * Determine whether this save operation requires a rewrite flush.
     *
     * Triggers when:
     * - A field becomes (or stops being) a linkable taxonomy (show_links + type supports 'link' + taxonomy storage).
     * - A linkable taxonomy field changes its slug.
     * - A field switches storage between taxonomy ↔ meta while linkability changes.
     *
     * @param array<string,mixed>|null $before Field definition before save (null on create).
     * @param array<string,mixed>      $after  Field definition after save (the data we just persisted).
     * @return bool True if the plugin should flush rewrite rules on next request.
     */
    protected function should_flag_rewrite_flush( ?array $before, array $after ): bool
    {
        $before_linkable = $this->is_linkable_taxonomy_field( $before );
        $after_linkable  = $this->is_linkable_taxonomy_field( $after );

        // If linkability toggled (false→true or true→false), we must flush.
        if ( $before_linkable !== $after_linkable ) {
            return true;
        }

        // If both were/are linkable, flush if the slug changed (route base is /{slug}/).
        if ( $before_linkable && $after_linkable ) {
            $before_slug = (string) ( $before['slug'] ?? '' );
            $after_slug  = (string) ( $after['slug'] ?? '' );
            if ( $before_slug && $after_slug && $before_slug !== $after_slug ) {
                return true;
            }
        }

        // On create: if the new field is linkable, we need a flush to add /{slug}/.
        if ( ! $before && $after_linkable ) {
            return true;
        }

        return false;
    }



    /**
     * Check whether a field definition represents a linkable taxonomy.
     *
     * A field is considered linkable when:
     * - It exists and has a valid 'type'.
     * - The field type's storage is 'taxonomy'.
     * - The field type supports 'link'.
     * - The field has 'show_links' truthy.
     *
     * @param array<string,mixed>|null $def Field definition, or null.
     * @return bool
     */
    protected function is_linkable_taxonomy_field( ?array $def ): bool
    {
        if ( ! is_array( $def ) ) {
            return false;
        }

        $type = (string) ( $def['type'] ?? '' );
        if ( ! $type ) {
            return false;
        }

        $info      = \Luma\ProductFields\Registry\FieldTypeRegistry::get( $type );
        $is_tax    = isset($info['storage']) && $info['storage'] === 'taxonomy';
        $supports  = \Luma\ProductFields\Registry\FieldTypeRegistry::supports( $type, 'link' );
        $show      = ! empty( $def['show_links'] );

        return $is_tax && $supports && $show;
    }



    /**
     * Set a one-time flag so the bootstrap flushes rewrite rules
     * after taxonomies and routes have been registered on the next request.
     *
     * @return void
     */
    protected function flag_rewrite_flush(): void
    {
        update_option( 'luma_product_fields_flush_rewrite', 1, true );
    }
}
