<?php
/**
 * Frontend field renderer class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Frontend;

use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use WP_Term;


/**
 * Front end field renderer class
 *
 * Renders product metadata fields on the frontend.
 */
class FieldRenderer
{

    /**
     * Render a complete frontend field block.
     *
     * This method wraps the rendered field value with label, unit, and tooltip HTML,
     * using the `wrap_field()` method. It delegates the actual value formatting to
     * `render_field_value()`. Returns an empty string if no value is found.
     *
     * Typical use case: displaying the full field UI on the product page.
     *
     * @param array $field   Field definition array.
     * @param int   $post_id Product or variation ID.
     *
     * @return string Full rendered HTML block, or empty string if value is empty.
     */
    public function render_field(array $field, int $post_id): string
    {
        $value = $this->render_field_value($field, $post_id);        
        return $value !== '' ? self::wrap_field($field, $value) : '';
    }


    /**
     * Render a raw (unwrapped) field value for output.
     *
     * This method retrieves the raw field value for the given product ID and formats it
     * using either a core rendering method (e.g., render_text_field) or a registered
     * `render_frontend_cb` callback from the FieldTypeRegistry.
     *
     * It is used for producing a displayable value without surrounding UI — such as
     * schema markup, inline usage in tables, or JSON APIs.
     *
     * @param array $field   Field definition array.
     * @param int   $post_id Product or variation ID.
     *
     * @return string Raw rendered value, or empty string if value is missing or not renderable.
     */
    public function render_field_value(array $field, int $post_id): string {

        $value = Helpers::get_field_value($post_id, $field['slug']);
        if ( Helpers::is_truly_empty( $value ) ) {
            return '';
        }
        $type = $field['type'] ?? 'text';

        // External custom callback
        $cb = FieldTypeRegistry::get_callback($type, 'frontend_cb');
        if (is_callable($cb)) {
            return call_user_func($cb, $field, $value );
        }
        

        // Internal core renderer
        if (FieldTypeRegistry::is_core_type($type)) {
            $method = "render_{$type}_field";
            return method_exists($this, $method)
                ? $this->$method($field, $value)
                : esc_html($value);
        }

        return esc_html($value); // fallback
    }


    /**
     * Wrap rendered field value in semantic HTML using <dl>/<dt>/<dd>.
     *
     * Adds tooltip and optional schema.org support. Fields output
     * as properties of the parent Product entity (Yoast), avoiding nested
     * Product scopes that cause validation errors.
     *
     * @param array  $field Field definition.
     * @param string $value Rendered field value (HTML-escaped).
     * @param string $link  Optional link URL.
     * @return string
     */
    public static function wrap_field(array $field, string $value, string $link = ''): string
    {
        $slug        = esc_attr($field['slug']);
        $label       = esc_html($field['label'] ?? $slug);
        $unit        = $field['unit'] ?? '';
        $unit_html   = $unit ? Helpers::get_formatted_unit_html($unit) : '';
        $schema_prop = $field['schema_prop'] ?? '';
        $aria_label  = $label . ' ' . __('explanation', 'luma-product-fields');

        $schema_meta   = '';
        $itemprop_attr = '';

        // If schema property is set and unit is provided (e.g., weight, dimensions)
        if ($schema_prop && !empty($unit)) {
            $clean_value = esc_attr( trim( wp_strip_all_tags($value) ) );
            $schema_meta = "
            <meta itemprop='{$schema_prop}' content='{$clean_value}'>
            <meta itemprop='{$schema_prop}UnitText' content='" . esc_attr(trim($unit)) . "'>";
        } elseif ($schema_prop) {
            // Simple literal property (e.g., brand, sku, material)
            $itemprop_attr = " itemprop='" . esc_attr($schema_prop) . "'";
        }

        // Detect if $value already contains schema
        $is_html_with_schema = str_contains($value, 'itemprop=');

        // Build display value
        if ($link) {
            // Keep itemprop on span instead of <a> to avoid validation issues
            $display_value = $itemprop_attr
                ? "<a href='" . esc_url($link) . "'><span{$itemprop_attr}>{$value}</span></a>"
                : "<a href='" . esc_url($link) . "'>{$value}</a>";
        } else {
            $display_value = $is_html_with_schema ? $value : "<span{$itemprop_attr}>{$value}</span>";
        }

        // Tooltip
        $tooltip = '';
        if (!empty($field['frontend_desc'])) {
            $desc = wp_kses_post($field['frontend_desc']);
            $tooltip = "
            <span class='luma-product-fields-tooltip' tabindex='0'>
                <span class='luma-product-fields-tooltip-trigger' role='button' aria-describedby='tip-{$slug}' aria-label='{$aria_label}'>?</span>
                <span class='luma-product-fields-tooltip-txt' id='tip-{$slug}' role='tooltip'>{$desc}</span>
            </span>";
        }

        return "<dl class='luma-product-fields-product-meta' data-slug='{$slug}'>
            <dt class='luma-product-fields-label'>{$label}{$tooltip}:</dt>
            <dd class='luma-product-fields-val'>{$display_value} {$unit_html} {$schema_meta}</dd>
        </dl>";
    }

    

