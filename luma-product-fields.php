<?php
/**
 * Plugin Name:           Luma Product Fields
 * Plugin URI:            https://github.com/luma-retail/luma-product-fields
 * Description:           Structured, linkable WooCommerce product specifications with fast inline editing.
 * Version:               1.2.1
 * Requires at least:     6.0
 * Tested up to:          7.0
 * Requires PHP:          8.0
 * Author:                Terje Johansen
 * Author URI:            https://luma-retail.com
 * Text Domain:           luma-product-fields
 * Domain Path:           /languages
 * License:               GPL-2.0-or-later
 * License URI:           https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields;

defined( 'ABSPATH' ) || exit;



define( 'LUMA_PRODUCT_FIELDS_PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'LUMA_PRODUCT_FIELDS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUMA_PRODUCT_FIELDS_PLUGIN_VER', get_file_data( __FILE__, [ 'Version' => 'Version' ] ) );
define( 'LUMA_PRODUCT_FIELDS_PREFIX', 'luma_product_fields' );


/**
 * PSR-4 compatible autoloader for plugin classes under the Luma\ProductFields\ namespace.
 *
 * @param string $class Fully-qualified class name.
 * @return void
 */
spl_autoload_register(function($class) {
    $prefix   = 'Luma\\ProductFields\\';
    $base_dir = __DIR__ . '/includes/';
    $len      = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});


// Run the plugin bootstrap
(new Plugin())->run();


// Register activation and deactivation hooks
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Activation', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Activation', 'deactivate' ) );



