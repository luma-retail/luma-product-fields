<?php
/**
 * Field Storage Class
 *
 * @package Luma\ProductFields
 *
 */
namespace Luma\ProductFields\Product;

use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Utils\CacheInvalidator;


/**
 * Handles saving and validation of individual product fields.
 *
 * @hook Luma\ProductFields\save_field
 *       Filter fired only when a field slug is not defined in the registry.
 *       Allows external code to save custom fields (e.g. EAN, cost price).
 *
 *       Filter signature:
 *           bool $saved       Default false. External handler MUST return true or false.
 *           int  $product_id  Product ID.
 *           string $field_slug Field slug.
 *           mixed $value       Raw value to save.
 *
 */			
class FieldStorage {
	
	
	/**
     * Meta key prefix 
     *
     * @const string
     */
    public const META_PREFIX = '_lpf_';
	
	

	/**
	 * Save a single field value to a product.
	 *
	 * If the field definition includes a 'save_cb' callback, it will be used
	 *
	 * If the field is not defined in the registry, hook Luma\ProductFields\save_field is fired.
	 *
	 * If no 'save_cb' is present and the value is empty (empty string, null, or empty array),
	 * the field value will be deleted:
	 * - Meta fields: post meta is deleted using delete_post_meta().
	 * - Taxonomy fields: all terms are removed via wp_set_object_terms( [], $taxonomy ).
	 *
	 * Otherwise, the method falls back to an internal type-specific save method,
	 * or to save_text_value() if no method is defined.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $field_slug Field slug.
	 * @param mixed  $value      Field value to save. Empty values will clear the field if no save_cb is set.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function save_field( int $product_id, string $field_slug, $value ): bool
	{
		$field = Helpers::get_field_definition_by_slug( $field_slug );
		if ( ! $field ) {
			return apply_filters(
				'Luma\ProductFields\save_field',
				false,           
				$product_id,
				$field_slug,
				$value
			);
		}

		$field_type = FieldTypeRegistry::get( $field['type'] );
		if ( ! $field_type ) {
			return false;
		}

		CacheInvalidator::invalidate_product_meta_cache( $product_id );

		if ( isset( $field_type['save_cb'] ) && is_callable( $field_type['save_cb'] ) ) {
			return (bool) call_user_func( $field_type['save_cb'], $product_id, $field, $value );
		}
		// empty value - clear and return true ("0" is valid)
		if (
			($value === '' || $value === null || (is_array($value) && empty($value)))
			&& $value !== '0' && $value !== 0
		) {
			// Clear the field
			if ( ($field_type['storage'] ?? '') === 'taxonomy' ) {
				$taxonomy = $field['taxonomy'] ?? $field['slug'];
				wp_set_object_terms( $product_id, [], $taxonomy );
				return true;
			}

			delete_post_meta( $product_id, $field['meta_key'] ?? self::META_PREFIX . $field_slug );
			return true;
		}
				
		$type   = $field['type'] ?? 'text';
		$method = 'save_' . $type . '_value';
		if ( method_exists( __CLASS__, $method ) ) {
			return self::$method( $product_id, $field, $value );
		}
	
		return self::save_text_value( $product_id, $field, $value );
	}


	
	/**
	 * Save a text field value.
	 */
	protected static function save_text_value( int $product_id, array $field, $value ): bool {
		$meta_key = self::META_PREFIX . $field['slug'];
		if ( $value === '' || $value === null ) {
			delete_post_meta( $product_id, $meta_key );
			return true;
		}
		update_post_meta( $product_id, $meta_key, sanitize_text_field( $value ) );
		return true;
	}



	/**
	 * Save an integer field value.
	 */
	protected static function save_integer_value( int $product_id, array $field, $value ): bool {
		if ( is_array( $value ) ) $value = reset( $value );
		$meta_key = self::META_PREFIX . $field['slug'];

		if ( $value === '' || $value === null ) {
			delete_post_meta( $product_id, $meta_key );
			return true;
		}

		$int = filter_var( $value, FILTER_VALIDATE_INT );
		if ( $int === false ) {
			delete_post_meta( $product_id, $meta_key );
			return true;
		}

		update_post_meta( $product_id, $meta_key, $int );
		return true;
	}


	/**
	 * Save a number (float) field value with locale normalization.
	 */
	protected static function save_number_value( int $product_id, array $field, $value ): bool {
		if ( is_array( $value ) ) $value = reset( $value );
		$meta_key = self::META_PREFIX . $field['slug'];

		if ( $value === '' || $value === null ) {
			delete_post_meta( $product_id, $meta_key );
			return true;
		}

		$normalized = str_replace( ',', '.', (string) $value );
		$float = filter_var( $normalized, FILTER_VALIDATE_FLOAT );

		if ( $float === false ) {
			delete_post_meta( $product_id, $meta_key );
			return true;
		}

		update_post_meta( $product_id, $meta_key, $float );
		return true;
	}


