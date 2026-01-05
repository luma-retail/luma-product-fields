<?php
/**
 * Helper methods class
 *
 * @package Luma\ProductFields
 * @since   1.0.0
 */

namespace Luma\ProductFields\Utils;

defined( 'ABSPATH' ) || exit;

use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Meta\MetaManager;
use Luma\ProductFields\Frontend\FieldRenderer;
use Luma\ProductFields\Product\FieldStorage;


/**
 * Helpers class
 *
 * Contains utility functions and helpers for retrieving product group slugs and meta values.
 *
 * @hook luma_product_fields_get_all_fields
 *      Filter the list of field definitions after merging and group filtering.
 *      Use this to modify or reorder fields (e.g. per-group ordering).
 *      @param array<int,array<string,mixed>> $fields Field definitions.
 *      @param string|null $group  Product group slug, or null for all fields
 *        
 *
 * @hook luma_product_fields_formatted_field_value
 *      Filters the final formatted value returned for a field.
 *      @param string $formatted_value The final display value.
 *      @param mixed $value The input value
 *      @param array $field   Field definition
 *      @param int    $post_id         Product ID.
 *      @param bool   $links   Whether to render links for taxonomy terms.
 *
 * @hook luma_product_fields_external_field_value
 *      Allow external plugins to return values for fields that are not
 *      registered in the Luma Product Fields registry.
 *      @param mixed  $value     Default null.
 *      @param int    $post_id   Product ID.
 *      @param string $slug      Field slug.
 *
 *      @return mixed|null Custom field value or null if not handled.
 */
class Helpers {


    /**
     * Get the product group term object for a given post ID.
     *
     * @param int $post_id
     * @return \WP_Term|false
     */
    public static function get_product_group($post_id) {
        $product = wc_get_product($post_id);
        if ($product && $product->is_type('variation')) {
            $post_id = $product->get_parent_id();
        }

        $terms = get_the_terms($post_id, 'luma_product_fields_product_group');
        if (empty($terms)) {
            return false;
        }

        return $terms[0];
    }


    /**
     * Get the product group term ID for a given post.
     *
     * @param int $post_id
     * @return int|false
     */
    public static function get_product_group_id( $post_id ) {
        $group = self::get_product_group( $post_id );
        if (!$group) {
            return false;
        }
        return $group->term_id;
    }


    /**
     * Get the product group slug for a given product.
     *
     * If no product group is assigned, returns "general".
     *
     *
     * @param int $post_id
     * @return string
     */
    public static function get_product_group_slug( int $post_id ): string
    {
        $group = self::get_product_group( $post_id );

        // No group assigned → return "general"
        if ( ! $group ) {
            return 'general';
        }

        return $group->slug ?: 'general';
    }


    /**
     * Get the product group slug from term ID.
     *
     * @param int $term_id
     * @return string|null
     */
    public static function get_product_group_slug_from_term_id($term_id) {
        $term = get_term_by('term_id', $term_id, 'luma_product_fields_product_group');
        return $term->slug;
    }


   /**
    * Get all fields for a given product group term ID.
    *
    * @param int $term_id
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
    public static function get_fields_for_group_id($term_id) {
        $slug = self::get_product_group_slug_from_term_id($term_id);
        return self::get_all_fields($slug);
    }
    
    
    
    /**
     * Retrieve the fields attached to a specific group slug.
     *
     * @param string $group_slug
     * @return array
     */
    public static function get_fields_for_group( string $group_slug ): array {
        return self::get_all_fields( $group_slug );
    }


    /**
     * Get all fields (meta + taxonomy), optionally filtered by product group.
     *
     * @param string|null $group Product group slug, or null for all fields.
     *                           Use "general" for products without a group.
     * @return array<int, array<string,mixed>>
     */
    public static function get_all_fields( $group = null ): array
    {
        $fields = array_merge(
            MetaManager::get_all( null ),
            TaxonomyManager::get_all( null )
        );

        if ( null !== $group ) {
            $fields = array_filter(
                $fields,
                static function ( array $field ) use ( $group ): bool {
                    $groups = $field['groups'] ?? [];

                    if ( ! is_array( $groups ) ) {
                        $groups = [ (string) $groups ];
                    }

                    $is_global = empty( $groups );

                    if ( 'general' === $group ) {
                        return $is_global;
                    }

                    if ( $is_global ) {
                        return true;
                    }

                    return in_array( $group, $groups, true );
                }
            );
        }

        $fields = array_values( $fields );

        /**
         * Filter the list of field definitions after merging and group filtering.
         *
         * Use this to modify or reorder fields (e.g. per-group ordering).
         *
         * @param array<int,array<string,mixed>> $fields Field definitions.
         * @param string|null                    $group  Product group slug, or null for all fields.
         */
        $fields = apply_filters( 'luma_product_fields_get_all_fields', $fields, $group );

        return $fields;
    }


