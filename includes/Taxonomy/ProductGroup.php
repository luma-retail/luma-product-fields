<?php
/**
 * Product Group class
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Taxonomy;

use WP_Query;

defined( 'ABSPATH' ) || exit;


/**
 * Product Group class
 *
 * Manages the lpf_product_group taxonomy and adds sorting + filtering in the Products list.
 */
class ProductGroup {

    /**
     * Taxonomy slug.
     *
     * @var string
     */
    protected $tax = 'luma_product_fields_product_group';


    /**
     * Constructor.
    */
    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
        add_action( 'current_screen', [ $this, 'maybe_boot_admin' ] );
        add_action( 'woocommerce_product_bulk_edit_end', [ $this, 'render_bulk_edit_field' ] );
        add_action( 'woocommerce_product_bulk_edit_save', [ $this, 'handle_bulk_edit_save' ], 10, 1 );
        add_action( 'woocommerce_product_quick_edit_end', [ $this, 'render_quick_edit_field' ] );
        add_action( 'woocommerce_product_quick_edit_save', [ $this, 'handle_quick_edit_save' ], 10, 1 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_quick_edit_assets' ] );
    }


    /**
     * Registers the 'luma_product_fields_product_group' taxonomy for WooCommerce products (non-hierarchical).
     *
     * @return void
     */
    public function register() {
        $labels = [
            'name'               => _x( 'Product groups', 'taxonomy general name', 'luma-product-fields' ),
            'singular_name'      => _x( 'Product group', 'taxonomy general name', 'luma-product-fields' ),
            'menu_name'          => __( 'Product groups', 'luma-product-fields' ),
            'edit_item'          => __( 'Edit Product group', 'luma-product-fields' ),
            'update_item'        => __( 'Update Product group', 'luma-product-fields' ),
            'add_new_item'       => __( 'Add New Product group', 'luma-product-fields' ),
            'new_item_name'      => __( 'New Product group Name', 'luma-product-fields' ),
            'not_found'          => __( 'No Product groups Found', 'luma-product-fields' ),
            'back_to_items'      => __( 'Back to Product groups', 'luma-product-fields' ),
        ];

        $args = [
            'labels'             => $labels,
            'rewrite'            => [ 'slug' => 'productgroup' ],
            'description'        => __( 'Main product groups in the shop', 'luma-product-fields' ),
            'hierarchical'       => false, // Product groups are flat
            'public'             => true,
            'show_in_rest'       => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_admin_column'  => true,  // Adds the column in Products list
            'query_var'          => true,
            'meta_box_cb'        => false,
        ];

        register_taxonomy( $this->tax, 'product', $args );
    }


    /**
     * Boots admin hooks only on the Products list table screen.
     *
     * @return void
     */
    public function maybe_boot_admin() {
        $screen = get_current_screen();

        if ( empty( $screen ) || 'edit-product' !== $screen->id ) {
            return;
        }

        add_filter( 'manage_edit-product_sortable_columns', [ $this, 'register_sortable_column' ] );
        add_filter( 'posts_clauses', [ $this, 'handle_sorting_clauses' ], 10, 2 );
        add_action( 'restrict_manage_posts', [ $this, 'render_taxonomy_filter' ] );
        add_action( 'pre_get_posts', [ $this, 'apply_taxonomy_filter' ] );
    }


    /**
     * Registers the Product group column as sortable.
     *
     * Note: The column key equals the taxonomy slug when 'show_admin_column' is true.
     *
     * @param array $columns Existing sortable columns.
     * @return array
     */
    public function register_sortable_column( array $columns ): array {
        $columns[ 'taxonomy-' . $this->tax ] = $this->tax;
        return $columns;
    }


    /**
     * Alters SQL to sort by the (first) Product group term name when orderby=lpf_product_group.
     *
     * @param array    $clauses SQL clauses.
     * @param WP_Query $query   Main list-table query.
     * @return array
     */
    public function handle_sorting_clauses( array $clauses, \WP_Query $query ): array {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return $clauses;
        }
        if ( 'product' !== $query->get( 'post_type' ) ) {
            return $clauses;
        }

