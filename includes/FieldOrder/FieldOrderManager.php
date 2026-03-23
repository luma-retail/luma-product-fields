<?php
/**
 * Field ordering manager.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\FieldOrder;

defined( 'ABSPATH' ) || exit;

use Luma\ProductFields\Admin\FieldOptionsOverview;
use Luma\ProductFields\Utils\CacheInvalidator;

/**
 * Handles built-in field ordering across admin and field retrieval.
 */
class FieldOrderManager {

	/**
	 * Option name prefix used for saved field order.
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'luma_product_fields_field_order_';


	/**
	 * Register field-order hooks.
	 */
	public function __construct() {
		add_filter( 'luma_product_fields_get_all_fields', [ self::class, 'apply_saved_field_order' ], 10, 2 );

		if ( is_admin() ) {
			add_action( 'luma_product_fields_field_options_overview_table_head_start', [ $this, 'insert_into_table_header' ], 10, 0 );
			add_action( 'luma_product_fields_field_options_overview_table_row_start', [ $this, 'insert_drag_handle' ], 10, 0 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ], 10, 1 );
		}
	}


	/**
	 * Apply a persisted field order for a product group.
	 *
	 * @param array<int,array<string,mixed>> $fields Field definitions.
	 * @param string|null                    $group  Product group slug, or null for all fields.
	 * @return array<int,array<string,mixed>>
	 */
	public static function apply_saved_field_order( array $fields, $group = null ): array {
		$order = self::get_saved_order( $group );
		if ( empty( $order ) ) {
			return $fields;
		}

		$by_slug = [];
		foreach ( $fields as $field ) {
			$slug = sanitize_key( (string) ( $field['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}

			$by_slug[ $slug ] = $field;
		}

		$sorted = [];
		foreach ( $order as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$sorted[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}

		foreach ( $by_slug as $field ) {
			$sorted[] = $field;
		}

		return $sorted;
	}


	/**
	 * Persist an ordered list of field slugs for a group.
	 *
	 * @param string       $group Product group slug.
	 * @param array<int,string> $order Ordered field slugs.
	 * @return void
	 */
	public static function save_order( string $group, array $order ): void {
		$group = self::normalize_group( $group );
		$order = array_values( array_filter( array_map( 'sanitize_key', $order ) ) );

		update_option( self::get_option_name( $group ), $order );

		$cache_version = (int) get_option( 'luma_product_fields_meta_cache_version', 1 );
		update_option( 'luma_product_fields_meta_cache_version', $cache_version + 1 );
		CacheInvalidator::invalidate_all_meta_caches();
	}


	/**
	 * Render the drag-handle table header cell.
	 *
	 * @return void
	 */
	public function insert_into_table_header(): void {
		echo '<th class="lumaprfi-field-order-column"></th>';
	}


	/**
	 * Render the drag handle cell.
	 *
	 * @return void
	 */
	public function insert_drag_handle(): void {
		echo '<td class="lumaprfi-drag-handle" style="cursor:move;" aria-label="' . esc_attr__( 'Drag to reorder field', 'luma-product-fields' ) . '" title="' . esc_attr__( 'Drag to reorder field', 'luma-product-fields' ) . '">&#9776;</td>';
	}


	/**
	 * Enqueue sortable JS on the field overview page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$matches_screen = $screen && isset( $screen->id ) && $screen->id === FieldOptionsOverview::SCREEN_ID;
		$matches_hook   = $hook_suffix === FieldOptionsOverview::SCREEN_ID;

		if ( ! $matches_screen && ! $matches_hook ) {
			return;
		}

		wp_enqueue_script(
			'luma-product-fields-field-order',
			LUMA_PRODUCT_FIELDS_PLUGIN_URL . 'js/admin/field-order.js',
			[ 'jquery', 'jquery-ui-sortable', 'luma-product-fields-admin-js' ],
			LUMA_PRODUCT_FIELDS_PLUGIN_VER,
			true
		);
	}


	/**
	 * Get the saved order for a group, with fallback to the all-groups order.
	 *
	 * @param string|null $group Product group slug or null.
	 * @return array<int,string>
	 */
	private static function get_saved_order( $group ): array {
		$group = self::normalize_group( $group );
		$order = get_option( self::get_option_name( $group ), [] );

		if ( ( ! is_array( $order ) || empty( $order ) ) && 'all' !== $group ) {
			$order = get_option( self::get_option_name( 'all' ), [] );
		}

		if ( ! is_array( $order ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'sanitize_key', $order ) ) );
	}


	/**
	 * Normalize a group slug for storage and retrieval.
	 *
	 * @param string|null $group Product group slug.
	 * @return string
	 */
	private static function normalize_group( $group ): string {
		if ( empty( $group ) || ! is_string( $group ) ) {
			return 'all';
		}

		$group = sanitize_key( $group );

		return '' === $group ? 'all' : $group;
	}


	/**
	 * Build the option name for a group.
	 *
	 * @param string $group Normalized product group slug.
	 * @return string
	 */
	private static function get_option_name( string $group ): string {
		return self::OPTION_PREFIX . $group;
	}
}