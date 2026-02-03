<?php
/**
 * Taxonomy archive controller
 *
 * Forces WooCommerce-style product archive rendering for public, linkable
 * Luma Product Fields dynamic taxonomies (and LPF Product Groups).
 *
 * This is primarily for block themes (FSE) where taxonomy archives otherwise
 * fall back to the theme's generic post loop.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Frontend;

defined( 'ABSPATH' ) || exit;

use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;

class TaxonomyArchiveController {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', [ $this, 'maybe_adjust_tax_archive_query' ], 20 );
		add_filter( 'template_include', [ $this, 'maybe_use_woocommerce_archive_template' ], 20 );
		add_filter( 'body_class', [ $this, 'maybe_add_woocommerce_archive_body_classes' ] );
	}


	/**
	 * Ensure WooCommerce-like body classes on LPF taxonomy archives.
	 *
	 * WooCommerce adds important body classes based on its conditionals
	 * (product_cat/product_tag/pa_*). Our dynamic field taxonomies are not
	 * recognized by those helpers, so themes like Storefront may miss
	 * Woo-specific archive styling.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public function maybe_add_woocommerce_archive_body_classes( array $classes ): array {
		if ( is_admin() || ! function_exists( 'is_tax' ) || ! is_tax() ) {
			return $classes;
		}

		$taxonomy = $this->get_current_taxonomy();
		if ( '' === $taxonomy ) {
			return $classes;
		}

		if ( ! $this->should_handle_taxonomy_archive( $taxonomy ) ) {
			return $classes;
		}

		$classes[] = 'woocommerce';
		$classes[] = 'woocommerce-page';
		$classes[] = 'woocommerce-shop';
		$classes[] = 'archive';
		$classes[] = 'post-type-archive-product';
		$classes[] = 'lumaprfi-taxonomy-archive';

		return array_values( array_unique( $classes ) );
	}


	/**
	 * Ensure the main query on supported taxonomy archives is a WooCommerce product query.
	 *
	 * @param \WP_Query $query
	 * @return void
	 */
	public function maybe_adjust_tax_archive_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$taxonomy = $this->get_current_taxonomy();
		if ( '' === $taxonomy ) {
			return;
		}

		if ( ! $this->should_handle_taxonomy_archive( $taxonomy ) ) {
			return;
		}

		// Ensure product post type.
		$query->set( 'post_type', 'product' );

		// Apply WooCommerce catalog visibility/order handling when available.
		if ( function_exists( 'WC' ) && WC() instanceof \WooCommerce && WC()->query ) {
			WC()->query->product_query( $query );
		}
	}


	/**
	 * Force a WooCommerce-style archive template for supported taxonomy archives.
	 *
	 * @param string $template
	 * @return string
	 */
	public function maybe_use_woocommerce_archive_template( string $template ): string {
		if ( is_admin() || ! function_exists( 'is_tax' ) || ! is_tax() ) {
			return $template;
		}

		if ( ! function_exists( 'WC' ) || ! ( WC() instanceof \WooCommerce ) ) {
			return $template;
		}

		$taxonomy = $this->get_current_taxonomy();
		if ( '' === $taxonomy ) {
			return $template;
		}

		if ( ! $this->should_handle_taxonomy_archive( $taxonomy ) ) {
			return $template;
		}

		$plugin_template = trailingslashit( LUMA_PRODUCT_FIELDS_PLUGIN_DIR_PATH ) . 'templates/taxonomy-product-archive.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}


	/**
	 * Determine if we should override the template for a taxonomy archive.
	 *
	 * @param string $taxonomy
	 * @return bool
	 */
	protected function should_handle_taxonomy_archive( string $taxonomy ): bool {
		// Product Groups should behave like a product archive too.
		if ( ProductGroup::$tax_name === $taxonomy ) {
			return true;
		}

		// Dynamic LPF field taxonomies (created when a taxonomy-backed field has "show links").
		$field = Helpers::get_field_definition_by_slug( $taxonomy );
		if ( ! is_array( $field ) ) {
			return false;
		}

		if ( empty( $field['show_links'] ) ) {
			return false;
		}

		$type = (string) ( $field['type'] ?? '' );
		if ( '' === $type ) {
			return false;
		}

		// Must be a taxonomy-backed type that supports link routing.
		if ( FieldTypeRegistry::get_field_storage_type( $type ) !== 'taxonomy' ) {
			return false;
		}

		return FieldTypeRegistry::supports( $type, 'link' );
	}


	/**
	 * Get current queried taxonomy slug.
	 *
	 * @return string
	 */
	protected function get_current_taxonomy(): string {
		$term = get_queried_object();
		$taxonomy = is_object( $term ) && isset( $term->taxonomy ) ? (string) $term->taxonomy : '';
		return sanitize_key( $taxonomy );
	}
}
