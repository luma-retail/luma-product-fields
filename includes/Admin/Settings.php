<?php
/**
 * Admin settings for Luma Product Fields.
 *
 * @package Luma\ProductFields\Admin
 */

namespace Luma\ProductFields\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
  * @hook Luma\ProductFields\settings_array
 *      Filters the array of settings 
 *      @see woocommerce settings API
 *      @param array $settings 
 */
class Settings {


	/**
	 * Option ID prefix.
	 *
	 * @const string
	 */
    public const PREFIX = 'luma_product_fields_';

    
    
	/**
	 * Hook into WooCommerce settings API.
	 */
	public function __construct() {
		add_filter( 'woocommerce_get_sections_products', [ $this, 'add_settings_section' ] );
		add_filter( 'woocommerce_get_settings_products', [ $this, 'add_settings_fields' ], 10, 2 );
	}


	/**
	 * Add custom section under WooCommerce > Settings > Products.
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public function add_settings_section( $sections ) {
		$sections['luma_product_fields'] = __( 'Luma Product Fields', 'luma-product-fields' );
		return $sections;
	}


	/**
	 * Add fields for settings section.
	 *
	 * @param array  $settings Existing settings.
	 * @param string $current_section Current section ID.
	 * @return array
	 */
	public function add_settings_fields( $settings, $current_section ) {
		if ( 'luma_product_fields' !== $current_section ) {
			return $settings;
		}

		$settings = [
			[
				'title' => __( 'Luma Product Fields Settings', 'luma-product-fields' ),
                'desc' =>  __('These are the system settings for Luma Product Fields. To add or remove fields, go to Products → Product Fields', 'luma-product-fields'),
				'type'  => 'title',
				'id'    => self::PREFIX . 'settings_title',
			],
			[
				'title'    => __( 'Front End title', 'luma-product-fields' ),
				'desc'     => __( 'Title on the product fields tab.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'front_end_title',
				'type'     => 'text',
				'default'  => __('Additional information', 'woocommerce'),
				'desc_tip' => true,
			],	
			[
				'title'    => __( 'Display Product Group in Front End', 'luma-product-fields' ),
				'desc'     => __( 'Enable to show the product group name on product pages.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'display_group',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => true,
			],			
			[
				'title'    => __( 'Display SKU', 'luma-product-fields' ),
				'desc'     => __( 'Enable to show the product SKU with the fields in the front end.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'display_sku',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Display Global Unique Identifier', 'luma-product-fields' ),
				'desc'     => __( 'Enable to show GTIN/barcode (available from Woo 9.1.) in the front end.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'display_global_unique_id',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => true,
			],			
			[
				'title'    => __( 'Display tags', 'luma-product-fields' ),
				'desc'     => __( 'Enable to show the product tags with the fields in the front end.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'display_tags',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Display categories', 'luma-product-fields' ),
				'desc'     => __( 'Enable to show the product categories with the fields in the front end.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'display_categories',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => true,
			],
            [
				'title'    => __( 'Enable migration tool', 'luma-product-fields' ),
				'desc'     => __( 'Add tool to migrate existing metadata to Product Fields.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'enable_migration_tool',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => true,
			],
            
			[
				'type' => 'sectionend',
				'id'   => self::PREFIX . 'settings_end',
			],
		];

        /**
		 * Filter: Modify or extend Luma Product Fields settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings Settings array.
		 */
		return apply_filters( 'Luma\ProductFields\settings_array', $settings );
	}
}
