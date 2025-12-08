<?php
/**
 *  Plugin bootstrap class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields;

use Luma\ProductFields\Utils\CacheInvalidator;
use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Taxonomy\TaxonomyIndexRouter;
use Luma\ProductFields\Taxonomy\TaxonomyManagerInlineEdit;
use Luma\ProductFields\Frontend\Breadcrumbs;
use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Admin\FieldEditor;
use Luma\ProductFields\Admin\FieldOptionsOverview;
use Luma\ProductFields\Admin\ListView;
use Luma\ProductFields\Admin\Ajax;
use Luma\ProductFields\Admin\Settings;
use Luma\ProductFields\Admin\Migration\MigrationPage;
use Luma\ProductFields\Registry\FieldTypeRegistry;            
use Luma\ProductFields\Admin\NotificationManager;
use Luma\ProductFields\Admin\Onboarding;
            
defined('ABSPATH') || exit;

/**
 * Plugin bootstrap class
 *
 * @package Luma\ProductFields
 */
class Plugin {


    /** @var TaxonomyManager|null */
    protected ?TaxonomyManager $tax_manager = null;


    /** @var TaxonomyIndexRouter|null */
    protected ?TaxonomyIndexRouter $tax_index_router = null;






    /**
     * Load and initialize plugin components based on context (admin or frontend).
     *
     * Also boots the virtual taxonomy index routing and performs a one-time
     * rewrite flush after activation (flag set by Activation::activate()).
     *
     * @return void
     */
    public function run(): void {

<?php
/**
 *  Plugin bootstrap class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields;

use Luma\ProductFields\Utils\CacheInvalidator;
use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Taxonomy\TaxonomyManagerInlineEdit;
use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Admin\FieldEditor;
use Luma\ProductFields\Admin\FieldOptionsOverview;
use Luma\ProductFields\Admin\ListView;
use Luma\ProductFields\Admin\Ajax;
use Luma\ProductFields\Admin\Settings;
use Luma\ProductFields\Admin\Migration\MigrationPage;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Admin\NotificationManager;
use Luma\ProductFields\Admin\Onboarding;

defined('ABSPATH') || exit;

/**
 * Plugin bootstrap class
 *
 * @package Luma\ProductFields
 */
class Plugin {


    /** @var TaxonomyManager|null */
    protected ?TaxonomyManager $tax_manager = null;



    /**
     * Load and initialize plugin components based on context (admin or frontend).
     *
     * @return void
     */
    public function run(): void {

        new Taxonomy\ProductGroup();

        $this->tax_manager = new TaxonomyManager();
        $this->tax_manager->init();

        add_action( 'init', function () {
            if ( get_option( 'luma_product_fields_flush_rewrite', false ) ) {
                flush_rewrite_rules( false );
                delete_option( 'luma_product_fields_flush_rewrite' );
            }
        }, 99 );

        add_action( 'init', function () {
            FieldTypeRegistry::init();
        }, 20 );

        add_action( 'save_post_product', [ CacheInvalidator::class, 'invalidate_product_meta_cache' ] );
        add_action( 'save_post_product_variation', [ CacheInvalidator::class, 'invalidate_product_meta_cache' ] );
        add_action( 'woocommerce_update_product', function ( $product_id ) {
            CacheInvalidator::invalidate_product_meta_cache( $product_id );
        } );

        if ( is_admin() ) {
            ( new Admin() )->initialize_hooks();
            new FieldEditor();
            new FieldOptionsOverview();
            new ListView();
            new Ajax();
            MigrationPage::register();
            new TaxonomyManagerInlineEdit();
            new Settings();
            NotificationManager::init();
            Onboarding::init();
        }

        if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            ( new Frontend\FrontendController() )->initialize_hooks();
        }
    }
}

}