        // We set this orderby value in register_sortable_column()
        if ( $this->tax !== $query->get( 'orderby' ) ) {
            return $clauses;
        }

        global $wpdb;

    $join = isset( $clauses['join'] ) ? (string) $clauses['join'] : '';

    if ( false === strpos( $join, ' tr_pg ' ) ) {
        $join .= $wpdb->prepare(
            " LEFT JOIN {$wpdb->term_relationships} tr_pg ON ({$wpdb->posts}.ID = tr_pg.object_id)
            LEFT JOIN {$wpdb->term_taxonomy}   tt_pg ON (tr_pg.term_taxonomy_id = tt_pg.term_taxonomy_id AND tt_pg.taxonomy = %s)
            LEFT JOIN {$wpdb->terms}           t_pg  ON (tt_pg.term_id = t_pg.term_id) ",
            $this->tax
        );
    }

    $clauses['join'] = $join;


        $groupby = trim( (string) $clauses['groupby'] );
        if ( '' === $groupby ) {
            $clauses['groupby'] = "{$wpdb->posts}.ID";
        } elseif ( false === strpos( $groupby, "{$wpdb->posts}.ID" ) ) {
            $clauses['groupby'] .= ", {$wpdb->posts}.ID";
        }

        $order = ( 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ) ? 'DESC' : 'ASC';
        $clauses['orderby'] = " MIN(t_pg.name) {$order}, {$wpdb->posts}.ID {$order} ";

