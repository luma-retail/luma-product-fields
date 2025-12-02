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
    protected $tax = 'lpf_product_group';


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
        add_action( 'admin_print_footer_scripts-edit.php', [ $this, 'print_quick_edit_script' ], 99 );
    }


    /**
     * Registers the 'lpf_product_group' taxonomy for WooCommerce products (non-hierarchical).
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

        if ( false === strpos( $clauses['join'], ' tr_pg ' ) ) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->term_relationships} tr_pg ON ({$wpdb->posts}.ID = tr_pg.object_id)
                  LEFT JOIN {$wpdb->term_taxonomy}   tt_pg ON (tr_pg.term_taxonomy_id = tt_pg.term_taxonomy_id AND tt_pg.taxonomy = %s)
                  LEFT JOIN {$wpdb->terms}           t_pg  ON (tt_pg.term_id = t_pg.term_id) ",
                $this->tax
            );
        }

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

        $selected = isset( $_GET[ $this->tax ] ) ? sanitize_text_field( wp_unslash( $_GET[ $this->tax ] ) ) : '';

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

        if ( empty( $_GET[ $this->tax ] ) || '0' === $_GET[ $this->tax ] ) {
            return;
        }

        $value = sanitize_text_field( wp_unslash( $_GET[ $this->tax ] ) );

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

        $field_name = 'lpf_pg_single';
        ?>
        <div class="inline-edit-group">
            <h4><?php echo esc_html__( 'Product group', 'luma-product-fields' ); ?></h4>

            <label class="alignleft">
                <span class="title"><?php echo esc_html__( 'Set to', 'luma-product-fields' ); ?></span>
                <span class="input-text-wrap">
                    <select name="<?php echo esc_attr( $field_name ); ?>" class="lpf-pg-single" style="min-width: 16rem;">
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
        $raw = isset( $_REQUEST['lpf_pg_single'] ) ? wp_unslash( $_REQUEST['lpf_pg_single'] ) : '';

        if ( '' === $raw ) {
            return; // — No change —
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
     * Verifies bulk edit request. Accepts common WooCommerce and WP bulk-edit nonces,
     * and falls back to a capability check if none are present (to be resilient across versions).
     *
     * @return bool
     */
    protected function verify_bulk_edit_request(): bool {
        // WooCommerce bulk edit (most common in recent versions)
        if ( ! empty( $_REQUEST['_woocommerce_bulk_edit_nonce'] )
            && wp_verify_nonce( wp_unslash( $_REQUEST['_woocommerce_bulk_edit_nonce'] ), 'woocommerce_bulk_edit' ) ) {
            return current_user_can( 'edit_products' );
        }

        // Alternate WooCommerce/inline flows sometimes use "security" key
        if ( ! empty( $_REQUEST['security'] )
            && wp_verify_nonce( wp_unslash( $_REQUEST['security'] ), 'woocommerce_bulk_edit' ) ) {
            return current_user_can( 'edit_products' );
        }

        // Core WP bulk list-table nonce
        if ( ! empty( $_REQUEST['_wpnonce'] )
            && wp_verify_nonce( wp_unslash( $_REQUEST['_wpnonce'] ), 'bulk-posts' ) ) {
            return current_user_can( 'edit_products' );
        }

        // As a safe fallback, require capability in admin.
        if ( is_admin() && current_user_can( 'edit_products' ) ) {
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

        // Name unique to Quick Edit (separate from bulk)
        $field_name = 'lpf_pg_quick_single';
        ?>
        <br/>
        <div class="inline-edit-group">
            <label class="alignleft" style="min-width: 260px;">
                <span class="title"><?php echo esc_html__( 'Product group', 'luma-product-fields' ); ?></span>
                <span class="input-text-wrap">
                    <select name="<?php echo esc_attr( $field_name ); ?>" class="lpf-pg-quick-single" style="min-width:16rem;">
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

        // Reuse permissive verifier (accepts WC/WP nonces; falls back to caps).
        if ( ! $this->verify_bulk_edit_request() ) {
            return;
        }

        $raw = isset( $_REQUEST['lpf_pg_quick_single'] ) ? wp_unslash( $_REQUEST['lpf_pg_quick_single'] ) : '';
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
            'fields'     => 'ids',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $exists ) || empty( $exists ) ) {
            return;
        }

        wp_set_object_terms( $product_id, [ $term_id ], $this->tax, false );
    }



    /**
     * Injects a Quick Edit helper that waits for inlineEditPost to exist,
     * then preselects the Product group dropdown based on the visible label
     * in the "taxonomy-{$this->tax}" column. Matching is case/space-insensitive.
     *
     * @return void
     */
    public function print_quick_edit_script() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }

        $colSel = esc_js( 'td.column-taxonomy-' . $this->tax ); // e.g. td.column-taxonomy-lpf_product_group
        ?>
        <script>
        (function($){
            var DEBUG = false;
            function log(){ if (DEBUG && window.console && console.log) console.log.apply(console, arguments); }

            function attachPatch(){
                // Already patched?
                if ( inlineEditPost.edit.__fk_pg_patched ) {
                    return;
                }

                var coreEdit = inlineEditPost.edit;

                inlineEditPost.edit = function(id){
                    coreEdit.apply(this, arguments);

                    var postId = (typeof id === 'object') ? parseInt(this.getId(id), 10) : parseInt(id, 10);
                    if (!postId) { log('[HPF PG] no postId'); return; }

                    var $row    = $('#post-' + postId);
                    var $quick  = $('#edit-' + postId);
                    var $select = $quick.find('select.lpf-pg-quick-single');
                    if (!$row.length || !$quick.length || !$select.length) {
                        log('[HPF PG] missing row/quick/select'); 
                        return;
                    }

                    var label = $.trim( $row.find('<?php echo $colSel; ?>').text() );
                    log('[HPF PG] label:', label);

                    $select.val('');
                    if (!label) { return; }

                    function norm(s){ return $.trim(String(s)).replace(/\s+/g,' ').toLowerCase(); }
                    var target = norm(label);
                    var matched = false;

                    $select.find('option').each(function(){
                        if (norm($(this).text()) === target) {
                            $select.val( $(this).val() );
                            matched = true;
                            log('[HPF PG] matched option value:', $(this).val());
                            return false; // break
                        }
                    });

                    if (!matched) { log('[HPF PG] no option matched for:', label); }
                };

                inlineEditPost.edit.__fk_pg_patched = true;
                log('[HPF PG] Quick Edit patched');
            }

            // Wait until inlineEditPost is available, then attach once.
            var tries = 0, maxTries = 200; // ~5s at 25ms
            var timer = setInterval(function(){
                if (window.inlineEditPost && inlineEditPost.edit) {
                    clearInterval(timer);
                    attachPatch();
                } else if (++tries >= maxTries) {
                    clearInterval(timer);
                    log('[HPF PG] inlineEditPost not found after waiting');
                }
            }, 25);
        })(jQuery);
        </script>
        <?php
    }



}
