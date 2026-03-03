<?php
/**
 * Admin settings for Luma Product Fields.
 *
 * @package Luma\ProductFields\Admin
 */

namespace Luma\ProductFields\Admin;

use Luma\ProductFields\Utils\CacheInvalidator;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Migration\LegacyMetaMigrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
  * @hook luma_product_fields_settings_array
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
	 * Settings field ID for units management field.
	 */
	public const UNITS_FIELD_ID = self::PREFIX . 'units_editor';

	/**
	 * Settings field ID for unit aliases management field.
	 */
	public const UNIT_ALIASES_FIELD_ID = self::PREFIX . 'unit_aliases_editor';

    
    
	/**
	 * Hook into WooCommerce settings API.
	 */
	public function __construct() {
		add_filter( 'woocommerce_get_sections_products', [ $this, 'add_settings_section' ] );
		add_filter( 'woocommerce_get_settings_products', [ $this, 'add_settings_fields' ], 10, 2 );
		add_action( 'woocommerce_update_options_products_luma_product_fields', [ $this, 'handle_save' ] );
		add_action( 'woocommerce_admin_field_luma_settings_intro', [ $this, 'render_settings_intro' ] );
		add_action( 'woocommerce_admin_field_luma_settings_tabs', [ $this, 'render_settings_tabs' ] );
		add_action( 'woocommerce_admin_field_luma_migration_link', [ $this, 'render_migration_link_field' ] );
		add_action( 'woocommerce_admin_field_luma_units_repeater', [ $this, 'render_units_repeater_field' ] );
		add_action( 'woocommerce_admin_field_luma_unit_aliases_repeater', [ $this, 'render_unit_aliases_repeater_field' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
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

		$current_tab = $this->get_current_settings_tab();
		$tabs        = $this->get_settings_tabs();

		if ( ! isset( $tabs[ $current_tab ] ) ) {
			$current_tab = 'general';
		}

		$settings = [
			[
				'title' => __( 'Luma Product Fields Settings', 'luma-product-fields' ),
                'desc' =>  __('These are the system settings for Luma Product Fields. To add or remove fields, go to Products → Product Fields', 'luma-product-fields'),
				'type'  => 'luma_settings_intro',
				'id'    => self::PREFIX . 'settings_title',
			],
			[
				'id'   => self::PREFIX . 'settings_tabs',
				'type' => 'luma_settings_tabs',
			],
		];

		if ( 'general' === $current_tab ) {
			$settings = array_merge( $settings, [
			[
				'title' => __( 'General', 'luma-product-fields' ),
				'type'  => 'title',
				'id'    => self::PREFIX . 'general_settings_title',
			],
			[
				'title'    => __( 'Front End title', 'luma-product-fields' ),
				'desc'     => __( 'Title on the product fields tab.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'front_end_title',
				'type'     => 'text',
				'default'  => __('Additional information', 'luma-product-fields'),
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
				'title'    => __( 'Enable built-in tooltips', 'luma-product-fields' ),
				'desc'     => __( 'Enable tooltips for package weight and package size.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'enable_builtin_field_tooltips',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Package weight tooltip text', 'luma-product-fields' ),
				'desc'     => __( 'Shown when built-in tooltips are enabled. Package weight and package size are intended for shipping calculations and should include packaging. If you need net weight or net dimensions, you can now create fields for these.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'weight_tooltip_text',
				'type'     => 'textarea',
				'default'  => __( 'The weight of the product including packaging.', 'luma-product-fields' ),
				'css'      => 'min-width: 320px; min-height: 80px;',
			],
			[
				'title'    => __( 'Package size tooltip text', 'luma-product-fields' ),
				'desc'     => __( 'Shown when built-in tooltips are enabled.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'dimensions_tooltip_text',
				'type'     => 'textarea',
				'default'  => __( 'The size of the product including packaging.', 'luma-product-fields' ),
				'css'      => 'min-width: 320px; min-height: 80px;',
			],
			[
				'type' => 'sectionend',
				'id'   => self::PREFIX . 'general_settings_end',
			],
			] );
		} elseif ( 'style' === $current_tab ) {
			$settings = array_merge( $settings, [
			[
				'title' => __( 'Style', 'luma-product-fields' ),
				'type'  => 'title',
				'id'    => self::PREFIX . 'style_settings_title',
			],
			[
				'title'    => __( 'Field row style', 'luma-product-fields' ),
				'desc'     => __( 'Choose how each field row is visually separated.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'frontend_row_style',
				'type'     => 'select',
				'options'  => [
					'plain'   => __( 'Plain', 'luma-product-fields' ),
					'divider' => __( 'Small divider between rows', 'luma-product-fields' ),
					'striped' => __( 'Striped rows', 'luma-product-fields' ),
				],
				'default'  => 'plain',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Field layout', 'luma-product-fields' ),
				'desc'     => __( 'Choose automatic label width or fixed-width grid layout.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'frontend_layout_style',
				'type'     => 'select',
				'options'  => [
					'auto' => __( 'Auto label width', 'luma-product-fields' ),
					'grid' => __( 'Grid (same label width)', 'luma-product-fields' ),
				],
				'default'  => 'auto',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Labels bold', 'luma-product-fields' ),
				'desc'     => __( 'Enable to render field labels in bold.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'frontend_labels_bold',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Values bold', 'luma-product-fields' ),
				'desc'     => __( 'Enable to render field values in bold.', 'luma-product-fields' ),
				'id'       => self::PREFIX . 'frontend_values_bold',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => self::PREFIX . 'style_settings_end',
			],
			] );
		} elseif ( 'units' === $current_tab ) {
			$settings = array_merge( $settings, [
			[
				'title' => __( 'Units', 'luma-product-fields' ),
				'type'  => 'title',
				'id'    => self::PREFIX . 'units_settings_title',
			],
			[
				'title'    => __( 'Units editor', 'luma-product-fields' ),
				'desc'     => __( 'Add or remove units used by unit-aware field types.', 'luma-product-fields' ),
				'id'       => self::UNITS_FIELD_ID,
				'type'     => 'luma_units_repeater',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Unit aliases for migration', 'luma-product-fields' ),
				'desc'     => __( 'Old product text often uses different words for the same unit. Add common alternatives here so the migration tool can better find and import the right values.', 'luma-product-fields' ),
				'id'       => self::UNIT_ALIASES_FIELD_ID,
				'type'     => 'luma_unit_aliases_repeater',
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => self::PREFIX . 'units_settings_end',
			],
			] );
		} elseif ( 'tools' === $current_tab ) {
			$settings = array_merge( $settings, [
            [
				'title' => __( 'Tools', 'luma-product-fields' ),
				'type'  => 'title',
				'id'    => self::PREFIX . 'tools_settings_title',
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
				'title' => __( 'Migration page', 'luma-product-fields' ),
				'type'  => 'luma_migration_link',
				'id'    => self::PREFIX . 'migration_link',
			],
            
			[
				'type' => 'sectionend',
				'id'   => self::PREFIX . 'tools_settings_end',
			],
			] );
		}

		/**
		 * Filter: Modify or extend Luma Product Fields settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed>        $settings    Settings array for current rendered tab.
		 * @param string                     $current_tab Current settings tab slug.
		 * @param array<string,string>       $tabs        Available settings tabs (slug => label).
		 */
		return apply_filters( 'luma_product_fields_settings_array', $settings, $current_tab, $tabs );
	}


	/**
	 * Render intro heading/description above settings tabs.
	 *
	 * @param array $value Field configuration.
	 * @return void
	 */
	public function render_settings_intro( array $value ): void {
		echo '<tr valign="top">';
		echo '<th scope="row" class="titledesc">';
		echo '<h2>' . esc_html( (string) ( $value['title'] ?? '' ) ) . '</h2>';
		echo '</th>';
		echo '<td class="forminp">';
		if ( ! empty( $value['desc'] ) ) {
			echo '<p>' . esc_html( (string) $value['desc'] ) . '</p>';
		}
		echo '</td>';
		echo '</tr>';
	}


	/**
	 * Get available tabs for this settings page.
	 *
	 * @return array<string,string>
	 */
	protected function get_settings_tabs(): array {
		$tabs = [
			'general' => __( 'General', 'luma-product-fields' ),
			'style'   => __( 'Style', 'luma-product-fields' ),
			'units'   => __( 'Units', 'luma-product-fields' ),
			'tools'   => __( 'Tools', 'luma-product-fields' ),
		];

		/**
		 * Filter the in-page tabs shown for Luma Product Fields settings.
		 *
		 * @param array<string,string> $tabs Tab map (slug => label).
		 */
		$filtered_tabs = apply_filters( 'luma_product_fields_settings_tabs', $tabs );

		return is_array( $filtered_tabs ) ? $filtered_tabs : $tabs;
	}


	/**
	 * Resolve current in-page tab for Luma settings section.
	 *
	 * @return string
	 */
	protected function get_current_settings_tab(): string {
		$tab = 'general';

		$post_tab_input = filter_input( INPUT_POST, 'luma_settings_tab', FILTER_DEFAULT );
		$get_tab_input  = filter_input( INPUT_GET, 'luma_settings_tab', FILTER_DEFAULT );

		if ( is_string( $post_tab_input ) ) {
			$tab = sanitize_key( wp_unslash( $post_tab_input ) );
		} elseif ( is_string( $get_tab_input ) ) {
			$tab = sanitize_key( wp_unslash( $get_tab_input ) );
		}

		$tabs = $this->get_settings_tabs();

		if ( '' === $tab || ! isset( $tabs[ $tab ] ) ) {
			return 'general';
		}

		return $tab;
	}


	/**
	 * Render in-page tabs for Luma settings.
	 *
	 * @return void
	 */
	public function render_settings_tabs(): void {
		$tabs        = $this->get_settings_tabs();
		$current_tab = $this->get_current_settings_tab();

		echo '<tr valign="top"><td colspan="2" class="forminp">';
		echo '<nav class="nav-tab-wrapper woocommerce-nav-tab-wrapper" style="margin-bottom:1em;" data-lumaprfi-current-tab="' . esc_attr( $current_tab ) . '">';

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				[
					'page'              => 'wc-settings',
					'tab'               => 'products',
					'section'           => 'luma_product_fields',
					'luma_settings_tab' => $slug,
				],
				admin_url( 'admin.php' )
			);

			$class = 'nav-tab' . ( $slug === $current_tab ? ' nav-tab-active' : '' );

			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';
		echo '<input type="hidden" name="luma_settings_tab" value="' . esc_attr( $current_tab ) . '" />';
		echo '</td></tr>';
	}


	/**
	 * Render a quick link to the migration page in Tools tab.
	 *
	 * @param array $value Field config.
	 * @return void
	 */
	public function render_migration_link_field( array $value ): void {
		$migration_url = admin_url( 'edit.php?post_type=product&page=luma-product-fields-migration' );
		$is_enabled    = 'yes' === get_option( self::PREFIX . 'enable_migration_tool', 'yes' );

		echo '<tr valign="top">';
		echo '<th scope="row" class="titledesc">';
		echo '<label>' . esc_html( $value['title'] ?? '' ) . '</label>';
		echo '</th>';
		echo '<td class="forminp">';

		if ( $is_enabled ) {
			echo '<a href="' . esc_url( $migration_url ) . '" class="button">' . esc_html__( 'Open migration tool', 'luma-product-fields' ) . '</a>';
			echo '<p class="description">' . esc_html__( 'Open the migration page to map legacy meta keys and import values into Product Fields.', 'luma-product-fields' ) . '</p>';
		} else {
			echo '<button type="button" class="button" disabled="disabled">' . esc_html__( 'Open migration tool', 'luma-product-fields' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'The migration tool is currently disabled above. Enable it and save settings to open the migration page.', 'luma-product-fields' ) . '</p>';
		}

		echo '</td>';
		echo '</tr>';
	}


	/**
	 * Invalidate meta cache after saving options.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( 'units' === $this->get_current_settings_tab() ) {
			$units = $this->parse_units_repeater_from_request();
			if ( empty( $units ) ) {
				delete_option( FieldTypeRegistry::OPTION_UNITS );
			} else {
				update_option( FieldTypeRegistry::OPTION_UNITS, $units );
			}

			$aliases = $this->parse_aliases_repeater_from_request();
			if ( empty( $aliases ) ) {
				delete_option( FieldTypeRegistry::OPTION_UNIT_ALIASES );
			} else {
				update_option( FieldTypeRegistry::OPTION_UNIT_ALIASES, $aliases );
			}
		}

		CacheInvalidator::invalidate_all_meta_caches();
	}


	/**
	 * Build current units editor value from persisted units/defaults.
	 *
	 * @return string
	 */
	protected function get_units_for_repeater(): array {
		$units = FieldTypeRegistry::get_units();

		$currency_code = get_woocommerce_currency();
		if ( ! empty( $currency_code ) ) {
			unset( $units[ strtolower( $currency_code ) ] );
		}

		return $units;
	}


	/**
	 * Build current aliases editor value from persisted aliases/defaults.
	 *
	 * @return string
	 */
	protected function get_aliases_for_repeater(): array {
		$stored_aliases = get_option( FieldTypeRegistry::OPTION_UNIT_ALIASES, null );
		if ( ! is_array( $stored_aliases ) ) {
			return LegacyMetaMigrator::get_default_unit_aliases();
		}

		$aliases = [];

		foreach ( $stored_aliases as $slug => $slug_aliases ) {
			if ( ! is_scalar( $slug ) || ! is_array( $slug_aliases ) ) {
				continue;
			}

			$normalized_slug = FieldTypeRegistry::normalize_unit_slug( (string) $slug );
			if ( '' === $normalized_slug ) {
				continue;
			}

			$line_aliases = [];
			foreach ( $slug_aliases as $alias ) {
				if ( ! is_scalar( $alias ) ) {
					continue;
				}

				$normalized_alias = sanitize_text_field( (string) $alias );
				if ( '' === $normalized_alias ) {
					continue;
				}

				$line_aliases[] = $normalized_alias;
			}

			if ( empty( $line_aliases ) ) {
				continue;
			}

			$aliases[ $normalized_slug ] = array_values( array_unique( $line_aliases ) );
		}

		return $aliases;
	}


	/**
	 * Render units repeater settings field.
	 *
	 * @param array $value Field configuration from Woo settings API.
	 * @return void
	 */
	public function render_units_repeater_field( array $value ): void {
		$units = $this->get_units_for_repeater();

		if ( empty( $units ) ) {
			$units = [ '' => '' ];
		}

		echo '<tr valign="top">';
		echo '<th scope="row" class="titledesc">';
		echo '<label>' . esc_html( $value['title'] ?? '' ) . '</label>';
		echo '</th>';
		echo '<td class="forminp">';
		echo '<table class="lumaprfi-repeater" data-lumaprfi-repeater="units">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Slug', 'luma-product-fields' ) . '<br><span class="description">' . esc_html__( 'Internal key', 'luma-product-fields' ) . '</span></th>';
		echo '<th scope="col">' . esc_html__( 'Label', 'luma-product-fields' ) . '<br><span class="description">' . esc_html__( 'Shown to users', 'luma-product-fields' ) . '</span></th>';
		echo '<th scope="col" style="width:120px">' . esc_html__( 'Actions', 'luma-product-fields' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $units as $slug => $label ) {
			echo '<tr>';
			echo '<td><input type="text" class="regular-text" name="luma_product_fields_units_slug[]" value="' . esc_attr( (string) $slug ) . '" /></td>';
			echo '<td><input type="text" class="regular-text" name="luma_product_fields_units_label[]" value="' . esc_attr( (string) $label ) . '" /></td>';
			echo '<td><button type="button" class="button button-link-delete lumaprfi-remove-row">' . esc_html__( 'Remove', 'luma-product-fields' ) . '</button></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><button type="button" class="button lumaprfi-add-row" data-lumaprfi-target="units">' . esc_html__( 'Add unit', 'luma-product-fields' ) . '</button></p>';
		if ( ! empty( $value['desc'] ) ) {
			echo '<p class="description">' . esc_html( (string) $value['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}


	/**
	 * Render unit aliases repeater settings field.
	 *
	 * @param array $value Field configuration from Woo settings API.
	 * @return void
	 */
	public function render_unit_aliases_repeater_field( array $value ): void {
		$aliases = $this->get_aliases_for_repeater();
		$rows    = [];

		foreach ( $aliases as $slug => $slug_aliases ) {
			if ( ! is_scalar( $slug ) || ! is_array( $slug_aliases ) ) {
				continue;
			}

			$prepared_aliases = [];
			foreach ( $slug_aliases as $alias ) {
				if ( ! is_scalar( $alias ) ) {
					continue;
				}

				$prepared_alias = sanitize_text_field( (string) $alias );
				if ( '' === $prepared_alias ) {
					continue;
				}

				$prepared_aliases[] = $prepared_alias;
			}

			$prepared_aliases = array_values( array_unique( $prepared_aliases ) );
			$rows[]           = [
				'slug'    => (string) $slug,
				'aliases' => implode( ',', $prepared_aliases ),
			];
		}

		if ( empty( $rows ) ) {
			$rows[] = [ 'slug' => '', 'aliases' => '' ];
		}

		echo '<tr valign="top">';
		echo '<th scope="row" class="titledesc">';
		echo '<label>' . esc_html( $value['title'] ?? '' ) . '</label>';
		echo '</th>';
		echo '<td class="forminp">';
		echo '<table class="lumaprfi-repeater" data-lumaprfi-repeater="aliases">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Unit slug', 'luma-product-fields' ) . '<br><span class="description">' . esc_html__( 'Use the slug from Units', 'luma-product-fields' ) . '</span></th>';
		echo '<th scope="col">' . esc_html__( 'Aliases (comma-separated)', 'luma-product-fields' ) . '<br><span class="description">' . esc_html__( 'Alternative words in old text', 'luma-product-fields' ) . '</span></th>';
		echo '<th scope="col" style="width:120px">' . esc_html__( 'Actions', 'luma-product-fields' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><input type="text" class="regular-text" name="luma_product_fields_alias_unit[]" value="' . esc_attr( $row['slug'] ) . '" /></td>';
			echo '<td><input type="text" class="regular-text" name="luma_product_fields_aliases[]" value="' . esc_attr( $row['aliases'] ) . '" /></td>';
			echo '<td><button type="button" class="button button-link-delete lumaprfi-remove-row">' . esc_html__( 'Remove', 'luma-product-fields' ) . '</button></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><button type="button" class="button lumaprfi-add-row" data-lumaprfi-target="aliases">' . esc_html__( 'Add alias row', 'luma-product-fields' ) . '</button></p>';
		if ( ! empty( $value['desc'] ) ) {
			echo '<p class="description">' . esc_html( (string) $value['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}


	/**
	 * Parse units repeater request values into a units map.
	 *
	 * @return array<string,string>
	 */
	protected function parse_units_repeater_from_request(): array {
		$units = [];
		$raw_slugs_input  = filter_input( INPUT_POST, 'luma_product_fields_units_slug', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$raw_labels_input = filter_input( INPUT_POST, 'luma_product_fields_units_label', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$raw_slugs  = is_array( $raw_slugs_input ) ? wp_unslash( $raw_slugs_input ) : [];
		$raw_labels = is_array( $raw_labels_input ) ? wp_unslash( $raw_labels_input ) : [];

		if ( ! is_array( $raw_slugs ) || ! is_array( $raw_labels ) ) {
			return $units;
		}

		$total = max( count( $raw_slugs ), count( $raw_labels ) );
		for ( $index = 0; $index < $total; $index++ ) {
			$slug_raw  = isset( $raw_slugs[ $index ] ) && is_scalar( $raw_slugs[ $index ] ) ? (string) $raw_slugs[ $index ] : '';
			$label_raw = isset( $raw_labels[ $index ] ) && is_scalar( $raw_labels[ $index ] ) ? (string) $raw_labels[ $index ] : '';

			if ( '' === trim( $slug_raw ) && '' === trim( $label_raw ) ) {
				continue;
			}

			$slug  = FieldTypeRegistry::normalize_unit_slug( $slug_raw );
			$label = sanitize_text_field( $label_raw );

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			$units[ $slug ] = $label;
		}

		return $units;
	}


	/**
	 * Parse aliases repeater request values into alias map.
	 *
	 * @return array<string,array<int,string>>
	 */
	protected function parse_aliases_repeater_from_request(): array {
		$aliases = [];
		$raw_units_input   = filter_input( INPUT_POST, 'luma_product_fields_alias_unit', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$raw_aliases_input = filter_input( INPUT_POST, 'luma_product_fields_aliases', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$raw_units   = is_array( $raw_units_input ) ? wp_unslash( $raw_units_input ) : [];
		$raw_aliases = is_array( $raw_aliases_input ) ? wp_unslash( $raw_aliases_input ) : [];

		if ( ! is_array( $raw_units ) || ! is_array( $raw_aliases ) ) {
			return $aliases;
		}

		$total = max( count( $raw_units ), count( $raw_aliases ) );
		for ( $index = 0; $index < $total; $index++ ) {
			$slug_raw    = isset( $raw_units[ $index ] ) && is_scalar( $raw_units[ $index ] ) ? (string) $raw_units[ $index ] : '';
			$aliases_raw = isset( $raw_aliases[ $index ] ) && is_scalar( $raw_aliases[ $index ] ) ? (string) $raw_aliases[ $index ] : '';

			if ( '' === trim( $slug_raw ) && '' === trim( $aliases_raw ) ) {
				continue;
			}

			$slug = FieldTypeRegistry::normalize_unit_slug( $slug_raw );
			if ( '' === $slug ) {
				continue;
			}

			$line_aliases = array_map( 'trim', explode( ',', $aliases_raw ) );
			$line_aliases = array_map( 'sanitize_text_field', $line_aliases );
			$line_aliases = array_values( array_filter( $line_aliases ) );

			if ( empty( $line_aliases ) ) {
				continue;
			}

			if ( ! isset( $aliases[ $slug ] ) ) {
				$aliases[ $slug ] = [];
			}

			$aliases[ $slug ] = array_values(
				array_unique(
					array_merge( $aliases[ $slug ], $line_aliases )
				)
			);
		}

		return $aliases;
	}


	/**
	 * Enqueue assets used by repeater fields on this plugin settings screen.
	 *
	 * @return void
	 */
	public function enqueue_settings_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}

		$tab_input = filter_input( INPUT_GET, 'tab', FILTER_DEFAULT );
		$section_input = filter_input( INPUT_GET, 'section', FILTER_DEFAULT );

		$tab = is_string( $tab_input )
			? sanitize_key( wp_unslash( $tab_input ) )
			: '';

		$section = is_string( $section_input )
			? sanitize_key( wp_unslash( $section_input ) )
			: '';

		if ( 'products' !== $tab || 'luma_product_fields' !== $section ) {
			return;
		}

		wp_enqueue_script(
			'luma-product-fields-settings-units',
			LUMA_PRODUCT_FIELDS_PLUGIN_URL . 'js/admin/settings-units.js',
			[ 'jquery' ],
			LUMA_PRODUCT_FIELDS_PLUGIN_VER,
			true
		);
	}

}
