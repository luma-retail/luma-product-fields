<?php
/**
 * Activation class.
 *
 * Handles plugin activation and deactivation logic, including taxonomy initialization.
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields;

defined( 'ABSPATH' ) || exit;

/**
 * Activation class.
 *
 * Handles plugin activation and deactivation logic, including taxonomy initialization.
 */
class Activation {


    /**
     * @return void
     */
	public static function activate(): void {

		// Flag for flushing rewrite rules on next request
		if ( false === get_option( LUMA_PRODUCT_FIELDS_PREFIX . '_flush_rewrite', false ) ) {
			add_option( LUMA_PRODUCT_FIELDS_PREFIX . '_flush_rewrite', 1, '', false );
		} else {
			update_option( LUMA_PRODUCT_FIELDS_PREFIX . '_flush_rewrite', 1, false );
		}

		self::maybe_update_prefixes();
	}



    /**
     * Runs on plugin deactivation.
     *
     * Flushes rewrite rules to remove plugin routes and logs deactivation.
     *
     * @return void
     */
    public static function deactivate() : void {
        flush_rewrite_rules( false );
    }


    /**
	 * One-time migration for legacy option keys (copy-forward only).
	 *
	 * @return void
	 */
	private static function maybe_update_prefixes(): void {

		$flag = LUMA_PRODUCT_FIELDS_PREFIX . '_migrated_2026_01';

		if ( get_option( $flag, false ) ) {
			return;
		}

		$map = [
			'luma_product_fields_meta_fields'        => LUMA_PRODUCT_FIELDS_PREFIX . '_meta_fields',
			'luma_product_fields_dynamic_taxonomies' => LUMA_PRODUCT_FIELDS_PREFIX . '_dynamic_taxonomies',
		];

		foreach ( $map as $old => $new ) {

			$old_val = get_option( $old, null );
			if ( null !== $old_val && false === get_option( $new, false ) ) {
				add_option( $new, $old_val, '', false );
			}
		}

		// Mark migration complete (no autoload).
		add_option( $flag, 1, '', false );
	}



}