    /**
     * Retrieve the value of a custom field for a specific product.
     *
     * @param int    $post_id The product or variation ID.
     * @param string $slug    The field slug (taxonomy name or meta key).
     *
     * @return mixed|null
     */
    public static function get_field_value(int $post_id, string $slug) {
        $field_definition = self::get_field_definition_by_slug($slug);
        if ( ! $field_definition ) {

            /**
             * Allow external plugins to return values for fields that are not
             * registered in the Luma Product Fields registry.
             *
             * @hook luma_product_fields_external_field_value
             *
             * @param mixed  $value     Default null.
             * @param int    $post_id   Product ID.
             * @param string $slug      Field slug.
             *
             * @return mixed|null Custom field value or null if not handled.
             */
            $external_value = apply_filters(
                'luma_product_fields_external_field_value',
                null,
                $post_id,
                $slug
            );

            return $external_value;
        }

        $type      = $field_definition['type'] ?? 'text';
        $product   = wc_get_product($post_id);
        $is_var    = $product && $product->is_type('variation');
        $value     = null;

        if (self::is_taxonomy_field($slug)) {
            $terms = wp_get_post_terms($post_id, $slug);
            if (!is_wp_error($terms) && !self::is_truly_empty($terms)) {
                $value = FieldTypeRegistry::supports($type, 'multiple_values')
                    ? wp_list_pluck($terms, 'slug')
                    : $terms[0]->slug;
            }
        }

        if ($value === null && !self::is_taxonomy_field($slug)) {
            $raw = get_post_meta($post_id, FieldStorage::META_PREFIX . $slug, true);
            $value = self::is_truly_empty($raw) ? null : $raw;
        }

        if ($value === null && $is_var) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                return self::get_field_value($parent_id, $slug);
            }
        }

        return $value;
    }




    /**
     * Get the formatted display value for a field (with optional taxonomy links).
     *
     * Falls back to field renderer if no special formatting rules apply.
     *
     * @param int          $post_id Product ID.
     * @param array|string $field   Field definition or field slug.
     * @param bool         $links   Whether to render links for taxonomy terms.
     *
     * @return string Formatted value ready for frontend display.
     */
    public static function get_formatted_field_value( int $post_id, $field, bool $links = true ): string {
        if ( ! is_array( $field ) ) {
            $field = self::get_field_definition_by_slug( $field );
        }

        if ( ! $field ) {
            /**
             * Allow external data providers to inject values for unknown fields
             * (e.g., EAN, SKU, cost, stock, etc.)
             *
             * @hook luma_product_fields_formatted_field_value
             */
            return apply_filters( 'luma_product_fields_formatted_field_value', '', null, [ 'slug' => $field ], $post_id, $links );
        }

        $type = $field['type'] ?? 'text';
        $slug = $field['slug'] ?? '';
        $unit = $field['unit'] ?? '';
        $value = self::get_field_value( $post_id, $slug );

        if ( self::is_truly_empty( $value ) ) {
            return '';
        }

        // Handle taxonomy fields without links
        if (
            self::is_taxonomy_field( $slug )
            && ! $links
        ) {
            $terms = get_the_terms( $post_id, $slug );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                $formatted_value = '';
            } else {
                $names = implode( ', ', array_map( fn( $term ) => esc_html( $term->name ), $terms ) );
                $formatted_value = $unit ? $names . ' ' . self::get_formatted_unit_html( $unit ) : $names;
            }
        }

        // Handle minmax field (special case)
        elseif ( $type === 'minmax' && is_array( $value ) ) {
            $min = $value['min'] ?? '';
            $max = $value['max'] ?? '';
            if ( $min === '' && $max === '' ) {
                $formatted_value = '';
            } else {
                $range = trim( "$min – $max", ' –' );
                $formatted_value = $unit ? $range . ' ' . self::get_formatted_unit_html( $unit ) : $range;
            }
        }

        // All other fields: use default rendering
        else {
            $rendered = (new FieldRenderer)->render_field_value( $field, $post_id );
            $formatted_value = ( $rendered !== '' && $unit )
                ? $rendered . ' ' . self::get_formatted_unit_html( $unit )
                : $rendered;
        }

        /**
         * @hook luma_product_fields_formatted_field_value
         * Allows developers to filter the final formatted display value for a field.
         *
         * @param string $formatted_value The final display value.
         * @param mixed $value The input value
         * @param array $field   Field definition
         * @param int    $post_id         Product ID.
         * @param bool   $links   Whether to render links for taxonomy terms.
         */
        return apply_filters( 'luma_product_fields_formatted_field_value', $formatted_value, $value, $field, $post_id, $links );
    }


    /**
     * Get all frontend-visible fields for a given product.
     *
     * Retrieves and filters all fields belonging to the product’s group,
     * respecting visibility and variation settings, and returns structured
     * data ready for frontend rendering.
     *
     * @param int  $product_id                Product ID (simple or variation).
     * @param bool $include_variation_fields  Whether to include fields marked for variation use.
     *
     * @return array<int,array<string,mixed>> List of frontend-visible fields.
     */
    public static function get_frontend_fields_for_product( int $product_id, bool $include_variation_fields = true ): array {
        $fields_to_display = [];

        $product_group = self::get_product_group_slugs( $product_id )[0] ?? 'general';
        $all_fields = self::get_all_fields( $product_group );

        foreach ( $all_fields as $field ) {
            if ( ! empty( $field['hide_in_frontend'] ) ) {
                continue;
            }
            if ( ! $include_variation_fields && ! empty( $field['variation'] ) ) {
                continue;
            }

            $value = self::get_field_value( $product_id, $field['slug'] );
            if ( $value === '' || $value === null || ( is_array( $value ) && empty( $value ) ) ) {
                continue;
            }

            $link = ! empty( $field['is_taxonomy'] ) ? self::get_term_link( $value ) : '';
            $fields_to_display[] = [
                'slug'          => $field['slug'],
                'label'         => $field['label'],
                'value'         => $value,
                'link'          => $link,
                'unit'          => $field['unit'] ?? '',
                'frontend_desc' => $field['frontend_desc'] ?? '',
            ];
        }

        return $fields_to_display;
    }




    /**
     * Retrieve a full field definition by its storage key.
     *
     * @param string $slug
     * @return array|null
     */
    public static function get_field_definition_by_slug(string $slug): ?array {
        $fields = array_merge(
            MetaManager::get_all(),
            TaxonomyManager::get_all()
        );

        foreach ($fields as $field) {
            if (!isset($field['slug'])) {
                continue;
            }

            if (
                $field['slug'] === $slug ||
                FieldStorage::META_PREFIX . $field['slug'] === $slug
            ) {
                return $field;
            }
        }

        return null;
    }


    /**
     * Check whether a field is taxonomy-based.
     *
     * @param string $slug Field slug.
     * @return bool True if taxonomy-based, false otherwise.
     */
    public static function is_taxonomy_field(string $slug): bool {
        $field = self::get_field_definition_by_slug($slug);
        if ( ! $field ) {
            return false;
        }

        $is_taxonomy_flag = isset($field['is_taxonomy']) && filter_var($field['is_taxonomy'], FILTER_VALIDATE_BOOLEAN);

        $type = $field['type'] ?? '';
        $type_def = \Luma\ProductFields\Registry\FieldTypeRegistry::get($type);
        $is_taxonomy_storage = ($type_def['storage'] ?? '') === 'taxonomy';

        return $is_taxonomy_flag || $is_taxonomy_storage;
    }


    /**
     * Get all product group slugs for a product.
     *
     * @param int $post_id
     * @return string[]
     */
    public static function get_product_group_slugs(int $post_id): array {
        $group = self::get_product_group($post_id);
        return $group ? [ $group->slug ] : [];
    }


    /**
     * Check if a given product ID is a variation.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_variation_product(int $post_id): bool {
        $product = wc_get_product($post_id);
        return $product && $product->is_type('variation');
    }


    /**
     * Generate a term link for a given slug if available.
     *
     * @param string|string[] $slug
     * @return string
     */
    public static function get_term_link($slug): string {
        $slugs = (array) $slug;
        $term = get_term_by('slug', $slugs[0], $slugs[0]); // Assumes field slug equals taxonomy name

        if ($term && !is_wp_error($term)) {
            return get_term_link($term);
        }

        return '';
    }
    
    
    /**
     * Return HTML-wrapped label for a unit, using the registered unit list if available.
     *
     * @param string $unit Unit slug.
     * @return string HTML with unit label wrapped in span.
     */
    public static function get_formatted_unit_html( $unit ) {
        $registered_units = FieldTypeRegistry::get_units();
        $unit_label = $registered_units[ $unit ] ?? $unit;                
        return ' <span class="luma-product-fields-unit">' . esc_html( $unit_label ) . '</span>';
    }
    
    
    /**
     * True if value is really empty (but NOT for 0 or "0").
     */
    public static function is_truly_empty( $v ): bool
    {
        if ( $v === 0 || $v === '0' ) {
            return false;
        }
        if ( is_array( $v ) ) {
            return $v === [];
        }
        return $v === '' || $v === null;
    }




    
    
}
