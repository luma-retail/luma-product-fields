<?php
/**
 * Taxonomy manager class
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Taxonomy;

defined('ABSPATH') || exit;

use Luma\ProductFields\Product\FieldStorage;
use WP_Taxonomy;

/**
 * Taxonomy Manager class
 *
 * Registers dynamic field taxonomies for field types that are taxonomies.
 *
 * @hook luma_product_fields_taxonomy_registered
 *       Fires after custom taxonomy is registered.
 *       For 3rd parties to customize taxonomies.
 *       @param string $slug Taxonomy name.
 *       @param array  $args Args for register_taxonomy.
 */
class TaxonomyManager
{

    protected const OPTION_KEY = 'lpf_dynamic_taxonomies';

    /** @var string[] Slugs for dynamic taxonomies that are public (links enabled). */
    protected array $linkable_taxonomy_slugs = [];


    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('init', [$this, 'register_dynamic_taxonomies']);
    }


    /**
     * Get stored dynamic taxonomy field definitions.
     *
     * If $group is provided, only items whose 'groups' array contains that slug are returned.
     *
     * Each item has (at least) these keys:
     * - label            string
     * - description      string
     * - frontend_desc    string (may contain sanitized HTML)
     * - slug             string (taxonomy name)
     * - type             string
     * - unit             string
     * - groups           string[] (product group slugs)
     * - hide_in_frontend bool
     * - variation        bool
     * - is_taxonomy      bool
     * - show_links       bool
     * - schema_prop      string
     * - tax_description  string (optional)
     *
     * @param string|null $group Product group slug to filter by, or null for all.
     * @return array<int, array{
     *     label: string,
     *     description: string,
     *     frontend_desc: string,
     *     slug: string,
     *     type: string,
     *     unit: string,
     *     groups: array<int,string>,
     *     hide_in_frontend: bool,
     *     variation: bool,
     *     is_taxonomy: bool,
     *     show_links: bool,
     *     schema_prop: string,
     *     tax_description?: string
     * }>
     */
    public function get_dynamic_taxonomies($group = null): array
    {
        $fields = get_option(self::OPTION_KEY, []);

        if (! is_array($fields)) {
            return [];
        }

        if (empty($group)) {
            return $fields;
        }

        $filtered_fields = [];

        foreach ($fields as $field) {
            if (! empty($field['groups']) && in_array($group, (array) $field['groups'], true)) {
                $filtered_fields[] = $field;
            }
        }

        return $filtered_fields;
    }


    /**
     * Static: Get stored dynamic taxonomy field definitions.
     *
     * Proxy to get_dynamic_taxonomies(); see that method for the array shape.
     *
     * @param string|null $group Product group slug to filter by, or null for all.
     * @return array<int, array{
     *     label: string,
     *     description: string,
     *     frontend_desc: string,
     *     slug: string,
     *     type: string,
     *     unit: string,
     *     groups: array<int,string>,
     *     hide_in_frontend: bool,
     *     variation: bool,
     *     is_taxonomy: bool,
     *     show_links: bool,
     *     schema_prop: string,
     *     tax_description?: string
     * }>
     */
    public static function get_all($group = null): array
    {
        return (new self())->get_dynamic_taxonomies($group);
    }


    /**
     * Register each dynamic taxonomy.
     *
     * If the field has "show links" enabled, taxonomy is public with archives.
     *
     * @return void
     */
    public function register_dynamic_taxonomies(): void
    {
        foreach ($this->get_dynamic_taxonomies() as $field) {
            $slug = $field['slug'] ?? '';

            if ($slug === '') {
                continue;
            }

            $show_links = ! empty($field['show_links'])
                && \Luma\ProductFields\Registry\FieldTypeRegistry::supports($field['type'] ?? '', 'link');

            $args = [
                'label'              => $field['label'] ?? $slug,
                'hierarchical'       => false,
                'public'             => $show_links,
                'publicly_queryable' => $show_links,
                'show_in_rest'       => false,
                'show_ui'            => true,
                'show_in_menu'       => false,
                'show_admin_column'  => false,
                'query_var'          => $show_links,
                'meta_box_cb'        => false,
                'rewrite'            => $show_links ? [
                    'slug'         => $slug,
                    'with_front'   => false,
                    'hierarchical' => false,
                ] : false,
            ];

            register_taxonomy($slug, 'product', $args);

            if ($show_links) {
                $this->linkable_taxonomy_slugs[] = $slug;
            }

            /**
             * For 3rd parties to customize taxonomies.
             *
             * @since 3.1.0
             *
             * @param string $slug Taxonomy name.
             * @param array  $args Args used for register_taxonomy().
             */
            do_action('luma_product_fields_taxonomy_registered', $slug, $args);
        }
    }


    /**
     * Check if a taxonomy is of type 'single'.
     *
     * @param string $slug Taxonomy slug.
     * @return bool
     */
    public function is_single(string $slug): bool
    {
        foreach ($this->get_dynamic_taxonomies() as $tax) {
            if (($tax['slug'] ?? '') === $slug) {
                return ($tax['type'] ?? '') === 'single';
            }
        }

        return false;
    }


    /**
     * Get label for a dynamic taxonomy.
     *
     * @param string $slug Taxonomy slug.
     * @return string
     */
    public function get_label(string $slug): string
    {
        foreach ($this->get_dynamic_taxonomies() as $tax) {
            if (($tax['slug'] ?? '') === $slug) {
                return $tax['label'] ?? $slug;
            }
        }

        return $slug;
    }


    /**
     * Get a dynamic taxonomy field by slug.
     *
     * @param string $slug Taxonomy slug.
     * @return array|null
     */
    public static function get_field(string $slug): ?array
    {
        foreach (self::get_all() as $field) {
            if (($field['slug'] ?? null) === $slug) {
                return $field;
            }
        }

        return null;
    }


    /**
     * Get slugs for dynamic taxonomies that are public (links enabled).
     *
     * @return string[]
     */
    public function get_linkable_taxonomy_slugs(): array
    {
        return $this->linkable_taxonomy_slugs;
    }


    /**
     * Save or update a dynamic taxonomy field.
     *
     * Never creates a field whose taxonomy slug already exists in WordPress.
     *
     * @param array $field_data Field data from admin UI.
     * @return void
     */
    public static function save_field(array $field_data): void
    {
        $taxonomies = self::get_all();
        $updated    = false;
        $incoming_slug = $field_data['slug'] ?? '';

        foreach ($taxonomies as &$tax) {
            if (($tax['slug'] ?? '') === $incoming_slug && $incoming_slug !== '') {
                // Existing field: update data, keep slug as-is.
                $tax                = array_merge($tax, $field_data);
                $tax['is_taxonomy'] = true;
                $updated            = true;
                break;
            }
        }

        if (! $updated) {
            // New field.
            if (empty($field_data['slug'])) {
                $field_data['slug'] = self::generate_unique_slug($field_data['label'] ?? '');
            } else {
                $field_data['slug'] = self::normalize_slug($field_data['slug']);
            }

            $slug = $field_data['slug'];

            // Do NOT create field if taxonomy already exists globally.
            if (self::taxonomy_slug_in_use($slug)) {
                /**
                 * Fires when a dynamic taxonomy field attempted to use
                 * an existing taxonomy slug.
                 *
                 * @param array  $field_data Original field data.
                 * @param string $slug       Normalized slug that is already in use.
                 */
                do_action('luma_product_fields_dynamic_taxonomy_exists', $field_data, $slug);

                return;
            }

            $field_data['is_taxonomy'] = true;
            $taxonomies[]              = $field_data;
        }

        update_option(self::OPTION_KEY, $taxonomies);
        update_option('luma_product_fields_flush_rewrite', 1);
    }


    /**
     * Delete a taxonomy field.
     *
     * Deletes only the field definition by default. When $full_cleanup is true,
     * associated terms and product-level assignments are also removed.
     *
     * @todo Return an OperationResult instead of void for better notifications.
     *
     * @param string $slug         Taxonomy slug.
     * @param bool   $full_cleanup Whether to also remove terms and assignments.
     * @return void
     */
    public static function delete_field(string $slug, bool $full_cleanup = false): void
    {
        if ($slug === '') {
            return;
        }

        if ($full_cleanup) {
            $batch_size = 500;
            $page       = 1;

            while (true) {
                $product_ids = wc_get_products([
                    'limit'  => $batch_size,
                    'page'   => $page,
                    'return' => 'ids',
                ]);

                if (empty($product_ids)) {
                    break;
                }

                foreach ($product_ids as $product_id) {
                    FieldStorage::delete_field($slug, $product_id);
                }

                ++$page;
            }

            $terms = get_terms([
                'taxonomy'   => $slug,
                'hide_empty' => false,
            ]);

            if (! is_wp_error($terms)) {
                foreach ($terms as $term) {
                    wp_delete_term($term->term_id, $slug);
                }
            }
        }

        $taxonomies = get_option(self::OPTION_KEY, []);
        $taxonomies = array_filter(
            (array) $taxonomies,
            static fn($tax) => ($tax['slug'] ?? '') !== $slug
        );
        update_option(self::OPTION_KEY, array_values($taxonomies));
        update_option('luma_product_fields_flush_rewrite', 1);
    }


    /**
     * Generate a unique, human-readable slug for a taxonomy field.
     *
     * @param string $label Label to base the slug on.
     * @return string
     */
    public static function generate_unique_slug(string $label): string
    {
        return self::normalize_slug($label);
    }


    /**
     * Normalize a taxonomy slug:
     * - sanitize
     * - avoid reserved slugs by prefixing with "lpf-"
     * - ensure uniqueness across dynamic taxonomies (option only)
     *
     * This does not check global registered taxonomies; that is handled
     * separately by taxonomy_slug_in_use().
     *
     * @param string $raw_slug Raw slug or label.
     * @return string
     */
    protected static function normalize_slug(string $raw_slug): string
    {
        $slug           = sanitize_title($raw_slug);
        $existing_slugs = array_column(self::get_all(), 'slug');

        if (in_array($slug, self::get_reserved_slugs(), true)) {
            $slug = 'lpf-' . $slug;
        }

        $base   = $slug;
        $suffix = 2;

        while (in_array($slug, $existing_slugs, true)) {
            $slug = $base . '-' . $suffix;
            ++$suffix;
        }

        return $slug;
    }


    /**
     * Reserved taxonomy slugs that must not be used directly.
     *
     * @return string[]
     */
    protected static function get_reserved_slugs(): array
    {
      $reserved = [
            'page',
            'attachment',
            'embed',
            'category',
            'tag',
            'author',
            'search',
            'feed',
            'rss',
            'rss2',
            'atom',
            'comments',
            'comment-page',
            'archive',
            'archives',
            'wp-json',
            'sitemap',
            'seo',
            'api',
            'auth',
            'oauth',
            'login',
            'logout',
            'register',
            'account',
            'profile',
            'download',
            'downloads',
            'cart',
            'checkout',
            'shop',
            'store',
            'blog',
        ];

        if ( function_exists( 'WC' ) && WC() instanceof \WooCommerce ) {
            if ( WC()->query ) {
                $endpoints = WC()->query->get_query_vars(); 
                $reserved  = array_merge( $reserved, array_keys( (array) $endpoints ) );
            }

            $page_option_keys = [
                'woocommerce_shop_page_id',
                'woocommerce_cart_page_id',
                'woocommerce_checkout_page_id',
                'woocommerce_myaccount_page_id',
            ];

            foreach ( $page_option_keys as $option_key ) {
                $page_id = (int) get_option( $option_key );
                if ( $page_id > 0 ) {
                    $slug = get_post_field( 'post_name', $page_id );
                    if ( is_string( $slug ) && $slug !== '' ) {
                        $reserved[] = $slug;
                    }
                }
            }
        }
        $reserved = array_filter( array_map( 'sanitize_title', $reserved ) );
        $reserved = array_values( array_unique( $reserved ) );

        return $reserved;
    }


    /**
     * Check if a taxonomy slug is already registered in WordPress.
     *
     * This covers both core taxonomies and any custom taxonomies
     * registered by other plugins or themes.
     *
     * @param string $slug Taxonomy slug.
     * @return bool
     */
    protected static function taxonomy_slug_in_use(string $slug): bool
    {
        return taxonomy_exists($slug);
    }
}
