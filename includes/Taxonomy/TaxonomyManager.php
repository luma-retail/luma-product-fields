<?php
/**
 * Taxonomy manager class
 *
 * @package Luma\ProductFields
 * @since   1.0.0
 */
namespace Luma\ProductFields\Taxonomy;

defined('ABSPATH') || exit;

use Luma\ProductFields\Product\FieldStorage;
use WP_Taxonomy;


/**
* Taxonomy Manager class
*
*  Registers dynamic field taxonomies for field types that are taxonomies.
*
*
* @hook luma_product_fields_taxonomy_registered
*      Fires after custom taxonomy is registered.
*      For 3r parties to customize taxonomies.
*      @param string $slug name of Taxonomy
*      @param $args args for register_taxonomy
*
*/

class TaxonomyManager {

    protected const OPTION_KEY = 'lpf_dynamic_taxonomies';
    
    /** @var string[] Slugs for dynamic taxonomies that are public (links enabled). */
    protected array $linkable_taxonomy_slugs = [];
    

    /**
     * Initialize hooks.
     */
    public function init(): void {
        add_action('init', [$this, 'register_dynamic_taxonomies']);
    }


    /**
     * Get stored dynamic taxonomy field definitions.
     *
     * If $group is provided, only items whose 'groups' array contains that slug are returned.
     *
     * Each item has (at least) these keys:
     * - label            string
     * - description      string
     * - frontend_desc    string (may contain sanitized HTML)
     * - slug             string (taxonomy name)
     * - type             string
     * - unit             string
     * - groups           string[] (product group slugs)
     * - hide_in_frontend bool
     * - variation        bool
     * - is_taxonomy      bool
     * - show_links       bool
     * - schema_prop      string
     * - tax_description  string (optional)
     *
     * @param string|null $group Product group slug to filter by, or null for all.
     * @return array<int, array{
     *     label: string,
     *     description: string,
     *     frontend_desc: string,
     *     slug: string,
     *     type: string,
     *     unit: string,
     *     groups: array<int,string>,
     *     hide_in_frontend: bool,
     *     variation: bool,
     *     is_taxonomy: bool,
     *     show_links: bool,
     *     schema_prop: string,
     *     tax_description?: string
     * }>
     */
    public function get_dynamic_taxonomies( $group = null ): array {
        $fields = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $fields ) ) {
            return [];
        }
        if ( empty ( $group ) ) {
            return $fields;
        }
        $filtered_fields = [];
        foreach ( $fields as $field ) {
            if ( in_array( $group, $field['groups'] ) ) {
                $filtered_fields[] = $field;    
            }
        }        
        return $filtered_fields;        
    }


    /**
     * Static: Get stored dynamic taxonomy field definitions.
     *
     * Proxy to get_dynamic_taxonomies(); see that method for the array shape.
     *
     * @param string|null $group Product group slug to filter by, or null for all.
     * @return array<int, array{
     *     label: string,
     *     description: string,
     *     frontend_desc: string,
     *     slug: string,
     *     type: string,
     *     unit: string,
     *     groups: array<int,string>,
     *     hide_in_frontend: bool,
     *     variation: bool,
     *     is_taxonomy: bool,
     *     show_links: bool,
     *     schema_prop: string,
     *     tax_description?: string
     * }>
     */
    public static function get_all( $group = NULL): array {
        return (new self())->get_dynamic_taxonomies( $group );
    }


    /**
     * Register each dynamic taxonom
     *
     * If the field has "show links" enabled, tax is public with archives.
     *
     */
    public function register_dynamic_taxonomies(): void {
        foreach ($this->get_dynamic_taxonomies() as $field) {

            $show_links = ! empty( $field['show_links'] ) &&
                          \Luma\ProductFields\Registry\FieldTypeRegistry::supports( $field['type'], 'link' );
                          
                          
            $args =  [
                'label'              => $field['label'],
                'hierarchical'       => false,
                'public'             => $show_links,
                'publicly_queryable' => $show_links,
                'show_in_rest'       => false,
                'show_ui'            => true,
                'show_in_menu'       => false,
                'show_admin_column'  => false,
                'query_var'          => $show_links,
                'meta_box_cb'        => false,
            ];

            register_taxonomy( $field['slug'], 'product',  $args );
            
            if ( $show_links ) {
                $this->linkable_taxonomy_slugs[] = $field['slug'];
            }            
            
            /**
             * For 3r parties to customize taxonomies
             *
             * @since 3.1.0
             */
            do_action( 'luma_product_fields_taxonomy_registered', $field['slug'], $args );            
        }
    }
        


    /**
     * Check if a taxonomy is of type 'single'.
     *
     * @param string $slug
     * @return bool
     */
    public function is_single(string $slug): bool {
        $tax = $this->get_dynamic_taxonomies();
        return ($tax[$slug]['type'] ?? '') === 'single';
    }
    

    /**
     * Get label for a dynamic taxonomy.
     *
     * @param string $slug
     * @return string
     */
    public function get_label(string $slug): string {
        $tax = $this->get_dynamic_taxonomies();
        return $tax[$slug]['label'] ?? $slug;
    }
    

    /**
     * Get a dynamic taxonomy field by slug.
     *
     * @param string $slug
     * @return array|null
     */
    public static function get_field(string $slug): ?array {
        foreach (self::get_all() as $field) {
            if (($field['slug'] ?? null) === $slug) {
                return $field;
            }
        }

        return null;
    }
    
    
    /**
     * Get slugs for dynamic taxonomies that are public (links enabled).
     *
     * @return string[]
     */
    public function get_linkable_taxonomy_slugs(): array {
        return $this->linkable_taxonomy_slugs;
    }
    
    
    

    /**
     * Save or update a dynamic taxonomy field.
     *
     * @param array $field_data
     * @return void
    */
    public static function save_field(array $field_data): void {
        $taxonomies = self::get_all();
        $updated = false;

        foreach ($taxonomies as &$tax) {
            if (($tax['slug'] ?? '') === ($field_data['slug'] ?? '')) {
                $tax = array_merge($tax, $field_data);
                $tax['is_taxonomy'] = true;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            if (empty($field_data['slug'])) {
                $field_data['slug'] = self::generate_unique_slug($field_data['label']);
            }

            $field_data['is_taxonomy'] = true;
            $taxonomies[] = $field_data;
        }

        update_option(self::OPTION_KEY, $taxonomies);
        flush_rewrite_rules();
        delete_option('rewrite_rules');
    }


        

    /**
     * Delete a taxonomy field.
     *
     * Deletes only the field definition by default. When $full_cleanup is true,
     * associated terms and product-level assignments are also removed.
     *
     * @todo Return an OperationResult instead of void for better notifications.
     *
     * @param string $slug
     * @param bool   $full_cleanup
     * @return void
     */
    public static function delete_field( string $slug, bool $full_cleanup = false ): void
    {
        if ( $slug === '' ) {
            return;
        }

        if ( $full_cleanup ) {
            // 1. Remove product assignments
            $batch_size = 500;
            $page       = 1;

            while ( true ) {
                $product_ids = wc_get_products([
                    'limit'  => $batch_size,
                    'page'   => $page,
                    'return' => 'ids',
                ]);

                if ( empty( $product_ids ) ) {
                    break;
                }

                foreach ( $product_ids as $product_id ) {
                    FieldStorage::delete_field( $slug, $product_id );
                }

                $page++;
            }

            // 2. Delete all taxonomy terms
            $terms = get_terms([
                'taxonomy'   => $slug,
                'hide_empty' => false,
            ]);

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    wp_delete_term( $term->term_id, $slug );
                }
            }

            // 3. Trigger rewrite flush on next load
            update_option( 'luma_product_fields_flush_rewrite', 1 );
        }

        // 4. Delete taxonomy field definition
        $taxonomies = get_option( self::OPTION_KEY, [] );
        $taxonomies = array_filter( $taxonomies, fn( $tax ) => ( $tax['slug'] ?? '' ) !== $slug );
        update_option( self::OPTION_KEY, array_values( $taxonomies ) );
    }



    
    
    /**
     * Generate a unique, human-readable slug for a taxonomy field.
     *
     * @param string $label
     * @return string
     */
    public static function generate_unique_slug(string $label): string {
        $base_slug = '_lpf_' . sanitize_title($label);
        $existing_slugs = array_column(self::get_all(), 'slug');

        $slug = $base_slug;
        $suffix = 2;

        while (in_array($slug, $existing_slugs, true)) {
            $slug = $base_slug . '_' . $suffix;
            $suffix++;
        }

        return $slug;
    }




} 
