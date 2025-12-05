<?php
/**
 * Field Registry class
 *
 * @package Luma\ProductFields
 * @since   1.0.0
 */
namespace Luma\ProductFields\Registry;

defined('ABSPATH') || exit;

/**
* Field Type Registry class 
*
* Central registry that defines all available field types, their 
* features (e.g., storage type, UI behaviors), and capabilities.
*
 * Hooks:
 *
 * @hook luma_product_fields_field_registry
 *       Filters the field registry array before it is used.
 *       @param array $field_types Array of registered field type definitions.
 *       @return array
 *
 * @hook luma_product_fields_allowed_units
 *       Filters the array of allowed units for numeric fields.
 *       @param array $units Array of allowed unit keys.
 *       @return array
 *
 */
class FieldTypeRegistry
{
        
    public static function init(): void {
        self::get_all();
    }
    
    
    /**
     * Get all registered field types.
     *
     * Each field type is keyed by a string identifier (e.g. 'text', 'number') and maps to
     * an array describing the field's behavior and capabilities.
     *
     * Keys in the inner array:
     * - `label` (string): Human-readable name shown in admin UI.
     * - `description` (string, optional): Optional tooltip/help text for the field type.
     * - `storage` ('meta'|'taxonomy'): Indicates how the value is stored.
     * - `supports` (string[], optional): List of supported features like 'unit', 'variations', etc.
     * - `validation` (string, optional): Validation type (e.g. 'float', 'range', 'int').
     * - `render_product_form_cb` (callable, optional): Callback to render the field in the product editor.
     * - `render_variation_form_cb` (callable, optional): Callback to render the field in variation edit view.
     * - `render_frontend_cb` (callable, optional): Callback to render the field on the frontend.
     * - `save_cb` (callable, optional): Callback to handle saving the field value.
     *
     * @hook luma_product_fields_field_types
     * Allows external plugins to register custom field types.
     *
     * @return array<string, array{
     *     label: string,
     *     description?: string,
     *     storage: 'meta'|'taxonomy',
     *     supports?: string[],
     *     validation?: string,
     *     render_product_form_cb?: callable,
     *     render_variation_form_cb?: callable,
     *     render_frontend_cb?: callable,
     *     save_cb?: callable
     * }>
     */
    public static function get_all(): array {
        $core_types = self::get_core_field_types();
        
        /**
         * @hook luma_product_fields_field_types
         * Filters the field registry array.
         *
         * @param array $fields Registered fields.
         *
         */         
        $all_types = apply_filters( 'luma_product_fields_field_types', $core_types );
        return $all_types;
    }


    /**
     * Return built-in core field types.
     *
     * @return array<string, array>
     * @see self::get_all()
     */
    public static function get_core_field_types(): array
    {
        $fields = [
            'text' => [
                'label'       => __('Text field', 'luma-product-fields'),
                'description' => __('A simple text input.', 'luma-product-fields'),
                'storage'     => 'meta',
                'datatype'        => 'text',
                'supports'    => ['variations'],
            ],
            'number' => [
                'label'       => __('Number', 'luma-product-fields'),
                'description' => __('A single numeric value.', 'luma-product-fields'),
                'storage'     => 'meta',
                'datatype'        => 'number',
                'supports'    => ['unit', 'variations'],
                'validation'  => 'float',
            ],
            'integer' => [
                'label'       => __('Integer', 'luma-product-fields'),
                'description' => __('A single integer value. You can use both , and . as decimal separator in admin. Presentation is based on your locale setting.', 'luma-product-fields'),
                'storage'     => 'meta',
                'datatype'        => 'number',
                'supports'    => ['unit', 'variations'],
                'validation'  => 'integer',
            ],
            'minmax' => [
                'label'       => __('Range (Min–Max)', 'luma-product-fields'),
                'description' => __('Two numeric inputs, e.g. 3.5 – 6.', 'luma-product-fields'),
                'storage'     => 'meta',
                'datatype'        => 'number',
                'supports'    => ['unit', 'variations'],
                'validation'  => 'range',
            ],
            'single' => [
                'label'       => __('Single select', 'luma-product-fields'),
                'description' => __('Dropdown from predefined terms.', 'luma-product-fields'),
                'storage'     => 'taxonomy',
                'datatype'        => 'text',
               'supports'    => ['unit', 'link'],
            ],
            'multiple' => [
                'label'       => __('Checkboxes', 'luma-product-fields'),
                'description' => __('Multiple predefined options.', 'luma-product-fields'),
                'datatype'        => 'text',
                'storage'     => 'taxonomy',
                'supports'    => ['multiple_values', 'link'],
            ],
            'autocomplete' => [
                'label'       => __('Autocomplete', 'luma-product-fields'),
                'description' => __('Suggest existing terms, allow new.', 'luma-product-fields'),
                'datatype'        => 'text',
                'storage'     => 'taxonomy',
                'supports'    => ['multiple_values', 'link' ],
            ],
        ];
        return $fields;
    }


