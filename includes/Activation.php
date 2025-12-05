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
     * Runs on plugin activation.
     *
     * Sets a one-time flag to flush rewrite rules on the next request,
     * after taxonomies and custom rewrite rules have been registered.
     *
     * @return void
     */
    public static function activate() : void {
        update_option( 'luma_product_fields_flush_rewrite', 1, true );
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

}
