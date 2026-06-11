<?php
/**
 * Variation numeric aggregate manager.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Product;

use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Utils\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains parent-level min/max meta for variation numeric fields.
 */
class VariationNumericAggregates {

	/**
	 * Prefix used for parent aggregate meta keys.
	 */
	public const AGG_PREFIX = FieldStorage::META_PREFIX . 'agg_';

	/**
	 * Option that marks one-time initialization state.
	 */
	public const INITIALIZED_OPTION = 'luma_product_fields_variation_aggregates_initialized';

	/**
	 * Event used for one-time activation rebuild.
	 */
	public const REBUILD_EVENT = 'luma_product_fields_rebuild_variation_aggregates';

	/**
	 * Parent map used across deletion hooks.
	 *
	 * @var array<int,int>
	 */
	protected static array $delete_parent_map = [];

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_action( 'woocommerce_save_product_variation', [ self::class, 'handle_variation_saved' ], 20, 2 );
		\add_action( 'woocommerce_update_product', [ self::class, 'handle_product_updated' ], 20 );

		\add_action( 'before_delete_post', [ self::class, 'capture_variation_parent_before_delete' ] );
		\add_action( 'deleted_post', [ self::class, 'handle_variation_deleted' ] );
		\add_action( 'trashed_post', [ self::class, 'handle_variation_trashed_or_restored' ] );
		\add_action( 'untrashed_post', [ self::class, 'handle_variation_trashed_or_restored' ] );

