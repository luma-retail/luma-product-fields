<?php
namespace Luma\ProductFields\Taxonomy;

defined( 'ABSPATH' ) || exit;

use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Taxonomy\ProductGroup;

/**
 * Class TaxonomyManagerInlineEdit
 *
 * Hides inline (Quick/Bulk) edit controls for taxonomies managed by the plugin.
 * Uses TaxonomyManager::get_all() to resolve taxonomy slugs, and also hides the
 * default inline UI for ProductGroup::$tax_name (since you render a custom control).
 */
class TaxonomyManagerInlineEdit {

    /**
     * Target post type.
     *
     * @var string
     */
    protected $post_type = 'product';

    /**
     * Cached hidden taxonomy map: ['slug' => true, ...]
     *
     * @var array<string,bool>|null
     */
    protected $hidden = null;


    /**
     * Constructor.
     *
     * Wires filters and a cache flush on settings change.
     */
    public function __construct() {
        add_filter( 'quick_edit_show_taxonomy', [ $this, 'filter_inline_taxonomy_visibility' ], 10, 3 );
        add_filter( 'bulk_edit_show_taxonomy',  [ $this, 'filter_inline_taxonomy_visibility' ], 10, 3 );
    }


    /**
     * Returns (and memoizes) the map of taxonomies to hide in Quick/Bulk Edit.
     *
     * Uses TaxonomyManager::get_all() (list of arrays with at least 'slug' and 'is_taxonomy').
    * Always includes ProductGroup::$tax_name to suppress the default inline UI,
     * since you render your own single-select control.
     *
     * @return array<string,bool>
     */
    protected function get_hidden_map(): array {
        if ( null !== $this->hidden ) {
            return $this->hidden;
        }

        $defs  = TaxonomyManager::get_all();
        $slugs = [];

        foreach ( $defs as $def ) {
            if ( ! empty( $def['is_taxonomy'] ) && ! empty( $def['slug'] ) ) {
                $slugs[] = sanitize_title( $def['slug'] );
            }
        }

        // Also hide the default inline UI for Product group.
        $slugs[] = ProductGroup::$tax_name;

        $this->hidden = array_fill_keys( $slugs, true );
        return $this->hidden;
    }


    /**
     * Filter: control visibility of taxonomies in Quick/Bulk Edit.
     *
     * @param bool              $show      Default visibility.
     * @param string|\WP_Taxonomy $taxonomy  Taxonomy name or object.
     * @param string            $post_type Current post type.
     * @return bool
     */
    public function filter_inline_taxonomy_visibility( $show, $taxonomy, $post_type ): bool {
        if ( $post_type !== $this->post_type ) {
            return (bool) $show;
        }

        $name = is_object( $taxonomy ) ? $taxonomy->name : (string) $taxonomy;
        $hidden = $this->get_hidden_map();

        return isset( $hidden[ $name ] ) ? false : (bool) $show;
    }

}
