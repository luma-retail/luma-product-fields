<?php
namespace Luma\ProductFields\Frontend;

defined('ABSPATH') || exit;

/**
 * Template loader for taxonomy index views (e.g., /designer/).
 *
 * @since 3.0.0
 */
class TaxonomyIndexTemplate
{
    /** @var string Default relative template name used for the index (theme overrideable). */
    protected string $default_template_name = 'lpf-taxonomy-index.php';

    
    /**
     * Register hooks to load the taxonomy index template and add head tags.
     *
     * @return void
     */
    public function register_hooks(): void {
        add_filter( 'template_include', [ $this, 'maybe_load_template' ], 99 );
        add_action( 'wp_head', [ $this, 'maybe_output_canonical' ], 1 );
        add_filter( 'body_class', [ $this, 'add_body_class' ] );
        add_filter( 'document_title_parts', [ $this, 'filter_document_title_parts' ] );
        add_filter( 'document_title_separator', [ $this, 'filter_document_title_separator' ] );
        if ( class_exists( 'WPSEO_Frontend' ) ) {
            add_filter( 'wpseo_title', [ $this, 'filter_wpseo_title' ], 20 );
        }
    }

    /**
     * If current request is a taxonomy index view, return our template path.
     *
     * @param string $template
     * @return string
     */
    public function maybe_load_template( string $template ): string {
                
        if ( ! $this->is_tax_index_view() ) {
            return $template;
        }
            
        $template_name = $this->get_template_name();
        $located       = $this->locate_template( $template_name );
        
        return $located ?: $template;
    }
    
    
    /**
     * Add WooCommerce body class.
     *
     * @param string[] $classes
     * @return string[]
     */
    public function add_body_class( array $classes ): array {
        if ( $this->is_tax_index_view() ) {
            $classes[] = 'woocommerce';
        }
        return $classes;
    }





    /**
     * Output a canonical link tag for taxonomy index pages.
     *
     * @return void
     */
    public function maybe_output_canonical(): void {
        if ( ! $this->is_tax_index_view() ) {
            return;
        }

        $slug      = get_query_var( 'lpf_tax' );
        $canonical = home_url( trailingslashit( $slug ) );

        echo '<link rel="canonical" href="' . esc_url( $canonical ) . "\" />\n";
    }





    /**
     * Normalize the document title parts on taxonomy index pages (standard WP).
     *
     * Produces: "Taxonomy Label - Site Name" (omits tagline).
     *
     * @param array<string,string> $parts
     * @return array<string,string>
     *
     * @hook document_title_parts
     */
    public function filter_document_title_parts( array $parts ): array {
        if ( ! $this->is_tax_index_view() ) {
            return $parts;
        }

        $taxonomy = $this->get_current_taxonomy();
        $label    = $this->get_current_taxonomy_label( $taxonomy );
        $site     = get_bloginfo( 'name' );

        return [
            'title' => $label ?: ( $parts['title'] ?? '' ),
            'site'  => $site,
            // Intentionally omit 'tagline' to avoid “title + tagline”
        ];
    }



    /**
     * Force a dash separator on taxonomy index pages.
     *
     * @param string $sep
     * @return string
     *
     * @hook document_title_separator
     */
    public function filter_document_title_separator( string $sep ): string {
        return $this->is_tax_index_view() ? ' - ' : $sep;
    }



    /**
     * Yoast SEO: override the computed <title> just for taxonomy index pages.
     *
     * Only runs if Yoast is active. Mirrors the standard WP result so users see
     * the same pattern regardless of SEO plugin presence.
     *
     * @param string $title Current Yoast-computed title.
     * @return string
     *
     * @hook wpseo_title
     */
    public function filter_wpseo_title( string $title ): string {
        if ( ! $this->is_tax_index_view() ) {
            return $title;
        }

        $taxonomy = $this->get_current_taxonomy();
        $label    = $this->get_current_taxonomy_label( $taxonomy );
        $site     = get_bloginfo( 'name' );

        // Keep consistent with document_title_parts + separator override
        return $label && $site ? "{$label} - {$site}" : $title;
    }




    /**
     * Determine if current main query is our virtual taxonomy index.
     *
     * @return bool
     */
    protected function is_tax_index_view(): bool {
        return (bool) get_query_var( 'lpf_tax_index' ) && get_query_var( 'lpf_tax' );
    }



