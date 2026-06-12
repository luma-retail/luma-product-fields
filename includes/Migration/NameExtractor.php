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
    *     skip_existing?: bool,
    *     exclude_product_ids?: int[]
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
        $exclude_product_ids = $this->normalize_excluded_product_ids( $config['exclude_product_ids'] ?? [] );

        $summary = [];

        foreach ( $this->iterate_product_ids( $include_simple, $include_variations ) as $product_id ) {
            if ( isset( $exclude_product_ids[ $product_id ] ) ) {
                continue;
            }

            if ( ! $this->field_applies_to_product( $product_id, $field ) ) {
                continue;
            }

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
                return $this->normalize_source_name( (string) $product->get_name() );
            }
        }

        return $this->normalize_source_name( (string) get_the_title( $product_id ) );
    }

    /**
     * Normalize a source title string before numeric extraction.
     *
     * Decodes HTML entities (e.g. &#8211; / &ndash;) so entity numbers are not
     * parsed as candidate numeric values.
     *
     * @param string $raw_name Raw product/variation name.
     * @return string
     */
    protected function normalize_source_name( string $raw_name ): string {
        $clean = trim( wp_strip_all_tags( $raw_name ) );
        if ( '' === $clean ) {
            return '';
        }

        $decoded = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return trim( preg_replace( '/\s+/u', ' ', $decoded ) ?? $decoded );
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

            // Unit mode should not fall back to positional extraction when any
            // non-matching unit token is present (e.g. target mm, source 60 cm).
            if ( $this->contains_any_unit_alias( $name_value ) ) {
                return null;
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

    /**
     * Check whether the selected field applies to the product's effective group.
     *
     * @param int                 $product_id Product or variation ID.
     * @param array<string,mixed> $field      Field definition.
     * @return bool
     */
    protected function field_applies_to_product( int $product_id, array $field ): bool {
        $group_slug = Helpers::get_product_group_slug( $product_id );
        $groups     = $field['groups'] ?? [];

        if ( ! is_array( $groups ) ) {
            $groups = [ (string) $groups ];
        }

        $groups = array_values(
            array_filter(
                array_map(
                    static fn( $group ) => sanitize_key( (string) $group ),
                    $groups
                ),
                static fn( string $group ) => '' !== $group
            )
        );

        if ( empty( $groups ) ) {
            return true;
        }

        return in_array( $group_slug, $groups, true );
    }

    /**
     * Normalize excluded product IDs into a fast lookup map.
     *
     * @param mixed $raw_ids
     * @return array<int,bool>
     */
    protected function normalize_excluded_product_ids( $raw_ids ): array {
        if ( ! is_array( $raw_ids ) ) {
            return [];
        }

        $ids = [];
        foreach ( $raw_ids as $raw_id ) {
            $id = absint( $raw_id );
            if ( $id > 0 ) {
                $ids[ $id ] = true;
            }
        }

        return $ids;
    }

    /**
     * Check whether the source text includes any recognized unit alias token.
     *
     * @param string $value
     * @return bool
     */
    protected function contains_any_unit_alias( string $value ): bool {
        $decoded    = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $normalized = function_exists( 'mb_strtolower' )
            ? mb_strtolower( $decoded, 'UTF-8' )
            : strtolower( $decoded );
        $aliases_map = $this->get_unit_aliases();

        foreach ( $aliases_map as $aliases ) {
            if ( ! is_array( $aliases ) ) {
                continue;
            }

            foreach ( $aliases as $alias ) {
                $alias_value = rtrim( (string) $alias, '.' );
                $alias = trim(
                    function_exists( 'mb_strtolower' )
                        ? mb_strtolower( $alias_value, 'UTF-8' )
                        : strtolower( $alias_value )
                );
                if ( '' === $alias ) {
                    continue;
                }

                if ( preg_match( '/\\b' . preg_quote( $alias, '/' ) . '\\b/u', $normalized ) ) {
                    return true;
                }
            }
        }

        return false;
    }
}
