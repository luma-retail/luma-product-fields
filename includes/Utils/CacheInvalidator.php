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
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		$like = $wpdb->esc_like( '_transient_luma_product_fields_meta_fields_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
				$like
			)
		);

		foreach ( $transients as $transient ) {
			if ( ! preg_match( '/_(\d+)$/', (string) $transient, $matches ) ) {
				continue;
			}

			if ( (int) $matches[1] !== $product_id ) {
				continue;
			}

			$key = str_replace( '_transient_', '', $transient );
			\delete_transient( $key );
		}
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients = $wpdb->get_col(
			"SELECT option_name FROM $wpdb->options WHERE option_name LIKE '_transient_luma_product_fields_meta_fields_%'"
		);

		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );
			\delete_transient( $key );
		}
	}
}
