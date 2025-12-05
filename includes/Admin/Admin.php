<?php
/**
 * Admin class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Admin;

defined('ABSPATH') || exit;

use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Admin\TaxonomyAdminPanel;
use Luma\ProductFields\Product\FieldRenderer;
use Luma\ProductFields\Product\VariationFieldRenderer;
use Luma\ProductFields\Admin\Migration\MigrationAjax;

/**
 * Admin class
 *
 * Registers hooks for admin field rendering, saving, and UI setup.
 */
class Admin {


    /**
     * Initialize all admin-related hooks for field and variation editors.
     *
     * @return void
     */
    public function initialize_hooks(): void {        
        $field_renderer = new FieldRenderer();
        $variation_field_renderer = new VariationFieldRenderer();
        MigrationAjax::register();
        add_filter('woocommerce_product_data_tabs', [$field_renderer, 'add_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$field_renderer, 'display_product_data_fields_content']);
        add_action('woocommerce_process_product_meta', [$field_renderer, 'save_the_fields']);        
        add_action('woocommerce_product_after_variable_attributes', [ $variation_field_renderer, 'render_variation_fields' ], 10, 3);
        add_action('woocommerce_save_product_variation', [ $variation_field_renderer, 'save_the_fields' ], 10, 2);
        add_action( 'admin_notices', [ $this, 'maybe_show_back_button' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] , 99 );
    }


    /**
     * Enqueue admin scripts and styles used by the plugin.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        wp_enqueue_script('jquery-ui-tooltip'); // 💡 This is the missing piece
        wp_enqueue_style(
            'woocommerce_select2',
            WC()->plugin_url() . '/assets/css/select2.css',
            [],
            WC_VERSION
        );


        wp_enqueue_script('select2');
        wp_enqueue_style('select2');  
        wp_enqueue_script('luma-product-fields-admin-js', LUMA_PRODUCT_FIELDS_PLUGIN_URL . 'js/ajax-admin.js', [ 'wc-admin-meta-boxes', 'jquery-ui-tooltip'  ], LUMA_PRODUCT_FIELDS_PLUGIN_VER, true);
        wp_enqueue_style('luma-product-fields-admin-style', LUMA_PRODUCT_FIELDS_PLUGIN_URL . 'css/admin-style.css', [], LUMA_PRODUCT_FIELDS_PLUGIN_VER);
        wp_localize_script('luma-product-fields-admin-js', 'luma_product_fields_admin_ajaxdata', $this->get_ajax_data());
    }


    /**
     * Returns an array of AJAX data used in localized admin scripts.
     *
     * @return array
     */
    public function get_ajax_data(): array {
        global $post;

        $data = [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('luma_product_fields_admin_nonce'),
            'action' => 'luma_product_fields_ajax',
            'slug_warning' => __('WARNING! When you change the slug, existing data from this field will no longer be shown. Are you really sure you want to do this?', 'luma-product-fields'),
            'delete_warning' => __('Are you sure you want to delete this field? Existing data will no longer be shown to the customers.', 'luma-product-fields'),
            'autocomplete_placeholder' => __('Just start typing...', 'luma-product-fields'),
            /* translators: %d: minimum number of characters the user must type before search starts. */
            'autocomplete_min_chars'   => __( 'Enter at least %d characters', 'luma-product-fields' ),
            'autocomplete_searching'   => __( 'Searching…', 'luma-product-fields' ),
            'spinner' => '<div style="text-align:center;padding:3em;"><img src="/wp-admin/images/spinner-2x.gif" /></div>',
        ];

        if (isset($post)) {
            $data['post_id'] = $post->ID;
        }

        return $data;
    }


    /**
     * Returns a <select> element for choosing or filtering product groups.
     *
     * @param string      $id            HTML name/id of the select.
     * @param string|null $selected_slug Currently selected group slug.
     * @param string|null $unselected_string 
     *        If provided, renders an empty-value <option>. 
     *        Used for product edit screen ("No group selected").
     *
     * @param array       $args {
     *     Optional options (ignored if $unselected_string is provided).
     *
     *     @type bool   $include_all     Add "All" option. Default false.
     *     @type bool   $include_general Add "General (no group)" option. Default false.
     *     @type string $all_label       Label for "All".
     *     @type string $general_label   Label for "General".
     * }
     *
     * @return string
     */
    public function get_product_group_select(
        string $id = '',
        ?string $selected_slug = null,
        ?string $unselected_string = null,
        array $args = []
    ): string {

        $args = wp_parse_args($args, [
            'include_all'     => false,
            'include_general' => false,
            'all_label'       => __('All', 'luma-product-fields'),
            'general_label'   => __('No group', 'luma-product-fields'),
        ]);

        $terms = get_terms([
            'taxonomy'   => 'lpf_product_group',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return '';
        }

        $html = '<select name="' . esc_attr($id) . '" id="' . esc_attr($id) . '">';

        /**
         * MODE 1: Product Edit Screen
         * If $unselected_string is provided → render exactly one default empty option
         * and DO NOT render “all/general”, because this is NOT a filter selector.
         */
        if ($unselected_string !== null) {
            $html .= sprintf(
                '<option value="" %s>%s</option>',
                selected($selected_slug, '', false),
                esc_html($unselected_string)
            );
        } 
        /**
         * MODE 2: Filter selectors (ListView, FieldOverview)
         * $unselected_string is NULL → use filtering options if enabled
         */
        else {
            if ($args['include_all']) {
                $html .= sprintf(
                    '<option value="all"%s>%s</option>',
                    selected($selected_slug, 'all', false),
                    esc_html($args['all_label'])
                );
            }

            if ($args['include_general']) {
                $html .= sprintf(
                    '<option value="general"%s>%s</option>',
                    selected($selected_slug, 'general', false),
                    esc_html($args['general_label'])
                );
            }
        }

        // Always show actual product groups
        foreach ($terms as $term) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($term->slug),
                selected($selected_slug, $term->slug, false),
                esc_html($term->name)
            );
        }

        $html .= '</select>';

        return $html;
    }



    /**
     * Render checkboxes for all product groups.
     *
     * @param string   $name     The name attribute for checkbox inputs.
     * @param string[] $selected An array of selected group slugs.
     *
     * @return string
     */
    public function get_product_group_checkboxes(string $name, array $selected = []): string {
        $groups = get_terms([
            'taxonomy'   => 'lpf_product_group',
            'hide_empty' => false,
        ]);

        if (is_wp_error($groups)) {
            return '';
        }

        $html = '<fieldset>';
        foreach ($groups as $group) {
            $checked = in_array($group->slug, $selected, true) ? 'checked' : '';
            $html .= sprintf(
                '<label><input type="checkbox" name="%s[]" value="%s" %s> %s</label><br>',
                esc_attr($name),
                esc_attr($group->slug),
                $checked,
                esc_html($group->name)
            );
        }
        $html .= '</fieldset>';

        return $html;
    }


    /**
     * Show back button if the current screen is for a supported taxonomy.
     *
     * @return void
     */
    public function maybe_show_back_button() {
        $screen = get_current_screen();

        if ($screen && $screen->taxonomy) {
            $custom_taxonomies = array_column(\Luma\ProductFields\Taxonomy\TaxonomyManager::get_all(), 'slug');
            if ( in_array($screen->taxonomy, $custom_taxonomies, true) || $screen->taxonomy === 'lpf_product_group' ) {
                self::show_back_button();
            }
        }
    }


    /**
     * Outputs a back button linking to the field overview page.
     *
     * @return void
     */
    public static function show_back_button() {
        echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=lpf-fields' ) ) . '" class="button-secondary">&larr; ' . esc_html__( 'Back to Field Overview', 'luma-product-fields' ) . '</a></p>';
    }

}
