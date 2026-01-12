<?php
/**
 * Frontend controller
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Frontend;

defined('ABSPATH') || exit;

use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Admin\Settings;


/**
* Frontend controller class
*
* Renders custom fields on the product page, replaces WooCommerce default meta, 
* and optionally handles variation field display updates via JS.
*
* @hook luma_product_fields_product_meta_start
*      Fires before the product meta fields are rendered.
*      Useful for extensions that want to prepend additional field groups.
*      @param \WC_Product $product
*
* @hook luma_product_fields_product_meta_end
*      Fires after the product meta fields are rendered.
*      Useful for appending custom field output or secondary metadata.
*      @param \WC_Product $product
*
* @hook luma_product_fields_display_product_meta
*       Filters a single final formatted product meta element.
*       @param string     $output  The HTML output.
*       @param WC_Product $product The product object.
*
* @since 1.0.0
*/
class FrontendController {

    public function __construct() {
        $this->initialize_hooks();
    }

    public function initialize_hooks(): void {
        add_action('woocommerce_product_additional_information', [$this, 'display_product_meta'], 99);
        add_filter('woocommerce_product_tabs', [ $this, 'filter_product_tabs' ], 20 );
        add_filter( 'woocommerce_product_additional_information_heading', [ $this, 'filter_additional_info_heading' ] );
        add_action('plugins_loaded', [$this, 'remove_product_data']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_script']);
        add_action('wp_ajax_nopriv_luma_product_fields_get_variation_fields_html', [$this, 'ajax_luma_product_fields_get_variation_fields_html']);
        add_action('wp_ajax_luma_product_fields_get_variation_fields_html', [$this, 'ajax_luma_product_fields_get_variation_fields_html']);
        add_filter( 'woocommerce_page_title', [ $this, 'filter_archive_title' ] );
    }

    public function remove_product_data(): void {
        remove_action('woocommerce_single_product_summary', ['WC_Structured_Data', 'generate_product_data'], 60);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
        remove_action('woocommerce_product_additional_information', 'wc_display_product_attributes', 10);

        //for block themes
        add_filter( 'woocommerce_should_render_product_meta', '__return_false' );
        add_filter( 'render_block', function( $block_content, $block ) {
            if (
                is_product()
                && isset( $block['blockName'] )
                && $block['blockName'] === 'woocommerce/product-meta'
            ) {
                return '';  // Completely remove SKU, Categories, Tags, etc.
            }

            return $block_content;
        }, 10, 2 );

    }
    

    public function enqueue_script(): void {

        if ( ! ( is_singular('product') || $this->is_on_plugin_taxonomy_archive() ) ) {
            return;
        }

        wp_enqueue_style(
            'luma-product-fields-style',
            LUMA_PRODUCT_FIELDS_PLUGIN_URL . 'css/style.css',
            array(),
            LUMA_PRODUCT_FIELDS_PLUGIN_VER
        );
                
        if ( ! is_singular('product') ) {
            return;
        } 
        global $product; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        if ( ! $product instanceof \WC_Product ) {
            $product = wc_get_product( get_the_ID() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        }
        if ( ! $product instanceof \WC_Product || $product->get_type() !== 'variable' ) {
            return;
        }
        
        wp_register_script(
            'luma-product-fields-js',
            LUMA_PRODUCT_FIELDS_PLUGIN_URL . '/js/luma-product-fields.js',
            ['jquery'],
            LUMA_PRODUCT_FIELDS_PLUGIN_VER,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        wp_enqueue_script('luma-product-fields-js');
        wp_localize_script('luma-product-fields-js', 'luma_product_fields_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('luma_product_fields_variation_nonce'),
        ]);
    }


    /**
     *
     *
     * Filter Additional Information tab title.
     *
     * @param array $tabs
     * @return array
     */
    public function filter_product_tabs( array $tabs ): array
    {
        $custom_title = get_option( Settings::PREFIX . 'front_end_title', __( 'Additional information', 'luma-product-fields' ) );

        if ( isset( $tabs['additional_information'] ) ) {
            $tabs['additional_information']['title'] = $custom_title;
        }

        return $tabs;
    }


    /**
     *
     *
     * Filter the heading inside the Additional Information tab.
     *
     * @param string $heading
     * @return string
     */
    public function filter_additional_info_heading( string $heading ): string
    {
        $custom = get_option(
            Settings::PREFIX . 'front_end_title',
            __( 'Additional information', 'luma-product-fields' )
        );

        return $custom ?: $heading;
    }



    /**
     * Check if current page is a term archive for one of our dynamic taxonomies.
     *
     * @return bool
     */
    protected function is_on_plugin_taxonomy_archive(): bool
    {
        if ( ! is_tax() ) {
            return false;
        }

        $tax_slugs = array_column( TaxonomyManager::get_all(), 'slug' );
        if ( empty( $tax_slugs ) ) {
            return false;
        }

        // is_tax( array $taxonomies ) returns true if current archive matches any of them.
        return is_tax( $tax_slugs );
    }
    
    
    /**
     * Output the product meta fields on the single product page.
     *
     * This wraps the rendered meta fields in a container and provides hooks for
     * extensions to inject content before and after the core field output.
     *
     * Hooks:
     * - `luma_product_fields_product_meta_start`: Fires before the product meta fields are rendered.
     *     Useful for extensions that want to prepend additional field groups.
     *
     * - `luma_product_fields_product_meta_end`: Fires after the product meta fields are rendered.
     *     Useful for appending custom field output or secondary metadata.
     *
     * @param \WC_Product $product WooCommerce product object.
     */
    public function display_product_meta( $product ) : void {

        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $product_id    = $product->get_id();
         echo '<div id="luma-product-fields-list">';
        
        /**
         * Hook: luma_product_fields_product_meta_start
         *
         * @param \WC_Product $product
         */
        do_action( 'luma_product_fields_product_meta_start', $product );

        
        $transient_key = 'luma_product_fields_meta_fields_' . $product_id;
        $output        = get_transient( $transient_key );
                
        if ( false === $output ) {
            $output = $this->render_all_fields( $product_id );
            set_transient( $transient_key, $output, HOUR_IN_SECONDS );
        }

        echo wp_kses( (string) $output, wp_kses_allowed_html( 'luma_product_fields_frontend_fields' ) );

        /**
         * Hook: luma_product_fields_product_meta_end
         *
         * @param \WC_Product $product
         */
        do_action( 'luma_product_fields_product_meta_end', $product );

        echo '</div>';
    }

    /**
     * Render all visible fields for a product or variation.
     *
     * @param int $product_id Product or variation ID.
     * @return string Complete HTML block of rendered fields.
     *
     */
    public function render_all_fields(int $product_id): string
    {
        $output = '';
        $field_renderer = new FieldRenderer();
        $group_slug = Helpers::get_product_group_slug($product_id) ?: 'general';
        $fields     = Helpers::get_fields_for_group($group_slug);  
        
        $show_sku  = get_option( Settings::PREFIX . 'display_sku', 'no' ) === 'yes';
        $show_tags = get_option( Settings::PREFIX . 'display_tags', 'no' ) === 'yes';
        $show_cats = get_option( Settings::PREFIX . 'display_categories', 'no' ) === 'yes';
        $show_group = get_option( Settings::PREFIX . 'display_group', 'no' ) === 'yes'; 
        $show_global_unique_id = get_option( Settings::PREFIX . 'display_global_unique_id', 'no' ) === 'yes';        
        
        foreach ($fields as $field) {
            if (!empty($field['hide_in_frontend'])) {
                continue;
            }
            $output .= $field_renderer->render_field($field, $product_id);            
        }

        // Add stock fields (e.g., weight, dimensions, SKU, tags, group)
        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        // Brand
        $brands = wp_get_post_terms($product->get_id(), 'product_brand');
        if (!empty($brands) && !is_wp_error($brands)) {
            $brand = $brands[0];
            $output .= FieldRenderer::wrap_field([
                'label' => __('Brand', 'luma-product-fields'),
                'slug'  => 'brand',
                'schema_prop'   => 'brand',
            ], esc_html($brand->name), esc_url(get_term_link($brand)));
        }

        // Weight
        if ( !empty( $product->get_weight() ) ) {
            $output .= FieldRenderer::wrap_field([
                'label'         => __( 'Package weight', 'luma-product-fields' ),
                'slug'          => 'weight',
                'unit'          => get_option( 'woocommerce_weight_unit' ),
                'schema_prop'   => 'weight',
                'frontend_desc' => __( 'The weight of the product including packaging.', 'luma-product-fields' ),
            ], esc_html( $product->get_weight() ) );
        }

        // Dimensions
        $dimensions = array_filter( $product->get_dimensions( false ) );
        if ( ! empty( $dimensions ) ) {
            $output .= FieldRenderer::wrap_field([
                'label'         => __( 'Package size', 'luma-product-fields' ),
                'slug'          => 'dimensions',
                'unit'          => get_option( 'woocommerce_dimension_unit' ),
                'schema_prop'   => 'size',
                'frontend_desc' => __( 'The size of the product including packaging.', 'luma-product-fields' ),
            ], trim( implode( ' × ', $dimensions ) ) );
        }

    
        // SKU
        if ( $show_sku && $product->get_sku() ) {
            $output .= FieldRenderer::wrap_field([
                'label' => __('SKU', 'luma-product-fields'),
                'slug'  => 'sku',
                'schema_prop'   => 'sku',
            ], esc_html($product->get_sku()));            
        }
        

        // Global unique identifier
        if ( $show_global_unique_id ) {        
            if ( method_exists( $product, 'get_global_unique_id' ) ) {
                $global_unique_id = $product->get_global_unique_id();

                if ( $global_unique_id !== '' ) {
                    $output .= FieldRenderer::wrap_field(
                        [
                            'label'       => __( 'GTIN / EAN', 'luma-product-fields' ),
                            'slug'        => 'global_unique_id',
                            'schema_prop' => 'gtin',
                        ],
                        esc_html( $global_unique_id )
                    );
                }
            }
        }
        
        // Tags        
        if ( $show_tags ) {
            $tags_html = wc_get_product_tag_list( $product->get_id() );
            if ( $tags_html ) {
                $output .= FieldRenderer::wrap_field([
                    'label'       => __( 'Tags', 'luma-product-fields' ),
                    'slug'        => 'product_tags',
                    'schema_prop' => 'keywords',
                ], $tags_html );
            }
        }
                
        if ( $show_cats ) {
            $cats_html = wc_get_product_category_list( $product->get_id() );
            if ( $cats_html ) {
                $output .= FieldRenderer::wrap_field([
                    'label'       => __( 'Categories', 'luma-product-fields' ),
                    'slug'        => 'product_cats',
                    'schema_prop' => 'category',
                ], $cats_html );
            }
        }
        
        // Product group
        if ( $show_group ) {
            $group = \Luma\ProductFields\Utils\Helpers::get_product_group( $product->get_id() );

            if ( $group ) {
                $output .= FieldRenderer::wrap_field(
                    [
                        'label' => __( 'Product group', 'luma-product-fields' ),
                        'slug'  => 'product_group',
                    ],
                    esc_html( $group->name ),
                    esc_url( get_term_link( $group ) )
                );
            }
        }
     

        /**
         * @hook luma_product_fields_display_product_meta
         * Filters the final formatted product meta element.
         *
         * @param string     $output  The HTML output.
         * @param WC_Product $product The product object.
         *
         * @since 1.0.0
         */
        return apply_filters( 'luma_product_fields_display_product_meta', $output, $product);
    }

    
    /**
     * AJAX handler: Returns rendered field HTML for a specific variation.
     *
     * This is intended for use on the product page when a customer selects
     * a variation. It returns fully rendered HTML for all applicable fields.
     *
     * @return void
     */
    public function ajax_luma_product_fields_get_variation_fields_html(): void {

        check_ajax_referer( 'luma_product_fields_variation_nonce', 'nonce' );

        $variation_id = isset( $_POST['variation_id'] )
            ? absint( wp_unslash( $_POST['variation_id'] ) )
            : 0;

        if ( ! $variation_id || 'product_variation' !== get_post_type( $variation_id ) ) {
            wp_send_json_error( [ 'error' => 'Invalid variation ID' ], 400 );
        }

        // Ensure variation is publicly accessible
        if ( 'publish' !== get_post_status( $variation_id ) ) {
            wp_send_json_error( [ 'error' => 'Variation not available' ], 404 );
        }

        $html = $this->render_all_fields( $variation_id );

        wp_send_json_success( [ 'html' => $html ] );
    }


    /**
     * Filter the WooCommerce archive title to include the field label
     * for taxonomies registered by the plugin.
     *
     * @param string $title The original WooCommerce archive title.
     *
     * @return string The modified archive title with label, if applicable.
     */
    public function filter_archive_title( string $title ): string {
        if ( is_tax() ) {
            $term     = get_queried_object();
            $taxonomy = $term->taxonomy ?? '';

            if ( \Luma\ProductFields\Utils\Helpers::is_taxonomy_field( $taxonomy ) ) {
                $field = \Luma\ProductFields\Utils\Helpers::get_field_definition_by_slug( $taxonomy );

                if ( ! empty( $field['label'] ) ) {
                    return sprintf( '<span class="luma-product-fields-field-label">%s:</span> %s', $field['label'], $term->name );
                }
            }
        }

        return $title;
    }
    
    
}
