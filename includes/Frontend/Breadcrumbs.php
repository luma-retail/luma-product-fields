<?php
/**
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Frontend;

use WC_Breadcrumb;
use Luma\ProductFields\Taxonomy\TaxonomyManager;

defined('ABSPATH') || exit;

/**
 * WooCommerce breadcrumb enhancer for this plugin's dynamic taxonomy term pages only.
 *
 * Ensures that on term archives (e.g., /designer/aegyoknit/), the taxonomy label crumb
 * (e.g., "Designer") is present and links to the taxonomy index (e.g., /designer/).
 *
 * Scope:
 * - Only runs for taxonomies derived from TaxonomyManager::get_all() where is_taxonomy is truthy.
 * - Does nothing for unrelated taxonomies (e.g., product_cat).
 *
 * @hook Luma\ProductFields\TaxonomyIndex\add_breadcrumb
 *       Filter to disable the breadcrumb enhancement entirely.
 *       Return `false` to leave WooCommerce breadcrumbs untouched.
 *       @param bool $enabled Default true.
 *
 * @hook Luma\ProductFields\TaxonomyIndex\enabled_taxonomies
 *       Filter the list of taxonomy slugs that should receive breadcrumb tweaks.
 *       Useful if you need to add or remove taxonomies from the managed list.
 *       @param string[] $enabled Array of taxonomy slugs.
 *
 * @hook Luma\ProductFields\TaxonomyIndex\is_linkable_taxonomy
 *       Filter whether a given taxonomy should get a linkable index crumb.
 *       @param bool   $is_linkable Default true.
 *       @param string $taxonomy    Current taxonomy slug.
 *
 * @hook Luma\ProductFields\TaxonomyIndex\breadcrumb_label
 *       Filter the label text used for the taxonomy index crumb.
 *       @param string               $label    Default is singular label, then plural, then ucfirst(slug).
 *       @param string               $taxonomy Current taxonomy slug.
 *       @param array{plural:string, singular:string} $labels Plural and singular labels from get_taxonomy().
 *
 * @hook Luma\ProductFields\TaxonomyIndex\breadcrumb_url
 *       Filter the URL used for the taxonomy index crumb.
 *       @param string $url      Default index URL built from taxonomy rewrite slug.
 *       @param string $taxonomy Current taxonomy slug.
 */

class Breadcrumbs
{
    /**
     * Register the WooCommerce breadcrumbs filter.
     *
     * @return void
     */
    public function register_hooks(): void
    {
        \add_filter('woocommerce_get_breadcrumb', [ $this, 'filter_breadcrumbs' ], 20, 2);
    }


    /**
     * Filter WooCommerce breadcrumbs on term archives for enabled (plugin-managed) taxonomies only.
     *
     * @param array<int, array{0:string,1?:string}> $crumbs
     * @param WC_Breadcrumb                          $breadcrumb
     * @return array<int, array{0:string,1?:string}>
     */
    public function filter_breadcrumbs(array $crumbs, $breadcrumb): array
    {
        if (false === \apply_filters('Luma\ProductFields\TaxonomyIndex\add_breadcrumb', true)) {
            return $crumbs;
        }

        if (! \is_tax()) {
            return $crumbs;
        }

        $qo = \get_queried_object();
        if (! $qo || empty($qo->taxonomy)) {
            return $crumbs;
        }

        $taxonomy = (string) $qo->taxonomy;

        // Only act for taxonomies owned by this plugin (derived from get_all()).
        if (! $this->is_managed_taxonomy($taxonomy)) {
            return $crumbs;
        }

        if (! $this->is_linkable_taxonomy($taxonomy)) {
            return $crumbs;
        }

        return $this->ensure_index_crumb_for_term_view($crumbs, $taxonomy);
    }


    /**
     * Determine whether a taxonomy is managed by this plugin's taxonomy index feature.
     * Source of truth: TaxonomyManager::get_all(), filtered to entries with is_taxonomy truthy.
     * Then we keep only slugs that actually exist as WordPress taxonomies.
     *
     * @param string $taxonomy
     * @return bool
     */
    protected function is_managed_taxonomy(string $taxonomy): bool
    {
        $defs = (array) TaxonomyManager::get_all();

        $enabled = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $is_tax = ! empty($def['is_taxonomy']);
            $slug   = isset($def['slug']) ? (string) $def['slug'] : '';

            if ($is_tax && $slug !== '' && \taxonomy_exists($slug)) {
                $enabled[] = $slug;
            }
        }

        /**
         * Filter the list of taxonomy slugs that should receive breadcrumb tweaks.
         *
         * @param array<int,string> $enabled
         */
        $enabled = (array) \apply_filters('Luma\ProductFields\TaxonomyIndex\enabled_taxonomies', $enabled);