    /**
     * Resolve the template name (filterable).
     *
     * Theme path:  yourtheme/luma-product-fields/{template}.php
     * Plugin path: luma-product-fields/templates/{template}.php
     *
     * @return string
     */
    protected function get_template_name(): string {
        /**
         * Filter the filename used for the taxonomy index template.
         *
         * @param string $template_name Default: 'lpf-taxonomy-index.php'
         */
        return (string) apply_filters(
            'luma_product_fields_taxonomy_index_template_name',
            $this->default_template_name
        );
    }



    /**
     * Locate a template allowing theme overrides, falling back to plugin.
     * If WooCommerce is present, uses wc_locate_template(). Otherwise, mimic it.
     *
     * Theme path:  yourtheme/luma-product-fields/{template}.php
     * Plugin path: luma-product-fields/templates/{template}.php
     *
     * @param string $template_name
     * @return string
     */
protected function locate_template( string $template_name ): string {
    $theme_path  = 'luma-product-fields/';
    $plugin_path = trailingslashit( LUMA_PRODUCT_FIELDS_PLUGIN_DIR_PATH ) . 'templates/';

    if ( function_exists( 'wc_locate_template' ) ) {
        $found = wc_locate_template( $template_name, $theme_path, $plugin_path );
        if ( ! empty( $found ) ) {
            return $found;
        }
    }

    // Manual theme override lookup
    $theme_file  = trailingslashit( get_stylesheet_directory() ) .
                   $theme_path .
                   $template_name;

    if ( file_exists( $theme_file ) ) {
        return $theme_file;
    }

    // Plugin fallback
    $plugin_file = $plugin_path . $template_name;
    if ( file_exists( $plugin_file ) ) {
        return $plugin_file;
    }
    return '';
}

    

     /**
     * Resolve the current field-backed taxonomy slug from the query.
     *
     * Looks at ?lpf_tax={slug}. If a registered taxonomy exists with that slug, returns it.
     * Otherwise, verifies against TaxonomyManager definitions and returns the slug if it
     * matches a field-backed taxonomy; returns '' when unknown.
     *
     * @return string Taxonomy slug or empty string.
     */
    protected function get_current_taxonomy(): string {
        $slug = sanitize_title( (string) get_query_var( 'lpf_tax' ) );
        if ( ! $slug ) {
            return '';
        }

        // If WP already knows this taxonomy, we’re done.
        if ( taxonomy_exists( $slug ) ) {
            return $slug;
        }

        // Otherwise, verify the slug against our field-backed taxonomies.
        if ( class_exists( '\Luma\ProductFields\Taxonomy\TaxonomyManager' ) ) {
            foreach ( (array) \Luma\ProductFields\Taxonomy\TaxonomyManager::get_all() as $def ) {
                if ( is_array( $def ) && ( $def['slug'] ?? '' ) === $slug ) {
                    return $slug; // trust our registry
                }
            }
        }

        return '';
    }


    /**
     * Get a human-friendly label for the current taxonomy.
     *
     * Prefers the WP taxonomy label if registered; otherwise falls back to the field
     * definition label from TaxonomyManager; finally, a prettified slug.
     *
     * @param string $taxonomy Taxonomy slug (can be empty).
     * @return string
     */
    protected function get_current_taxonomy_label( string $taxonomy ): string {
        // 1) Registered taxonomy label
        if ( $taxonomy ) {
            $tax_obj = get_taxonomy( $taxonomy );
            if ( $tax_obj && ! empty( $tax_obj->label ) ) {
                return (string) $tax_obj->label;
            }
        }

        // 2) Field definition label from our registry
        if ( class_exists( '\Luma\ProductFields\Taxonomy\TaxonomyManager' ) ) {
            $slug = $taxonomy ?: sanitize_title( (string) get_query_var( 'lpf_tax' ) );
            foreach ( (array) \Luma\ProductFields\Taxonomy\TaxonomyManager::get_all() as $def ) {
                if ( is_array( $def ) && ( $def['slug'] ?? '' ) === $slug ) {
                    $label = (string) ( $def['label'] ?? '' );
                    if ( $label !== '' ) {
                        return $label;
                    }
                }
            }
        }

        // 3) Fallback: prettify slug or query var
        $fallback = $taxonomy ?: (string) get_query_var( 'lpf_tax' );
        return $fallback ? ucwords( str_replace( [ '-', '_' ], ' ', $fallback ) ) : '';
    }



}