    /**
     *  Get available units for numeric fields.
     *
     * @return array
     */
    public static function get_units(): array
    {
        $units = [
            'cm'        => __('cm', 'luma-product-fields'),
            'mm'        => __('mm', 'luma-product-fields'),
            'm'         => __('meters', 'luma-product-fields'),
            'g'         => __('gram', 'luma-product-fields'),
            'kg'        => __('kg', 'luma-product-fields'),
            '"'         => __('inches', 'luma-product-fields'),
            'ft.'       => __('feet', 'luma-product-fields'),
            'pcs'       => __('pcs', 'luma-product-fields'),
            'years'     => __('years', 'luma-product-fields'),
            '%'         => __('%', 'luma-product-fields'),        
        ];

        // add currency
        $currency_code   = get_woocommerce_currency();
        $currency_symbol = get_woocommerce_currency_symbol( $currency_code );
        if ( $currency_code ) {
            $units[ strtolower( $currency_code ) ] = esc_html( $currency_symbol );                            
        }
        
        /**
         * @hook luma_product_fields_allowed_units
         * Filters the array of allowed units.
         *
         * @param array $units Array of allowed unit keys.
         *
         * @since 1.0.0
         */
        return apply_filters( 'luma_product_fields_allowed_units', $units);
    }


    /**
     * Get type definition by slug.
     *
     * @param string $slug
     * @return array|null
     */
    public static function get(string $slug): ?array
    {
        $types = self::get_all();                        
        return $types[$slug] ?? null;
    }


    /**
     * Get human-readable label for a field type slug.
     *
     * @param string $slug
     * @return string
     */
    public static function get_field_type_label(string $slug): string
    {
        $types = self::get_all();
        return $types[$slug]['label'] ?? ucfirst($slug);
    }


    /**
     * Get storage type for a given field type slug.
     *
     * @param string $slug
     * @return string 'meta' or 'taxonomy'
     */
    public static function get_field_storage_type(string $slug): string
    {
        $types = self::get_all();
        return $types[$slug]['storage'] ?? 'meta';
    }


    /**
     * Generic capability check: whether a field type supports a feature.
     *
     * @param string $type
     * @param string $feature
     * @return bool
     */
    public static function supports(string $type, string $feature): bool
    {
        $types = self::get_all();
        return in_array($feature, $types[$type]['supports'] ?? [], true);
    }


    /**
     * Check if field type supports display in variations.
     *
     * @param string $field_type
     * @return bool
     */
    public static function supports_variations(string $field_type): bool
    {
        return self::supports($field_type, 'variations');
    }


    /**
     * Check if a field type supports multiple values.
     *
     * @param string $type
     * @return bool
     */
    public static function supports_multiple_values(string $type): bool
    {
        return self::supports($type, 'multiple_values');
    }
    
    
    
    /**
     * Check if field data is numeric
     *
     * @param string $type 
     * @return bool
     */
    public static function field_type_is_numeric(string $type ): bool
    {
        $types = self::get_all();
        return $types[$type]['datatype'] === 'number' ? true : false;
    }


    /**
     * Check if a given field type is a core (built-in) field type.
     *
     * Core field types are defined internally by the plugin and not added via filters.
     *
     * @param string $type The field type slug to check.
     * @return bool True if the type is a core field type, false otherwise.
     */
    public static function is_core_type( string $type ): bool {
        return array_key_exists( $type, self::get_core_field_types() );
    }


    /**
     * Get a specific callback for a registered field type.
     *
     * @param string $field_type
     * @param string $callback_key
     * @return callable|null
     */
    public static function get_callback( string $field_type, string $callback_key ): ?callable {
        $definition = self::get( $field_type );
        return is_callable( $definition[ $callback_key ] ?? null )
            ? $definition[ $callback_key ]
            : null;
    }





}