        return \in_array($taxonomy, \array_map('strval', $enabled), true);
    }


    /**
     * Check that the taxonomy exists and is viewable.
     *
     * @param string $taxonomy
     * @return bool
     */
    protected function is_linkable_taxonomy(string $taxonomy): bool
    {
        if (! \taxonomy_exists($taxonomy) || ! \is_taxonomy_viewable($taxonomy)) {
            return false;
        }

        /**
         * Final escape hatch: allow disabling linkability per taxonomy.
         *
         * @param bool   $is_linkable
         * @param string $taxonomy
         */
        return (bool) \apply_filters('Luma\ProductFields\TaxonomyIndex\is_linkable_taxonomy', true, $taxonomy);
    }


    /**
     * Ensure exactly one taxonomy index crumb exists, linked correctly, right before the term crumb.
     *
     * Steps:
     * - Compute correct index URL from taxonomy rewrite slug (fallback to taxonomy name).
     * - Choose a display label (singular by default; filterable).
     * - Remove any existing taxonomy label crumbs that match by label (plural/singular) OR by URL.
     * - Insert our canonical crumb just before the final term crumb.
     *
     * @param array<int, array{0:string,1?:string}> $crumbs
     * @param string                                $taxonomy
     * @return array<int, array{0:string,1?:string}>
     */
    protected function ensure_index_crumb_for_term_view(array $crumbs, string $taxonomy): array
    {
        $labels = $this->get_tax_labels($taxonomy); // [ 'plural' => ..., 'singular' => ... ]
        $url    = $this->get_tax_index_url($taxonomy);

        // Preferred display label in breadcrumb (defaults to singular, then plural, then ucfirst slug).
        $display_label = (string) \apply_filters(
            'Luma\ProductFields\TaxonomyIndex\breadcrumb_label',
            $labels['singular'] ?: ($labels['plural'] ?: \ucfirst($taxonomy)),
            $taxonomy,
            $labels
        );

        // Normalize URL for strict comparisons.
        $url_norm = $this->normalize_url($url);

        // De-duplicate: remove any crumb matching our label(s) or URL.
        $label_candidates = \array_values(\array_filter([
            $labels['plural']   ?? '',
            $labels['singular'] ?? '',
        ], static fn($v) => '' !== (string) $v));

        foreach ($crumbs as $i => $c) {
            $label = isset($c[0]) ? (string) $c[0] : '';
            $href  = isset($c[1]) ? (string) $c[1] : '';

            if (($href && $this->normalize_url($href) === $url_norm) || ($label && \in_array($label, $label_candidates, true))) {
                \array_splice($crumbs, (int) $i, 1);
                // Adjust index due to removal; continue scanning remaining.
                $i--;
            }
        }

        // Insert canonical crumb right before final term crumb.
        $insert_pos = \max(0, \count($crumbs) - 1);
        \array_splice($crumbs, $insert_pos, 0, [ [ $display_label, $url ] ]);

        return $crumbs;
    }


    /**
     * Get both plural and singular labels for a taxonomy.
     *
     * @param string $taxonomy
     * @return array{plural:string, singular:string}
     */
    protected function get_tax_labels(string $taxonomy): array
    {
        $tax = \get_taxonomy($taxonomy);

        $plural   = '';
        $singular = '';

        if ($tax && isset($tax->labels)) {
            $plural   = isset($tax->labels->name) ? (string) $tax->labels->name : '';
            $singular = isset($tax->labels->singular_name) ? (string) $tax->labels->singular_name : '';
        }

        return [
            'plural'   => $plural,
            'singular' => $singular,
        ];
    }


    /**
     * Build the taxonomy index URL using rewrite slug if available (fallback to taxonomy name).
     *
     * @param string $taxonomy
     * @return string
     */
    protected function get_tax_index_url(string $taxonomy): string
    {
        $tax = \get_taxonomy($taxonomy);

        $slug = $taxonomy;
        if ($tax && ! empty($tax->rewrite) && is_array($tax->rewrite) && ! empty($tax->rewrite['slug'])) {
            $slug = (string) $tax->rewrite['slug'];
        }

        $url = \home_url(\trailingslashit($slug));

        /**
         * Filter the URL used for the taxonomy index crumb.
         *
         * @param string $url
         * @param string $taxonomy
         */
        return (string) \apply_filters('Luma\ProductFields\TaxonomyIndex\breadcrumb_url', $url, $taxonomy);
    }


    /**
     * Normalize a URL for strict comparisons: lowercase scheme/host and enforce trailing slash.
     *
     * @param string $url
     * @return string
     */
    protected function normalize_url(string $url): string
    {
        $url = (string) $url;

        // Ensure trailing slash for path-only comparisons.
        if (! str_ends_with($url, '/')) {
            $url .= '/';
        }

        // Attempt light normalization of scheme/host (avoid heavy parsing).
        $parts = \wp_parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? \strtolower($parts['scheme']) : '';
        $host   = isset($parts['host'])   ? \strtolower($parts['host'])   : '';
        $path   = $parts['path'] ?? '/';

        $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $query  = isset($parts['query']) ? '?' . $parts['query'] : '';
        $frag   = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($scheme && $host) {
            return "{$scheme}://{$host}{$port}{$path}{$query}{$frag}";
        }

        // Relative URL fallback (home_url should have produced absolute; keep as is).
        return $url;
    }
}
