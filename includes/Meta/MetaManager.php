<?php
/**
 * Meta Field Manager class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Meta;

defined('ABSPATH') || exit;

use Luma\ProductFields\Product\FieldStorage;

/**
* Field Manager class
*
* Manages global meta-based field definitions (e.g., text, number, minmax).
*
*/
class MetaManager {

    const OPTION_KEY = 'lpf_meta_fields';

    /**
     * Get all meta-based fields
     *
     * @param string $group Group slug
     * @return array[]
     */
    public static function get_all( $group = NULL ): array {
        $fields = get_option(self::OPTION_KEY, []);
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
     * Get a single meta field by slug
     *
     * @param string $slug
     * @return array|null
     */
    public static function get_field(string $slug): ?array {
        foreach (self::get_all() as $field) {
            if ($field['slug'] === $slug) {
                return $field;
            }
        }
        return null;
    }


    /**
     * Save or update a field
     *
     * @param array $data
     * @return void
     */
    public static function save_field(array $data): void {
        $fields = self::get_all();
        // Replace existing if it exists
        $fields = array_filter($fields, fn($f) => $f['slug'] !== $data['slug']);
        $fields[] = $data;

        update_option(self::OPTION_KEY, array_values($fields));
    }


    
    /**
     * Delete a meta field.
     *
     * Deletes only the field definition by default. When $full_cleanup is true,
     * all product-level values associated with the field are also removed.
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
        }

        $fields = get_option( self::OPTION_KEY, [] );
        $fields = array_filter( $fields, fn( $field ) => ( $field['slug'] ?? '' ) !== $slug );
        update_option( self::OPTION_KEY, array_values( $fields ) );
    }





}
