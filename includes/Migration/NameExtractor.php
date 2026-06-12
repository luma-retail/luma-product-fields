<?php
/**
 * Product/variation name extractor.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Migration;

use Luma\ProductFields\Product\FieldStorage;
use Luma\ProductFields\Utils\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Extract values from product and variation names.
 */
class NameExtractor extends LegacyMetaMigrator {

    /**
     * Run name extraction and field population.
     *
     * @param array{
     *     field: array<string,mixed>,
     *     include_simple: bool,
     *     include_variations: bool,
     *     number_index?: int,
     *     match_unit?: bool,
     *     skip_existing?: bool
     * } $config
     * @param bool $dry_run
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function run( array $config, bool $dry_run = true ): array {
        $field = $config['field'] ?? [];
        $slug  = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';

        if ( '' === $slug ) {
            return [];
        }

        $include_simple     = ! empty( $config['include_simple'] );
        $include_variations = ! empty( $config['include_variations'] );

        if ( ! $include_simple && ! $include_variations ) {
            return [];
        }

        $number_index = isset( $config['number_index'] ) ? (int) $config['number_index'] : 0;
        $match_unit   = ! empty( $config['match_unit'] );
        $skip_existing = ! empty( $config['skip_existing'] );

        $summary = [];

        foreach ( $this->iterate_product_ids( $include_simple, $include_variations ) as $product_id ) {
            $name_value = $this->get_source_name( $product_id );

            if ( '' === $name_value ) {
                continue;
            }

            $existing_raw = $this->get_direct_existing_value( $product_id, $field );
            $summary[ $product_id ][ $slug ]['existing'] = is_array( $existing_raw )
                ? wp_json_encode( $existing_raw )
                : (string) $existing_raw;

            if ( $skip_existing && ! $this->is_empty_value( $existing_raw ) ) {
                $summary[ $product_id ][ $slug ]['status']   = 'skipped';
                $summary[ $product_id ][ $slug ]['reason']   = __( 'Existing value present', 'luma-product-fields' );
                $summary[ $product_id ][ $slug ]['original'] = $name_value;
                $summary[ $product_id ][ $slug ]['new']      = $summary[ $product_id ][ $slug ]['existing'];
                continue;
            }

            $value = $this->extract_from_name( $name_value, $field, $number_index, $match_unit );

            if ( null === $value ) {
                $summary[ $product_id ][ $slug ]['status']   = 'skipped';
                $summary[ $product_id ][ $slug ]['reason']   = __( 'No valid value extracted from name.', 'luma-product-fields' );
                $summary[ $product_id ][ $slug ]['original'] = $name_value;
                continue;
            }

            if ( ! $dry_run ) {
                FieldStorage::save_field( $product_id, $slug, $value );
            }

            $summary[ $product_id ][ $slug ]['status']   = $dry_run ? 'dry-run' : 'migrated';
            $summary[ $product_id ][ $slug ]['original'] = $name_value;
            $summary[ $product_id ][ $slug ]['new']      = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
        }

        return $summary;
    }

    /**
     * Iterate product IDs in pages.
     *
     * @param bool $include_simple
     * @param bool $include_variations
     * @return \Generator<int>
     */
    protected function iterate_product_ids( bool $include_simple, bool $include_variations ): \Generator {
        $post_types = [];

        if ( $include_simple ) {
            $post_types[] = 'product';
        }

        if ( $include_variations ) {
            $post_types[] = 'product_variation';
        }

        if ( empty( $post_types ) ) {
            return;
        }

        $page = 1;
        $args = [
            'post_type'      => $post_types,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => 300,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'paged'          => 1,
            'no_found_rows'  => false,
        ];

        do {
            $args['paged'] = $page;
            $query         = new \WP_Query( $args );

            foreach ( $query->posts as $product_id ) {
                $product_id = absint( $product_id );
                if ( $product_id > 0 ) {
                    yield $product_id;
                }
            }

            $page++;
        } while ( $page <= (int) $query->max_num_pages );
    }

    /**
     * Get source name string from product or variation.
     *
     * @param int $product_id
     * @return string
     */
    protected function get_source_name( int $product_id ): string {
        $post_type = get_post_type( $product_id );

        if ( 'product_variation' === $post_type ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                return trim( wp_strip_all_tags( (string) $product->get_name() ) );
            }
        }

        return trim( wp_strip_all_tags( (string) get_the_title( $product_id ) ) );
    }

    /**
     * Extract typed value from name.
     *
     * @param string $name_value
     * @param array<string,mixed> $field
     * @param int $number_index
     * @param bool $match_unit
     * @return mixed
     */
    protected function extract_from_name( string $name_value, array $field, int $number_index, bool $match_unit ) {
        $type = (string) ( $field['type'] ?? 'text' );

        if ( ! in_array( $type, [ 'number', 'integer', 'minmax' ], true ) ) {
            return null;
        }

        if ( 'minmax' === $type ) {
            return $this->transform_minmax( $name_value );
        }

        if ( $match_unit && ! empty( $field['unit'] ) ) {
            $with_unit = $this->extract_number_with_unit( $name_value, (string) $field['unit'] );
            if ( null !== $with_unit ) {
                return 'integer' === $type ? (int) $with_unit : $with_unit;
            }
        }

        if ( 'integer' === $type ) {
            return $this->transform_integer( $name_value, $number_index );
        }

        return $this->transform_number( $name_value, $number_index );
    }

    /**
     * Read direct existing field value without variation inheritance.
     *
     * @param int $product_id
     * @param array<string,mixed> $field
     * @return mixed
     */
    protected function get_direct_existing_value( int $product_id, array $field ) {
        $slug = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';

        if ( '' === $slug ) {
            return '';
        }

        if ( Helpers::is_taxonomy_field( $slug ) ) {
            $terms = wp_get_post_terms( $product_id, $slug, [ 'fields' => 'names' ] );
            return is_wp_error( $terms ) ? [] : $terms;
        }

        return get_post_meta( $product_id, FieldStorage::META_PREFIX . $slug, true );
    }

    /**
     * Determine if value is empty for migration purposes.
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
