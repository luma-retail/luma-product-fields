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

        // Internal core renderer
        if (FieldTypeRegistry::is_core_type($type)) {
            $method = "render_{$type}_field";
            return method_exists($this, $method)
                ? $this->$method($field, $value)
                : esc_html($value);
        }

        // External custom callback
        $cb = FieldTypeRegistry::get_callback($type, 'render_frontend_cb');
        if (is_callable($cb)) {
            return call_user_func($cb, $field, $value );
        }

        return esc_html($value); // fallback.
    }

    
    /**
     * Wrap rendered field value in semantic HTML using <dl>/<dt>/<dd>.
     *
     * @param array  $field Field definition.
     * @param string $value Rendered field value.
     * @param string $link  Optional link URL.
     *
     * @return string
     */
    public static function wrap_field( array $field, string $value, string $link = '' ): string {
        $raw_slug = (string) ( $field['slug'] ?? '' );

        if ( '' === $raw_slug ) {
            return '';
        }

        $slug  = esc_attr( $raw_slug );
        $label = esc_html( (string) ( $field['label'] ?? $raw_slug ) );

        $tooltip    = self::build_tooltip_html( $field, $slug, $label );
        $dd_payload = self::build_dd_payload_html( $field, $value, $link );

        return sprintf(
            "<dl class='lumaprfi-product-meta' data-slug='%1\$s'>
                <dt class='lumaprfi-label'>%2\$s%3\$s:</dt>
                <dd class='lumaprfi-val'>%4\$s</dd>
            </dl>",
            $slug,
            $label,
            $tooltip,
            $dd_payload
        );
    }


    /**
     * Build the tooltip HTML (if any) for a frontend field.
     *
     * @param array  $field Field definition.
     * @param string $slug  Field slug, already escaped for attributes.
     * @param string $label Field label, already escaped for display.
     * @return string Tooltip HTML or empty string.
     */
    protected static function build_tooltip_html( array $field, string $slug, string $label ): string {
        if ( empty( $field['frontend_desc'] ) ) {
            return '';
        }

        $aria_label = esc_attr( $label . ' ' . __( 'explanation', 'luma-product-fields' ) );
        $desc = (string) $field['frontend_desc'];

        return sprintf(
            "<span class='lumaprfi-tooltip' tabindex='0'>
                <span class='lumaprfi-tooltip-trigger' role='button' aria-describedby='tip-%1\$s' aria-label='%2\$s'>?</span>
                <span class='lumaprfi-tooltip-txt' id='tip-%1\$s' role='tooltip'>%3\$s</span>
            </span>",
            $slug,
            $aria_label,
            $desc
        );
    }


    /**
     * Build the complete <dd> payload (value + unit + schema meta).
     *
     * Important: This method only *builds* HTML. Final sanitizing must be done
     * by sanitize_dd_html().
     *
     * @param array  $field Field definition.
     * @param string $value Rendered value (already escaped/sanitized by renderer contract).
     * @param string $link  Optional link URL.
     * @return string Raw dd HTML payload.
     */
    protected static function build_dd_payload_html( array $field, string $value, string $link = '' ): string {
        $unit        = (string) ( $field['unit'] ?? '' );

        $unit_html = $unit ? Helpers::get_formatted_unit_html( $unit ) : '';

        $display_value = self::build_display_value_html( $value, $link );

        return trim( $display_value . ' ' . $unit_html );
    }



    /**
     * Build the display value HTML (value wrapped in <span>, optionally inside <a>).
     *
     * @param string $value         Rendered field value (may contain HTML).
     * @param string $link          Optional URL.
     *
     * @return string
     */
    protected static function build_display_value_html( string $value, string $link ): string {
        $has_schema_in_value = false !== strpos( $value, 'itemprop=' );

        $inner = $has_schema_in_value
            ? $value
            : sprintf( '<span>%s</span>', $value );

        if ( '' === $link ) {
            return $inner;
        }

        return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), $inner );
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
