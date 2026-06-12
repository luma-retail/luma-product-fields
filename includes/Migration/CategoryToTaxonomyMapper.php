<?php
/**
 * Category to taxonomy mapper.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Migration;

use Luma\ProductFields\Product\FieldStorage;
use Luma\ProductFields\Utils\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Maps selected product categories to taxonomy field terms.
 */
class CategoryToTaxonomyMapper {

    /**
     * Run category-to-taxonomy mapping.
     *
     * @param array{
     *     field: array<string,mixed>,
     *     selected_category_ids: array<int>,
     *     skip_existing?: bool
     * } $config
     * @param bool $dry_run
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function run( array $config, bool $dry_run = true ): array {
        $field = $config['field'] ?? [];
        $slug  = isset( $field['slug'] ) ? \sanitize_key( (string) $field['slug'] ) : '';

        if ( '' === $slug ) {
            return [];
        }

        $selected_ids = array_values(
            array_filter(
                array_map( '\\absint', (array) ( $config['selected_category_ids'] ?? [] ) )
            )
        );

        if ( empty( $selected_ids ) ) {
            return [];
        }

        $skip_existing = ! empty( $config['skip_existing'] );
        $summary       = [];
        $page          = 1;
        $query_args    = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => 250,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'paged'          => 1,
            'no_found_rows'  => false,
            'tax_query'      => [
                [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => $selected_ids,
                    'include_children' => true,
                    'operator'         => 'IN',
                ],
            ],
        ];

        do {
            $query_args['paged'] = $page;
            $query               = new \WP_Query( $query_args );

            foreach ( $query->posts as $product_id ) {
                $product_id = \absint( $product_id );
                if ( $product_id <= 0 ) {
                    continue;
                }

                $source_paths = $this->get_matching_category_paths( $product_id, $selected_ids );
                $target_terms = $this->get_matching_category_term_names( $product_id, $selected_ids );

                if ( empty( $target_terms ) ) {
                    $summary[ $product_id ][ $slug ] = [
                        'status'   => 'skipped',
                        'reason'   => \__( 'No matching category terms found.', 'luma-product-fields' ),
                        'original' => '',
                    ];
                    continue;
                }

                $existing_raw = $this->get_direct_existing_value( $product_id, $field );
                $summary[ $product_id ][ $slug ]['existing'] = is_array( $existing_raw )
                    ? \wp_json_encode( $existing_raw )
                    : (string) $existing_raw;

                if ( $skip_existing && ! $this->is_empty_value( $existing_raw ) ) {
                    $summary[ $product_id ][ $slug ]['status']   = 'skipped';
                    $summary[ $product_id ][ $slug ]['reason']   = \__( 'Existing value present', 'luma-product-fields' );
                    $summary[ $product_id ][ $slug ]['original'] = implode( ', ', $source_paths );
                    $summary[ $product_id ][ $slug ]['new']      = $summary[ $product_id ][ $slug ]['existing'];
                    continue;
                }

                $value_to_save = $this->normalize_value_for_field( $field, $target_terms );

                if ( ! $dry_run ) {
                    FieldStorage::save_field( $product_id, $slug, $value_to_save );
                }

                $summary[ $product_id ][ $slug ]['status']   = $dry_run ? 'dry-run' : 'migrated';
                $summary[ $product_id ][ $slug ]['original'] = implode( ', ', $source_paths );
                $summary[ $product_id ][ $slug ]['new']      = is_array( $value_to_save )
                    ? implode( ', ', $value_to_save )
                    : (string) $value_to_save;
            }

            $page++;
        } while ( $page <= (int) $query->max_num_pages );

        return $summary;
    }

    /**
     * Get matching product category paths for selected categories.
     *
     * @param int $product_id
     * @param int[] $selected_ids
     * @return string[]
     */
    protected function get_matching_category_paths( int $product_id, array $selected_ids ): array {
        $terms = \get_the_terms( $product_id, 'product_cat' );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $paths = [];

        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) {
                continue;
            }

            if ( ! $this->is_term_in_scope( $term, $selected_ids ) ) {
                continue;
            }

            $paths[] = $this->build_category_path( $term );
        }

        $paths = array_values( array_unique( array_filter( $paths ) ) );
        sort( $paths );

        return $paths;
    }

    /**
     * Get matching product category leaf term names for selected categories.
     *
     * @param int $product_id
     * @param int[] $selected_ids
     * @return string[]
     */
    protected function get_matching_category_term_names( int $product_id, array $selected_ids ): array {
        $terms = \get_the_terms( $product_id, 'product_cat' );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $names = [];

        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) {
                continue;
            }

            if ( ! $this->is_term_in_scope( $term, $selected_ids ) ) {
                continue;
            }

            $names[] = trim( (string) $term->name );
        }

        $names = array_values( array_unique( array_filter( $names ) ) );
        natcasesort( $names );

        return array_values( $names );
    }

    /**
     * Check if a category term is selected or a child of selected terms.
     *
     * @param \WP_Term $term
     * @param int[] $selected_ids
     * @return bool
     */
    protected function is_term_in_scope( \WP_Term $term, array $selected_ids ): bool {
        if ( in_array( (int) $term->term_id, $selected_ids, true ) ) {
            return true;
        }

        $ancestors = \get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' );

        foreach ( $ancestors as $ancestor_id ) {
            if ( in_array( (int) $ancestor_id, $selected_ids, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build full category path from root to term.
     *
     * @param \WP_Term $term
     * @return string
     */
    protected function build_category_path( \WP_Term $term ): string {
        $parts     = [];
        $ancestors = array_reverse( \get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) );

        foreach ( $ancestors as $ancestor_id ) {
            $ancestor = \get_term( (int) $ancestor_id, 'product_cat' );
            if ( $ancestor instanceof \WP_Term ) {
                $parts[] = $ancestor->name;
            }
        }

        $parts[] = $term->name;

        return implode( ' / ', array_filter( $parts ) );
    }

    /**
     * Normalize mapped values depending on field type capabilities.
     *
     * @param array<string,mixed> $field
     * @param string[] $paths
     * @return string|string[]
     */
    protected function normalize_value_for_field( array $field, array $paths ) {
        $type_slug = isset( $field['type'] ) ? (string) $field['type'] : 'text';
        $supports_multiple = \Luma\ProductFields\Registry\FieldTypeRegistry::supports( $type_slug, 'multiple_values' );

        if ( $supports_multiple ) {
            return $paths;
        }

        return (string) reset( $paths );
    }

    /**
     * Read direct existing field value without variation fallback.
     *
     * @param int $product_id
     * @param array<string,mixed> $field
     * @return mixed
     */
    protected function get_direct_existing_value( int $product_id, array $field ) {
        $slug = isset( $field['slug'] ) ? \sanitize_key( (string) $field['slug'] ) : '';

        if ( '' === $slug ) {
            return '';
        }

        if ( Helpers::is_taxonomy_field( $slug ) ) {
            $terms = \wp_get_post_terms( $product_id, $slug, [ 'fields' => 'names' ] );
            return is_wp_error( $terms ) ? [] : $terms;
        }

        return \get_post_meta( $product_id, FieldStorage::META_PREFIX . $slug, true );
    }

    /**
     * Lightweight empty-value check for migration decisions.
     *
     * @param mixed $value
     * @return bool
     */
    protected function is_empty_value( $value ): bool {
        if ( is_array( $value ) ) {
            return empty( array_filter( $value, static fn( $item ) => '' !== trim( (string) $item ) ) );
        }

        return '' === trim( (string) $value );
    }
}