		\add_filter( 'woocommerce_debug_tools', [ self::class, 'register_debug_tool' ] );
		\add_action( self::REBUILD_EVENT, [ self::class, 'rebuild_all_parent_aggregates' ] );
	}

	/**
	 * Schedule one-time initialization after activation.
	 *
	 * @return void
	 */
	public static function maybe_schedule_initial_rebuild(): void {
		if ( 'yes' === \get_option( self::INITIALIZED_OPTION, 'no' ) ) {
			return;
		}

		if ( ! \wp_next_scheduled( self::REBUILD_EVENT ) ) {
			\wp_schedule_single_event( \time() + \MINUTE_IN_SECONDS, self::REBUILD_EVENT );
		}
	}

	/**
	 * Rebuild parent aggregates after variation save.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return void
	 */
	public static function handle_variation_saved( int $variation_id ): void {
		$parent_id = (int) \wp_get_post_parent_id( $variation_id );
		if ( $parent_id > 0 ) {
			self::rebuild_for_parent( $parent_id );
		}
	}

	/**
	 * Rebuild parent aggregates when parent product is updated.
	 *
	 * @param int $product_id Product post ID.
	 * @return void
	 */
	public static function handle_product_updated( int $product_id ): void {
		$product = \wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		self::rebuild_for_parent( $product_id );
	}

	/**
	 * Capture variation parent before delete so we can rebuild after deletion.
	 *
	 * @param int $post_id Post ID being deleted.
	 * @return void
	 */
	public static function capture_variation_parent_before_delete( int $post_id ): void {
		if ( 'product_variation' !== \get_post_type( $post_id ) ) {
			return;
		}

		$parent_id = (int) \wp_get_post_parent_id( $post_id );
		if ( $parent_id > 0 ) {
			self::$delete_parent_map[ $post_id ] = $parent_id;
		}
	}

	/**
	 * Rebuild aggregates when a variation is fully deleted.
	 *
	 * @param int $post_id Deleted post ID.
	 * @return void
	 */
	public static function handle_variation_deleted( int $post_id ): void {
		if ( ! isset( self::$delete_parent_map[ $post_id ] ) ) {
			return;
		}

		$parent_id = (int) self::$delete_parent_map[ $post_id ];
		unset( self::$delete_parent_map[ $post_id ] );

		if ( $parent_id > 0 ) {
			self::rebuild_for_parent( $parent_id );
		}
	}

	/**
	 * Rebuild when a variation is trashed/restored.
	 *
	 * @param int $post_id Variation post ID.
	 * @return void
	 */
	public static function handle_variation_trashed_or_restored( int $post_id ): void {
		if ( 'product_variation' !== \get_post_type( $post_id ) ) {
			return;
		}

		$parent_id = (int) \wp_get_post_parent_id( $post_id );
		if ( $parent_id > 0 ) {
			self::rebuild_for_parent( $parent_id );
		}
	}

	/**
	 * Register a WooCommerce status tool for aggregate rebuild.
	 *
	 * @param array $tools Existing tools.
	 * @return array
	 */
	public static function register_debug_tool( array $tools ): array {
		$tools['luma_product_fields_rebuild_variation_aggregates'] = [
			'name'     => \__( 'Luma Product Fields: Rebuild variation numeric aggregates', 'luma-product-fields' ),
			'button'   => \__( 'Rebuild aggregates', 'luma-product-fields' ),
			'desc'     => \__( 'Rebuild parent product min/max aggregate meta from variation numeric fields (number, integer, and min/max).', 'luma-product-fields' ),
			'callback' => [ self::class, 'run_debug_tool' ],
		];

		return $tools;
	}

	/**
	 * Execute the Woo status tool callback.
	 *
	 * @return string
	 */
	public static function run_debug_tool(): string {
		$count = self::rebuild_all_parent_aggregates();

		/* translators: %d: Number of variable products rebuilt. */
		return \sprintf( \__( 'Rebuilt variation numeric aggregates for %d variable products.', 'luma-product-fields' ), (int) $count );
	}

	/**
	 * Rebuild aggregates for all variable products.
	 *
	 * @return int Number of products rebuilt.
	 */
	public static function rebuild_all_parent_aggregates(): int {
		$total = 0;
		$page  = 1;

		do {
			$query = new \WP_Query(
				[
					'post_type'      => 'product',
					'post_status'    => [ 'publish', 'private', 'draft', 'pending', 'future' ],
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'paged'          => $page,
					'tax_query'      => [
						[
							'taxonomy' => 'product_type',
							'field'    => 'slug',
							'terms'    => [ 'variable' ],
						],
					],
				]
			);

			$product_ids = \array_map( 'intval', (array) $query->posts );
			foreach ( $product_ids as $product_id ) {
				self::rebuild_for_parent( $product_id );
				$total++;
			}

			$page++;
		} while ( ! empty( $product_ids ) );

		if ( 'yes' !== \get_option( self::INITIALIZED_OPTION, 'no' ) ) {
			\update_option( self::INITIALIZED_OPTION, 'yes', false );
		}

		return $total;
	}

	/**
	 * Rebuild min/max aggregate meta for one parent variable product.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return void
	 */
	public static function rebuild_for_parent( int $parent_id ): void {
		if ( $parent_id <= 0 ) {
			return;
		}

		self::clear_all_parent_aggregate_meta( $parent_id );

		$group_slug = Helpers::get_product_group_slug( $parent_id ) ?: 'general';
		$fields     = Helpers::get_fields_for_group( $group_slug );
		if ( empty( $fields ) ) {
			return;
		}

		$eligible_fields = self::get_eligible_variation_fields( (array) $fields );
		if ( empty( $eligible_fields ) ) {
			return;
		}

		$variation_ids = self::get_variation_ids( $parent_id );
		foreach ( $eligible_fields as $field ) {
			$slug = (string) ( $field['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$stats = self::calculate_field_stats_for_variations( $variation_ids, $field );
			self::store_field_stats( $parent_id, $slug, $stats );
		}
	}

	/**
	 * Return variation IDs for one parent product.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return int[]
	 */
	protected static function get_variation_ids( int $parent_id ): array {
		return \get_posts(
			[
				'post_type'      => 'product_variation',
				'post_status'    => [ 'publish', 'private' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_parent'    => $parent_id,
			]
		);
	}

	/**
	 * Filter to variation-enabled numeric fields we can aggregate.
	 *
	 * @param array $fields Group fields.
	 * @return array<int,array>
	 */
	protected static function get_eligible_variation_fields( array $fields ): array {
		$allowed_types = [ 'number', 'integer', 'minmax' ];
		$result        = [];

		foreach ( $fields as $field ) {
			$type = (string) ( $field['type'] ?? '' );

			if ( '' === $type || ! \in_array( $type, $allowed_types, true ) ) {
				continue;
			}

			if ( empty( $field['variation'] ) || ! FieldTypeRegistry::supports( $type, 'variations' ) ) {
				continue;
			}

			$result[] = (array) $field;
		}

		return $result;
	}

	/**
	 * Calculate min/max for one field across child variations.
	 *
	 * @param int[] $variation_ids Variation IDs.
	 * @param array $field         Field definition.
	 * @return array{min:float|null,max:float|null}
	 */
	protected static function calculate_field_stats_for_variations( array $variation_ids, array $field ): array {
		$min = null;
		$max = null;
		$key = FieldStorage::META_PREFIX . (string) $field['slug'];

		foreach ( $variation_ids as $variation_id ) {
			$raw = \get_post_meta( (int) $variation_id, $key, true );
			foreach ( self::extract_numeric_values( $raw, (string) $field['type'] ) as $value ) {
				$min = ( null === $min ) ? $value : \min( $min, $value );
				$max = ( null === $max ) ? $value : \max( $max, $value );
			}
		}

		return [
			'min' => $min,
			'max' => $max,
		];
	}

	/**
	 * Extract normalized numeric candidates from stored field values.
	 *
	 * @param mixed  $raw  Stored field value.
	 * @param string $type Field type.
	 * @return float[]
	 */
	protected static function extract_numeric_values( $raw, string $type ): array {
		if ( 'minmax' === $type && \is_array( $raw ) ) {
			$values = [];
			if ( isset( $raw['min'] ) && \is_numeric( $raw['min'] ) ) {
				$values[] = (float) $raw['min'];
			}
			if ( isset( $raw['max'] ) && \is_numeric( $raw['max'] ) ) {
				$values[] = (float) $raw['max'];
			}

			return $values;
		}

		if ( \is_numeric( $raw ) ) {
			return [ (float) $raw ];
		}

		return [];
	}

	/**
	 * Persist min/max aggregate meta for one field, or clear when empty.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param string $slug     Field slug.
	 * @param array{min:float|null,max:float|null} $stats Calculated stats.
	 * @return void
	 */
	protected static function store_field_stats( int $parent_id, string $slug, array $stats ): void {
		$min_key = self::get_min_meta_key( $slug );
		$max_key = self::get_max_meta_key( $slug );

		if ( null === $stats['min'] || null === $stats['max'] ) {
			\delete_post_meta( $parent_id, $min_key );
			\delete_post_meta( $parent_id, $max_key );
			return;
		}

		\update_post_meta( $parent_id, $min_key, (float) $stats['min'] );
		\update_post_meta( $parent_id, $max_key, (float) $stats['max'] );
	}

	/**
	 * Remove all aggregate meta keys for one parent product.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return void
	 */
	protected static function clear_all_parent_aggregate_meta( int $parent_id ): void {
		$meta = \get_post_meta( $parent_id );
		if ( ! \is_array( $meta ) || empty( $meta ) ) {
			return;
		}

		foreach ( \array_keys( $meta ) as $meta_key ) {
			if ( ! \is_string( $meta_key ) ) {
				continue;
			}

			if ( 0 !== \strpos( $meta_key, self::AGG_PREFIX ) ) {
				continue;
			}

			\delete_post_meta( $parent_id, $meta_key );
		}
	}

	/**
	 * Build parent aggregate min key for a field.
	 *
	 * @param string $slug Field slug.
	 * @return string
	 */
	public static function get_min_meta_key( string $slug ): string {
		return self::AGG_PREFIX . \sanitize_key( $slug ) . '_min';
	}

	/**
	 * Build parent aggregate max key for a field.
	 *
	 * @param string $slug Field slug.
	 * @return string
	 */
	public static function get_max_meta_key( string $slug ): string {
		return self::AGG_PREFIX . \sanitize_key( $slug ) . '_max';
	}
}