        return $clauses;
    }


    /**
     * Renders a dropdown filter for Product group above the list table.
     *
     * @return void
     */
    public function render_taxonomy_filter() {
        global $typenow;

        if ( 'product' !== $typenow ) {
            return;
        }

        $selected = isset( $_GET[ $this->tax ] ) ? sanitize_text_field( wp_unslash( $_GET[ $this->tax ] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        wp_dropdown_categories( [
            'show_option_all' => esc_html__( 'All Product groups', 'luma-product-fields' ),
            'taxonomy'        => $this->tax,
            'name'            => $this->tax,
            'orderby'         => 'name',
            'selected'        => $selected,
            'hierarchical'    => false, // flat taxonomy
            'show_count'      => false,
            'hide_empty'      => false,
            'value_field'     => 'slug', // cleaner URLs
        ] );
    }


    /**
     * Applies the selected Product group filter (slug by default, supports term_id fallback).
     *
     * @param WP_Query $query Main list-table query.
     * @return void
     */
    public function apply_taxonomy_filter( WP_Query $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( 'product' !== $query->get( 'post_type' ) ) {
            return;
        }

        if ( empty( $_GET[ $this->tax ] ) || '0' === $_GET[ $this->tax ] ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $value = sanitize_text_field( wp_unslash( $_GET[ $this->tax ] ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( is_numeric( $value ) ) {
            $query->set( 'tax_query', [
                [
                    'taxonomy' => $this->tax,
                    'field'    => 'term_id',
                    'terms'    => (int) $value,
                ],
            ] );
        } else {
            $query->set( 'tax_query', [
                [
                    'taxonomy' => $this->tax,
                    'field'    => 'slug',
                    'terms'    => $value,
                ],
            ] );
        }
    }


    /**
     * Renders a single-select bulk edit field for Product group.
     *
     * Options:
     * - "— No change —" (do nothing)
     * - "— Clear —" (remove any product group)
     * - A list of all Product groups (non-hierarchical)
     *
     * @return void
     */
    public function render_bulk_edit_field() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && 'edit-product' !== $screen->id ) {
            return;
        }

        $terms = get_terms( [
            'taxonomy'   => $this->tax,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        $field_name  = LUMA_PRODUCT_FIELDS_PREFIX . '_pg_single';
        $field_class = LUMA_PRODUCT_FIELDS_PREFIX . '-pg-single';
        ?>
        <div class="inline-edit-group">
            <h4><?php echo esc_html__( 'Product group', 'luma-product-fields' ); ?></h4>

            <label class="alignleft">
                <span class="title"><?php echo esc_html__( 'Set to', 'luma-product-fields' ); ?></span>
                <span class="input-text-wrap">
                    <select name="<?php echo esc_attr( $field_name ); ?>" class="<?php echo esc_attr( $field_class ); ?>" style="min-width: 16rem;">
                        <option value=""><?php echo esc_html__( '— No change —', 'luma-product-fields' ); ?></option>
                        <option value="__clear__"><?php echo esc_html__( '— Clear —', 'luma-product-fields' ); ?></option>
                        <?php if ( ! is_wp_error( $terms ) && $terms ) : ?>
                            <?php foreach ( $terms as $term ) : ?>
                                <option value="<?php echo esc_attr( (string) $term->term_id ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </span>
            </label>

            <p class="description" style="clear:both;margin-top:.5em;">
                <?php echo esc_html__( 'Choose a single Product group to assign to all selected products, or “— Clear —” to remove it. Leave as “— No change —” to keep current groups.', 'luma-product-fields' ); ?>
            </p>
        </div>
        <?php
    }


    /**
     * Handles bulk edit save for the single-select Product group field.
     *
     * Behavior:
     * - empty value      → no change
     * - "__clear__"      → clear all product groups
     * - numeric term_id  → replace with that single term
     *
     * Hook: woocommerce_product_bulk_edit_save
     *
     * @param \WC_Product $product Product being processed in bulk save.
     * @return void
     */
    public function handle_bulk_edit_save( $product ) {
        if ( ! $product instanceof \WC_Product ) {
            return;
        }
        if ( ! $this->verify_bulk_edit_request() ) {
            return;
        }

        $field_key = LUMA_PRODUCT_FIELDS_PREFIX . '_pg_single';

        $raw = isset( $_REQUEST[ $field_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field_key ] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( '' === $raw ) {
            return;
        }

        $product_id = $product->get_id();

        if ( '__clear__' === $raw ) {
            wp_set_object_terms( $product_id, [], $this->tax, false );
            return;
        }

        $term_id = absint( $raw );
        if ( $term_id <= 0 ) {
            return;
        }

        $exists = get_terms( [
            'taxonomy'   => $this->tax,
            'include'    => [ $term_id ],
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );

        if ( is_wp_error( $exists ) || empty( $exists ) ) {
            return;
        }

        wp_set_object_terms( $product_id, [ $term_id ], $this->tax, false );
    }


    /**
     * Verify this request is a valid bulk-edit request
     * (WooCommerce or core list-table).
     *
     * @return bool
     */
    protected function verify_bulk_edit_request(): bool {
        if ( ! is_admin() || ! current_user_can( 'edit_products' ) ) {
            return false;
        }

        // WooCommerce bulk edit nonce.
        $wc_nonce = isset( $_REQUEST['_woocommerce_bulk_edit_nonce'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['_woocommerce_bulk_edit_nonce'] ) )
            : '';

        if ( '' !== $wc_nonce && wp_verify_nonce( $wc_nonce, 'woocommerce_bulk_edit' ) ) {
            return true;
        }

        // Alt Woo flow sometimes uses "security".
        $security_nonce = isset( $_REQUEST['security'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) )
            : '';

        if ( '' !== $security_nonce && wp_verify_nonce( $security_nonce, 'woocommerce_bulk_edit' ) ) {
            return true;
        }

        // Core WP list-table bulk edit nonce.
        $wp_nonce = isset( $_REQUEST['_wpnonce'] )
            ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) )
            : '';

        if ( '' !== $wp_nonce && wp_verify_nonce( $wp_nonce, 'bulk-posts' ) ) {
            return true;
        }

        return false;
    }


    /**
     * Renders a single-select field for Product group in Quick Edit.
     *
     * Options:
     * - "— Select —" (no change unless user picks one or Clear)
     * - "— Clear —" (remove group)
     * - All Product groups
     *
     * @return void
     */
    public function render_quick_edit_field() {
        $terms = get_terms( [
            'taxonomy'   => $this->tax,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        $field_name  = LUMA_PRODUCT_FIELDS_PREFIX . '_pg_quick_single';
        $field_class = LUMA_PRODUCT_FIELDS_PREFIX . '-pg-quick-single';
        $nonce_name  = LUMA_PRODUCT_FIELDS_PREFIX . '_quick_edit_nonce';
        ?>
        <br/>
        <?php wp_nonce_field( LUMA_PRODUCT_FIELDS_NONCE_ACTION, $nonce_name ); ?>
        <div class="inline-edit-group">
            <label class="alignleft" style="min-width: 260px;">
                <span class="title"><?php echo esc_html__( 'Product group', 'luma-product-fields' ); ?></span>
                <span class="input-text-wrap">
                    <select name="<?php echo esc_attr( $field_name ); ?>" class="<?php echo esc_attr( $field_class ); ?>" style="min-width:16rem;">
                        <option value=""><?php esc_html_e( '— Select —', 'luma-product-fields' ); ?></option>
                        <option value="__clear__"><?php esc_html_e( '— Clear —', 'luma-product-fields' ); ?></option>
                        <?php if ( ! is_wp_error( $terms ) && $terms ) : ?>
                            <?php foreach ( $terms as $term ) : ?>
                                <option value="<?php echo esc_attr( (string) $term->term_id ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </span>
            </label>
        </div>
        <?php
    }


    /**
     * Saves Product group from Quick Edit.
     *
     * Behavior:
     * - empty       → no change
     * - "__clear__" → clear Product group
     * - numeric id  → set as the only Product group
     *
     * Hook: woocommerce_product_quick_edit_save
     *
     * @param \WC_Product $product Product being saved via Quick Edit.
     * @return void
     */
    public function handle_quick_edit_save( $product ) {
        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $product_id ) ) {
            return;
        }

        $nonce_key = LUMA_PRODUCT_FIELDS_PREFIX . '_quick_edit_nonce';
        $nonce     = isset( $_REQUEST[ $nonce_key ] )
            ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_key ] ) )
            : '';

        if ( '' === $nonce || ! wp_verify_nonce( $nonce, LUMA_PRODUCT_FIELDS_NONCE_ACTION ) ) {
            return;
        }

        $field_key = LUMA_PRODUCT_FIELDS_PREFIX . '_pg_quick_single';
        $raw       = isset( $_REQUEST[ $field_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field_key ] ) ) : '';

        if ( '' === $raw ) {
            return;
        }

        if ( '__clear__' === $raw ) {
            wp_set_object_terms( $product_id, [], $this->tax, false );
            return;
        }

        $term_id = absint( $raw );
        if ( $term_id <= 0 ) {
            return;
        }

        $exists = get_terms( [
            'taxonomy'   => $this->tax,
            'include'    => [ $term_id ],
            'fields'     => 'ids',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $exists ) || empty( $exists ) ) {
            return;
        }

        wp_set_object_terms( $product_id, [ $term_id ], $this->tax, false );
    }


    /**
     * Enqueue Quick Edit helper script for the product list screen.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public function enqueue_quick_edit_assets( string $hook_suffix ): void {

        // Only needed on the product list table (Quick Edit for products).
        if ( 'edit.php' !== $hook_suffix ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }

        wp_register_script(
            'luma-product-fields-quickedit',
            LUMA_PRODUCT_FIELDS_PLUGIN_URL . '/js/admin/quickedit.js',
            [ 'jquery', 'inline-edit-post' ],
            LUMA_PRODUCT_FIELDS_PLUGIN_VER,
            true
        );

        wp_localize_script(
            'luma-product-fields-quickedit',
            'lumaProductFieldsQuickEdit',
            [
                'columnSelector' => 'td.column-taxonomy-' . $this->tax,
                'selectSelector' => 'select.' . LUMA_PRODUCT_FIELDS_PREFIX . '-pg-quick-single',
                'debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
            ]
        );

        wp_enqueue_script( 'luma-product-fields-quickedit' );
    }

}