    /**
     * Render a text field.
     *
     * @param array $field Field definition.
     * @param mixed $value Field value.
     *
     * @return string
     */
    public function render_text_field(array $field, $value): string
    {
        return esc_html($value);
    }


    /**
     * Render a number field.
     *
     * @param array $field Field definition.
     * @param mixed $value Field value.
     *
     * @return string
     */
    public function render_number_field(array $field, $value): string
    {
        return esc_html(self::format_float($value) );
    }
        
        
    /**
     * Render an integer field
     *
     * @param array $field Field definition.
     * @param mixed $value Field value.
     *
     * @return string
     */
    public function render_integer_field(array $field, $value): string
    {
        return is_numeric($value) ? (int) $value : '';
    }


    /**
     * Render a min/max field (range).
     *
     * @param array $field Field definition.
     * @param mixed $value Field value (expects array with 'min' and 'max').
     *
     * @return string
     */
    public function render_minmax_field(array $field, $value): string
    {
        if (!is_array($value)) return '';
        $min = self::format_float($value['min'] ?? null);
        $max = self::format_float($value['max'] ?? null);
        if ($min === '' && $max === '') {
            return '';
        }
        return esc_html(trim("{$min} – {$max}", ' –') );
    }



    /**
     * Render a single taxonomy term (e.g. select field).
     *
     * @param array       $field Field definition.
     * @param string|null $value Term slug.
     *
     * @return string
     */
    public function render_single_field(array $field, $value): string
    {
        if (empty($value)) {
            return '';
        }
        return $this->render_multiple_field($field, [ $value ]);
    }



    /**
     * Render multiple taxonomy terms (e.g. checkbox/select-multiple).
     *
     * @param array $field  Field definition.
     * @param array $values Array of term slugs.
     *
     * @return string
     */
    public function render_multiple_field(array $field, $values): string
    {
        if (!is_array($values)) {
            return '';
        }

        $terms = get_terms([
            'taxonomy'   => $field['slug'],
            'slug'       => $values,
            'hide_empty' => false,
        ]);

        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        $should_link = !empty($field['show_links']) &&
                       \Luma\ProductFields\Registry\FieldTypeRegistry::supports($field['type'], 'link');

        $output = array_map(function (WP_Term $term) use ($should_link) {
            $name = esc_html($term->name);
            if (!$should_link) {
                return $name;
            }
            $url = get_term_link($term);
            return is_wp_error($url) ? $name : '<a href="' . esc_url($url) . '">' . $name . '</a>';
        }, $terms);

        return implode(', ', $output);
    }



    /**
     * Render an autocomplete field (term-based).
     *
     * @param array $field  Field definition.
     * @param array $values Array of term slugs.
     *
     * @return string
     */
    public function render_autocomplete_field(array $field, $values): string
    {
        return $this->render_multiple_field($field, $values);
    }

    
    
    /**
     * Format a float value for frontend display using WordPress locale settings.
     *
     * - Uses number_format_i18n() to apply locale-aware formatting.
     * - Removes trailing decimals if not needed (e.g., 1.00 → 1).
     *
     * @param float|string|null $value The value to format.
     *
     * @return string The localized float as a string, or empty string if invalid.
     */
    protected static function format_float($value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $float = (float) $value;

        // Strip decimals if whole number
        if (floor($float) == $float) {
            return number_format_i18n($float, 0);
        }

        return rtrim(rtrim(number_format_i18n($float, 2), '0'), number_format_i18n(0.1, 1)[1]);
    }


}
