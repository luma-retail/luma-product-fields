<?php
namespace Luma\ProductFields\Taxonomy;

defined('ABSPATH') || exit;

/**
 * Adds virtual index routes for each linkable dynamic taxonomy.
 *
 * Example: /designer/ → index.php?lpf_tax_index=1&lpf_tax=designer
 *
 * @since 3.0.0
 */
class TaxonomyIndexRouter
{
    /** @var callable Returns string[] of taxonomy slugs (linkable only). */
    protected $slugs_provider;


    /**
     * @param callable $slugs_provider Callable that returns an array of taxonomy slugs.
     */
    public function __construct( callable $slugs_provider ) {
        $this->slugs_provider = $slugs_provider;
    }


    /**
     * Register WP hooks.
     *
     * @return void
     */
    public function register_hooks(): void {
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        // Run after your taxonomies are registered on init.
        add_action( 'init', [ $this, 'add_rewrite_rules' ], 20 );
    }


    /**
     * Add custom query vars used by the virtual index route.
     *
     * @param string[] $vars
     * @return string[]
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'lpf_tax_index';
        $vars[] = 'lpf_tax';
        return $vars;
    }


    /**
     * Add a pretty permalink root (no term) for each linkable taxonomy.
     *
     * @return void
     */
    public function add_rewrite_rules(): void {
        $slugs = call_user_func( $this->slugs_provider );
        foreach ( (array) $slugs as $slug ) {
            // /{slug}/ → lpf_tax_index=1&lpf_tax={slug}
            add_rewrite_rule(
                '^' . preg_quote( $slug, '#' ) . '/?$',
                'index.php?lpf_tax_index=1&lpf_tax=' . $slug,
                'top'
            );
        }
    }


    /**
     * Flush rewrite rules safely after activation or when the set of
     * linkable taxonomies changes (e.g., toggling "show links").
     *
     * Call this after taxonomies + rewrite rules are registered.
     *
     * @return void
     */
    public function flush_rewrite_rules_safely(): void {
        flush_rewrite_rules( false );
    }
}