	/**
	 * Save a single taxonomy term.
	 *
	 * @param int   $product_id The product ID.
	 * @param array $field      The field definition array (must contain 'slug').
	 * @param mixed $value      The term to assign, or null to remove.
	 *
	 * @return bool Whether the operation succeeded.
	 */
	protected static function save_single_value( int $product_id, array $field, $value ): bool {
		// Unset the term if value is null
		if ( $value === null ) {
			return wp_set_object_terms( $product_id, null, $field['slug'] ) !== false;
		}

		// If array is passed (e.g. from a form), extract first item
		if ( is_array( $value ) ) {
			$value = isset( $value[0] ) ? $value[0] : '';
		}

		$value = sanitize_text_field( $value );

		// If value is now an empty string, also unset the term
		if ( $value === '' ) {
			return wp_set_object_terms( $product_id, null, $field['slug'] ) !== false;
		}

		// Set the single term
		return wp_set_object_terms( $product_id, $value, $field['slug'] ) !== false;
	}


	/**
	 * Save multiple taxonomy terms.
	 */
	protected static function save_multiple_value( int $product_id, array $field, $value ): bool {
			
		if ( ! is_array( $value ) ) {
			return false;
		}

		$terms = array_map( 'sanitize_text_field', $value );

		return wp_set_object_terms( $product_id, $terms, $field['slug'] ) !== false;
	}


	/**
	 * Save a min/max range field.
	 */
	protected static function save_minmax_value( int $product_id, array $field, $value ): bool {
		if ( ! is_array( $value ) ) {
			delete_post_meta( $product_id, self::META_PREFIX . $field['slug'] );
			return true;
		}

		$min = str_replace( ',', '.', $value['min'] ?? '' );
		$max = str_replace( ',', '.', $value['max'] ?? '' );

		$min = filter_var( $min, FILTER_VALIDATE_FLOAT );
		$max = filter_var( $max, FILTER_VALIDATE_FLOAT );

		$result = [];
		if ( $min !== false ) $result['min'] = $min;
		if ( $max !== false ) $result['max'] = $max;

		if ( empty( $result ) ) {
			delete_post_meta( $product_id, self::META_PREFIX . $field['slug'] );
			return true;
		}

		return (bool) update_post_meta( $product_id, self::META_PREFIX . $field['slug'], $result );
	}

	/**
	 * Save autocomplete taxonomy terms.
	 */
	protected static function save_autocomplete_value( int $product_id, array $field, $value ): bool {
		$term_slugs = [];

		foreach ( (array) $value as $item ) {
			$sanitized = sanitize_text_field( $item );

			$term = get_term_by( 'slug', $sanitized, $field['slug'] )
				?: get_term_by( 'name', $sanitized, $field['slug'] );

			if ( ! $term ) {
				$created = wp_insert_term( $sanitized, $field['slug'] );
				if ( ! is_wp_error( $created ) ) {
					$term_slugs[] = $created['slug'] ?? sanitize_title( $sanitized );
				}
			} else {
				$term_slugs[] = $term->slug;
			}
		}

		return wp_set_object_terms( $product_id, $term_slugs, $field['slug'] ) !== false;
	}
	
	
	
	/**
	 * Call an internal save method by name. Intended for use by custom field types.
	 *
	 * @param string $method     Internal method name, e.g. 'save_taxonomy_multiple_values'.
	 * @param int    $post_id
	 * @param array  $field
	 * @param mixed  $value
	 *
	 * @return void
	 */
	public static function save_by_core_method( string $method, int $post_id, array $field, $value ): void {
		if ( method_exists( self::class, $method ) ) {
			self::$method( $field['slug'], $post_id, $value, $field );
		} else {
			// Optional: add logging here
			trigger_error( "Unknown FieldStorage method: $method", E_USER_WARNING );
		}
	}
	
	
	/**
	 * Delete (clear) a field value for a product.
	 *
	 * @param string $field_slug Field slug.
	 * @param int    $post_id    Product ID.
	 * @return bool Always true for idempotent clear operations.
	 */
	public static function delete_field( string $field_slug, int $post_id ): bool {
		
		error_log( __CLASS__ . '::' . __FUNCTION__ . "( {$field_slug} , {$post_id} )" );
		
		self::save_field( $post_id, $field_slug, '' );
		return true;
	}
	
		
}
