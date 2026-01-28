<?php
/**
 * Overview list class
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

defined( 'ABSPATH' ) || exit;

use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;

/**
 * List view class
 *
 * Adds admin table columns or enhancements to existing list views (e.g., product or taxonomy lists).
 */
class ListView {

    /**
     * Number of products per page.
     *
     * @var int
     */
    public $pagesize = 30;


    /**
     * Offset start for pagination.
     *
     * @var int
     */
    public $start = 0;


    /**
     * Selected product group term ID.
     *
     * @var int|null
     */
    public $selected_group = null;


    /**
     * Title of the page.
     *
     * @var string
     */
    public $page_title = "";


    /**
     * Constructor.
     *
     * Initializes page title and menu registration.
     */
    public function __construct() {
        $this->page_title = __('Product fields overview', 'luma-product-fields');
        add_action('admin_menu', array( $this, 'register_list_page') );
    }


    /**
     * Registers the submenu page under WooCommerce > Products.
     *
     * @return void
     */
    public function register_list_page() {
        $res = add_submenu_page(
            'edit.php?post_type=product',
            $this->page_title,
            $this->page_title,
            'manage_woocommerce',
            'luma-product-fields-overview',
            array( $this, 'render_list_page' ),
            5,
        );
    }


    /**
     * Renders the full list view admin screen.
     *
     * @return void
     */
    public function render_list_page() {

        // Filter selection is read-only, but still verify a nonce because this page
        // processes user-supplied input from a GET form.
        $nonce = isset( $_GET['luma_product_fields_overview_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['luma_product_fields_overview_nonce'] ) )
            : '';

        $this->selected_group = null;
        if ( isset( $_GET['luma-product-fields-product-group'] ) ) {
            if ( '' !== $nonce && wp_verify_nonce( $nonce, 'luma_product_fields_overview_filter' ) ) {
                $this->selected_group = sanitize_title( wp_unslash( (string) $_GET['luma-product-fields-product-group'] ) );
            }
        }

        echo '<div id="luma-product-fields-fields-overview" class="wrap">';
        echo '<h1>' . esc_html($this->page_title) . '</h1>';

        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="product" />';
        echo '<input type="hidden" name="page" value="luma-product-fields-overview" />';

        wp_nonce_field( 'luma_product_fields_overview_filter', 'luma_product_fields_overview_nonce', false );
        
        $args = array(
            'include_all'     => false,
            'include_general' => true,
        );
        $select_html = (new Admin)->get_product_group_select( 'luma-product-fields-product-group', $this->selected_group, null, $args );
        echo wp_kses( $select_html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );

        echo '<input type="submit" class="button" value="' .
            esc_attr__('Choose product group', 'luma-product-fields') .
            '" />';

        echo '</form>';

        if ($this->selected_group === null || $this->selected_group === '') {
            echo '<p>' . esc_html__( 'Please select a product group.', 'luma-product-fields' ) . '</p>';
            echo '</div>';
            return;
        }

        if ($this->selected_group === 'general') {
            $table = new ListViewTable('general');
            $table->prepare_items();
            $table->display();
            echo '<div id="lpf-editor-overlay"></div>';
            echo '</div>';
            return;
        }

        $table = new ListViewTable($this->selected_group);
        $table->prepare_items();
        $table->display();
        echo '<div id="lpf-editor-overlay"></div>';
        echo '</div>';
    }


}
