<?php
namespace Luma\ProductFields\Frontend;

defined('ABSPATH') || exit;

use WP_Term_Query;
use Luma\ProductFields\Taxonomy\TermMetaManager;

/**
 * Renders a taxonomy "index" view with:
 * - Featured terms shown using WooCommerce's category loop markup (inherits Woo styles)
 * - Other terms listed as a simple UL (paginated)
 *
 * @Todo: Update Extension link in render_header
 *
 */
class TaxonomyIndexRenderer
{
    /** @var int Default "other terms" per page (filterable). */
    protected int $per_page_default = 48;

    /** @var int Default Woo-style columns for featured grid (filterable). */
    protected int $featured_columns_default = 4;


    /**
     * Render the taxonomy index (header, featured grid, other terms, pagination).
     *
     * @param string $taxonomy Taxonomy slug.
     * @return void
     */
    public function render( string $taxonomy ): void {
        if ( ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
            status_header( 404 );
            echo '<div class="lpf-tax-index lpf-tax-index--invalid">';
            esc_html_e( 'Not found.', 'lpf-fields' );
            echo '</div>';
            return;
        }

        $paged    = max( 1, (int) get_query_var( 'paged', 1 ) );
        $per_page = (int) apply_filters( 'Luma/ProductFields/taxonomy_index/terms_per_page', $this->per_page_default, $taxonomy );

        echo '<div class="lpf-tax-index lpf-tax-index--' . esc_attr( $taxonomy ) . '">';

        $this->render_header( $taxonomy, 0 );

        // Featured terms (Woo-style category tiles).
        $featured_ids = $this->render_featured_terms( $taxonomy );

        // Other terms (UL, paginated) excluding featured.
        [ $other_terms, $total_pages ] = $this->query_other_terms( $taxonomy, $featured_ids, $paged, $per_page );
        $this->render_other_terms_list( $other_terms, $taxonomy );
        $this->render_pagination( $paged, $total_pages );

        echo '</div>';
    }


    /**
     * Render the page header (title).
     *
     * @param string $taxonomy
     * @param int    $total_terms (reserved for future use)
     * @return void
     *
     */
    protected function render_header( string $taxonomy, int $total_terms ): void {
        $tax_obj = get_taxonomy( $taxonomy );
        $title   = $tax_obj && ! empty( $tax_obj->label ) ? $tax_obj->label : ucfirst( $taxonomy );

        echo '<header class="lpf-tax-index__header woocommerce-products-header">';
        echo '<h1 class="lpf-tax-index__title">' . esc_html( $title ) . '</h1>';        
        if ( class_exists( '\Luma\ProductFields\Extensions\Tax\TaxDesc' ) ) {
                        
            $desc_html = \Luma\ProductFields\Extensions\Tax\TaxDesc::get( $taxonomy );            
            if ( $desc_html ) {
                echo '<div class="lpf-taxonomy-description term-description">';
                echo wp_kses_post( $desc_html );
                echo '</div>';
            }            
        }        
        echo '</header>';
    }


    /**
     * Render the "Featured" section using WooCommerce category loop hooks.
     *
     * Hook order matches templates/content-product_cat.php:
     * - woocommerce_before_subcategory
     * - woocommerce_before_subcategory_title
     * - woocommerce_shop_loop_subcategory_title
     * - woocommerce_after_subcategory_title
     * - woocommerce_after_subcategory
     *
     * @param string $taxonomy
     * @return array<int> IDs of featured terms (for excluding from "other" list)
     */
    protected function render_featured_terms( string $taxonomy ): array {
        $args = apply_filters( 'Luma/ProductFields/taxonomy_index/featured_query_args', [
            'taxonomy'   => $taxonomy,
            'hide_empty' => apply_filters( 'Luma/ProductFields/taxonomy_index/featured_hide_empty', true, $taxonomy ),
            'orderby'    => 'name',
            'order'      => 'ASC',
        ], $taxonomy );

        $featured = \Luma\ProductFields\Taxonomy\TermMetaManager::get_featured_terms( $taxonomy, $args );

        if ( empty( $featured ) ) {
            return [];
        }

        $columns = (int) apply_filters( 'Luma/ProductFields/taxonomy_index/featured_columns', $this->featured_columns_default, $taxonomy );
        $columns = max( 1, min( 6, $columns ) );

        echo '<section class="lpf-tax-index__featured">';
        echo '<h2 class="screen-reader-text">' . esc_html__( 'Featured', 'lpf-fields' ) . '</h2>';
        echo '<ul class="products product-categories columns-' . esc_attr( (string) $columns ) . '">';

        foreach ( $featured as $term ) {
            echo '<li class="product-category product">';

            /**
             * Matches Woo template hook order.
             * $term is a WP_Term (same shape Woo expects for $category).
             */
            do_action( 'woocommerce_before_subcategory', $term );
            do_action( 'woocommerce_before_subcategory_title', $term );
            do_action( 'woocommerce_shop_loop_subcategory_title', $term );
            do_action( 'woocommerce_after_subcategory_title', $term );
            do_action( 'woocommerce_after_subcategory', $term );

            echo '</li>';
        }

        echo '</ul>';
        echo '</section>';

        return array_map( static fn( $t ) => (int) $t->term_id, $featured );
    }

