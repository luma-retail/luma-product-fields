<?php
/**
 * Cache Invalidator class
 *
 * @package Luma\ProductFields
 *
 */

namespace Luma\ProductFields\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Handles invalidation of cached product meta field output.
 *
 * This class is responsible for deleting transients used to cache the
 * rendered frontend product meta fields. It provides both per-product
 * and global invalidation methods, suitable for use in save hooks or
 * manual tools.
 */
class CacheInvalidator {

	/**
	 * Deletes the frontend product meta transient for a specific product.
	 *
	 * @param int $product_id WooCommerce product or variation ID.
	 */
	public static function invalidate_product_meta_cache( int $product_id ) : void {
		delete_transient( 'lpf_meta_fields_' . $product_id );
	}

	
	/**
	 * Deletes the frontend product meta transients for multiple products.
	 *
	 * @param int[] $product_ids Array of product or variation IDs.
	 */
	public static function invalidate_multiple( array $product_ids ) : void {
		foreach ( $product_ids as $id ) {
			self::invalidate_product_meta_cache( $id );
		}
	}


	/**
	 * Deletes all product meta field cache transients.
	 *
	 * Use with care. This is intended for development or global reset
	 * when field definitions have changed in a way that affects all products.
	 */
	public static function invalidate_all_meta_caches() : void {
		global $wpdb;

		$transients = $wpdb->get_col(
			"SELECT option_name FROM $wpdb->options WHERE option_name LIKE '_transient_lpf_meta_fields_%'"
		);

		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );
			delete_transient( $key );
		}
	}
}