    /**
     * Query "other" (non-featured) terms with pagination.
     *
     * Excludes any IDs returned by render_featured_terms().
     *
     * Filters:
     * - Luma/ProductFields/taxonomy_index/hide_empty
     * - Luma/ProductFields/taxonomy_index/orderby
     * - Luma/ProductFields/taxonomy_index/order
     * - Luma/ProductFields/taxonomy_index/other_terms_query_args
     *
     * @param string   $taxonomy
     * @param int[]    $exclude_term_ids
     * @param int      $paged
     * @param int      $per_page
     * @return array{0:array<int,\WP_Term>,1:int} [terms, total_pages]
     */
    protected function query_other_terms( string $taxonomy, array $exclude_term_ids, int $paged, int $per_page ): array {
        $meta_query = [
            'relation' => 'OR',
            [
                'key'     => TermMetaManager::META_FEATURED,
                'value'   => 'yes',
                'compare' => '!=',
            ],
            [
                'key'     => TermMetaManager::META_FEATURED,
                'compare' => 'NOT EXISTS',
            ],
        ];

        $query_args = [
            'taxonomy'     => $taxonomy,
            'hide_empty'   => apply_filters( 'Luma/ProductFields/taxonomy_index/hide_empty', true, $taxonomy ),
            'orderby'      => apply_filters( 'Luma/ProductFields/taxonomy_index/orderby', 'name', $taxonomy ),
            'order'        => apply_filters( 'Luma/ProductFields/taxonomy_index/order', 'ASC', $taxonomy ),
            'number'       => $per_page,
            'offset'       => ( $paged - 1 ) * $per_page,
            'count_total'  => true, // ensure found_terms is populated
            'meta_query'   => $meta_query,
            'exclude'      => $exclude_term_ids,
        ];

        /**
         * Allow full control over the "other terms" query args.
         */
        $query_args = apply_filters(
            'Luma/ProductFields/taxonomy_index/other_terms_query_args',
            $query_args,
            $taxonomy,
            $paged,
            $per_page,
            $exclude_term_ids
        );

        $term_query  = new WP_Term_Query( $query_args );
        $terms       = $term_query->get_terms();
        $total       = is_numeric( $term_query->found_terms ?? null ) ? (int) $term_query->found_terms : ( is_array( $terms ) ? count( $terms ) : 0 );
        $total_pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );

        return [ is_array( $terms ) ? $terms : [], $total_pages ];
    }


    /**
     * Render the "Other terms" as a UL of links (no images).
     *
     * @param array<int,\WP_Term> $terms
     * @param string              $taxonomy
     * @return void
     */
    protected function render_other_terms_list( array $terms, string $taxonomy ): void {
        echo '<section class="lpf-tax-index__others">';

        if ( empty( $terms ) ) {
            echo '<p class="lpf-tax-index__empty">' . esc_html__( 'No items to display.', 'lpf-fields' ) . '</p>';
            echo '</section>';
            return;
        }

        echo '<h2 class="screen-reader-text">' . esc_html__( 'All terms', 'lpf-fields' ) . '</h2>';
        echo '<ul class="lpf-tax-index__list">';

        foreach ( $terms as $term ) {
            $url = get_term_link( $term );

            echo '<li class="lpf-tax-index__list-item">';
            echo   '<h3 class="lpf-tax-index__name"><a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a></h3>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</section>';
    }


    /**
     * Render pagination using core paginate_links() for the "other terms" list.
     *
     * @param int $current
     * @param int $total_pages
     * @return void
     */
    protected function render_pagination( int $current, int $total_pages ): void {
        if ( $total_pages <= 1 ) {
            return;
        }

        $links = paginate_links( [
            'base'      => str_replace( 999999, '%#%', esc_url( get_pagenum_link( 999999 ) ) ),
            'format'    => 'page/%#%/',
            'current'   => $current,
            'total'     => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'type'      => 'list',
        ] );

        if ( $links ) {
            echo '<nav class="lpf-tax-index__pagination" aria-label="' . esc_attr__( 'Pagination', 'lpf-fields' ) . '">' . $links . '</nav>';
        }
    }
}
