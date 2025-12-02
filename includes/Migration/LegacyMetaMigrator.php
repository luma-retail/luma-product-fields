<?php
/**
 * Legacy Meta migration class
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Migration;

use WC_Product;
use WP_Term;
use WP_Error;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Product\FieldStorage;


defined('ABSPATH') || exit;

/**
 * Handles migration of legacy post meta fields to the this system.
 *
 * Performs transformations, unit parsing, variation inheritance, skip/overwrite
 * control, and dry-run diagnostics. Intended for single-field migrations driven
 * by the admin UI.
 *
 * @todo Refactor product lookup and meta reads.
 *       Implement direct SQL queries with chunked iteration to improve
 *       performance on large catalogs.
 *
 * @hook Luma\ProductFields\unit_aliases
 *      Filters the default unit alias map.
 *      @param array<string, string[]> $aliases Default unit aliases.
 *
 */
class LegacyMetaMigrator
{
    
    /**
     * Run the migration process for selected fields and meta mappings.
     *
     * This method retrieves legacy meta values from products and migrates them to our
     * system. It supports both default value transformations and field-type-specific 
     * migration callbacks via the `migrate_cb` key in the FieldTypeRegistry.
     *
     * If a `migrate_cb` is defined and returns `['saved' => true]`, no further action is taken.
     * Otherwise, the transformed value is passed to `FieldStorage::save_field()` unless in dry-run mode.
     *
     * The result array contains a per-product, per-field summary including status, original value,
     * and optionally the new value or reason for skipping.
     *
     * @param array $mapping An array where each key is a field slug, and each value is an array with:
     *                       - 'meta_key'   (string)  => Legacy meta key to read from.
     *                       - 'field'      (array)   => Field definition array.
     *                       - 'number_index' (int)   => Optional. Positional index of number to extract.
     *                       - 'match_unit'   (bool)  => Optional. Try unit-based number extraction.
     *
     * @param bool  $dry_run If true, performs a dry-run (transforms values without saving).
     *
     * @return array<int, array<string, array{
     *     status: string,
     *     original: mixed,
     *     new?: mixed,
     *     reason?: string,
     *     created?: array<string, bool>,
     *     saved?: bool
     * }>> Summary of migration results, keyed by product ID and field slug.
     *
     * @example
     *   [
     *     12345 => [
     *       'fiber_content' => [
     *         'status' => 'migrated',
     *         'original' => '70% wool, 30% alpaca',
     *         'new' => ['wool', 'alpaca'],
     *         'created' => [
     *           'wool' => false,
     *           'alpaca' => true
     *         ],
     *         'saved' => true,
     *       ],
     *     ],
     *     12346 => [
     *       'fiber_content' => [
     *         'status' => 'skipped',
     *         'reason' => 'No valid data',
     *         'original' => '',
     *       ],
     *     ],
     *     12347 => [
     *       'fiber_content' => [
     *         'status' => 'dry-run',
     *         'original' => '100% silke',
     *         'new' => ['silke'],
     *         'created' => ['silke' => false],
     *         'saved' => false,
     *       ],
     *     ],
     *   ]
     */
public function run(array $mapping, bool $dry_run = true): array
{
    $summary = [];

    foreach ($mapping as $slug => $config) {
        $old_key    = $config['meta_key'];
        $field      = $config['field'];
        $index      = isset($config['number_index']) ? (int) $config['number_index'] : 0;
        $match_unit = !empty($config['match_unit']);

        $types = ['simple', 'variable', 'grouped', 'external', 'bundle', 'composite'];

        if (!empty($config['include_variations'])) {
            $types[] = 'variation';
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT post_id 
             FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ('publish', 'private', 'draft')",
            $old_key
        );

        $product_ids = $wpdb->get_col( $sql );

        $chunks = array_chunk( $product_ids, 300 );

        foreach ( $chunks as $chunk ) {
            foreach ( $chunk as $product_id ) {
                
                $old_value = get_post_meta($product_id, $old_key, true);
                if ($old_value === '') {
                    continue;
                }

                $field_type = FieldTypeRegistry::get($field['type']);
                $transformed = null;
                $extra = [];

                if (!empty($field_type['migrate_cb']) && is_callable($field_type['migrate_cb'])) {
                    $result = call_user_func($field_type['migrate_cb'], [
                        'product_id' => $product_id,
                        'field'      => $field,
                        'value'      => $old_value,
                        'dry_run'    => $dry_run,
                        'meta_key'   => $old_key,
                    ]);

                    if (is_array($result)) {
                        if (!empty($result['saved'])) {

                            if (!isset($summary[$product_id][$slug])) {
                                $summary[$product_id][$slug] = [];
                            }

                            $summary[$product_id][$slug]['status']   = $dry_run ? 'dry-run (external save)' : 'migrated (external save)';
                            $summary[$product_id][$slug]['original'] = $old_value;
                            $summary[$product_id][$slug]['new']      = $result['value'] ?? null;

                            $merged = $result;
                            unset($merged['saved'], $merged['value']);

                            $summary[$product_id][$slug] = array_merge(
                                $summary[$product_id][$slug],
                                $merged
                            );

                            continue;
                        }

                        $transformed = $result['value'] ?? null;
                        $extra = $result;
                        unset($extra['value'], $extra['saved']);
                    } else {
                        $transformed = $result;
                    }
                } else {
                    $transformed = $this->transform_value($old_value, $field, $index, $match_unit);
                }

                if ($transformed === null) {

                    if (!isset($summary[$product_id][$slug])) {
                        $summary[$product_id][$slug] = [];
                    }

                    $summary[$product_id][$slug]['status']   = 'skipped';
                    $summary[$product_id][$slug]['reason']   = 'No valid data';
                    $summary[$product_id][$slug]['original'] = $old_value;

                    continue;
                }

                $current_value = \Luma\ProductFields\Utils\Helpers::get_formatted_field_value(
                    $product_id,
                    $field,
                    false
                );

                if (!isset($summary[$product_id][$slug])) {
                    $summary[$product_id][$slug] = [];
                }

                $summary[$product_id][$slug]['existing'] = $current_value;

                $skip_existing = !empty($config['skip_existing']);
                if ($skip_existing && $current_value !== null && $current_value !== '') {

                    $summary[$product_id][$slug]['status']   = 'skipped';
                    $summary[$product_id][$slug]['reason']   = __('Existing value present', 'luma-product-fields');
                    $summary[$product_id][$slug]['original'] = $old_value;
                    $summary[$product_id][$slug]['new']      = $current_value;

                    continue;
                }

                if (!$dry_run) {
                    FieldStorage::save_field($product_id, $field['slug'], $transformed);
                }

                $summary[$product_id][$slug]['status']   = $dry_run ? 'dry-run' : 'migrated';
                $summary[$product_id][$slug]['original'] = $old_value;
                $summary[$product_id][$slug]['new']      = $transformed;

                if (!empty($extra)) {
                    $summary[$product_id][$slug] = array_merge(
                        $summary[$product_id][$slug],
                        $extra
                    );
                }
            }
        }
    }

    return $summary;
}



    /**
     * Transform a legacy value based on the field definition.
     *
     * This method handles unit-aware matching, numeric extraction,
     * text cleanup, and taxonomy resolution based on the field type.
     *
     * If `$match_unit` is true and a `unit` is defined on the field, the method
     * will attempt to extract the number associated with that unit using
     * suffix pattern matching. If no match is found, it falls back to the
     * `$index`-based extraction (e.g. first number).
     *
     * @param mixed  $value       The original raw legacy value (string or array).
     * @param array  $field       The field definition (type, unit, is_taxonomy, etc.).
     * @param int    $index       The positional index of the number to extract (default 0).
     *                            Use -1 to extract the last number.
     * @param bool   $match_unit  Whether to try extracting a number based on unit suffix.
     * @return mixed|null         The transformed value, or null if invalid or not found.
     */
    protected function transform_value($value, array $field, int $index = 0, bool $match_unit = false)
    {
        if (is_array($value) && $field['type'] !== 'minmax') {
            $value = reset($value);
        }

        if (!empty($field['is_taxonomy'])) {
            return is_array($value) ? null : $this->transform_taxonomy($value, $field);
        }

        // Try matching number based on unit suffix
        if ($match_unit && !empty($field['unit']) && in_array($field['type'], ['number', 'integer'], true)) {
            $unit_value = $this->extract_number_with_unit((string) $value, $field['unit']);
            if ($unit_value !== null) {
                return match ($field['type']) {
                    'integer' => (int) $unit_value,
                    default   => $unit_value,
                };
            }
        }

        // Fallback to positional index
        return match ($field['type']) {
            'minmax'  => $this->transform_minmax($value),
            'number'  => $this->transform_number((string) $value, $index),
            'integer' => $this->transform_integer((string) $value, $index),
            'text'    => $this->transform_text((string) $value),
            default   => $this->transform_text((string) $value),
        };
    }
    
    
    /**
     * Extract the first numeric value that matches a known unit suffix.
     *
     * This method scans the legacy string and tries to locate a number followed
     * by a unit alias (e.g., "50 g", "210 meter"). Unit aliases are defined by
     * `get_unit_aliases()` and may include localized and plural variations.
     *
     * The match is case-insensitive and ignores trailing punctuation (like "g.").
     * Returns the first matching number for any of the defined aliases.
     *
     * Example:
     *   extract_number_with_unit('250 m / 50 g', 'g') => 50.0
     *   extract_number_with_unit('50 g = 210 meter', 'meter') => 210.0
     *
     * @param string $value The legacy input string to extract from.
     * @param string $unit  The normalized unit key (e.g., "g", "meter") as defined in get_units().
     * @return float|null   The numeric value found with matching unit, or null if not found.
     */
    protected function extract_number_with_unit(string $value, string $unit): ?float
    {
        if (empty($unit)) {
            return null;
        }

        $aliases = self::get_unit_aliases()[$unit] ?? [$unit];
        $normalized = str_replace(',', '.', $value);

        foreach ($aliases as $alias) {
            $cleaned_alias = preg_quote(rtrim($alias, '.'), '/');
            $pattern = '/(-?\d+(?:\.\d+)?)\s*' . $cleaned_alias . '\b/iu';
            if (preg_match($pattern, $normalized, $match)) {
                return (float) $match[1];
            }
        }

        return null;
    }


    /**
     * Transform value to float number.
     *
     * @param string $value Raw string value.
     * @param int    $index Index of number to extract (0 = first, 1 = second, -1 = last).
     * @return float|null Parsed float or null.
     */
    protected function transform_number(string $value, int $index = 0): ?float
    {
        $normalized = str_replace(',', '.', $value);
        if (preg_match_all('/-?\d+(?:\.\d+)?/', $normalized, $matches)) {
            $numbers = $matches[0];
            if ($index === -1) {
                $index = count($numbers) - 1;
            }
            if (isset($numbers[$index]) && is_numeric($numbers[$index])) {
                return (float) $numbers[$index];
            }
        }
        return null;
    }


    /**
     * Transform value to integer number.
     *
     * @param string $value Raw string value.
     * @param int    $index Index of number to extract (0 = first, 1 = second, -1 = last).
     * @return int|null Parsed integer or null.
     */
    protected function transform_integer(string $value, int $index = 0): ?int
    {
        if (preg_match_all('/-?\d+/', $value, $matches)) {
            $numbers = $matches[0];

            if ($index === -1) {
                $index = count($numbers) - 1;
            }

            if (isset($numbers[$index])) {
                return (int) $numbers[$index];
            }
        }

        return null;
    }
    
        
    /**
     * Get localized aliases for all known units.
     *
     * This array maps normalized unit keys (matching the output of get_units())
     * to a list of possible suffix aliases that may appear in legacy data.
     *
     * Aliases may include:
     * - Abbreviations (e.g. "g", "m")
     * - Full words (e.g. "meter", "gram")
     * - Plurals (e.g. "grams", "meters")
     * - Localized strings (translated via __())
     *
     *
     * @hook Luma\ProductFields\unit_aliases
     *      Filters the default unit alias map.
     *      @param array<string, string[]> $aliases Default unit aliases.
     *
     * @return array<string, string[]> Array of unit key => aliases
     *
     */
    protected function get_unit_aliases(): array
    {
        $aliases = [
            'cm' => [
                __('cm', 'luma-product-fields'),
                __('centimeter', 'luma-product-fields'),
                __('centimeters', 'luma-product-fields'),
                'cm.',
            ],
            'mm' => [
                __('mm', 'luma-product-fields'),
                __('millimeter', 'luma-product-fields'),
                __('millimeters', 'luma-product-fields'),
                'mm.',
            ],
            'm' => [
                __('m', 'luma-product-fields'),
                __('meter', 'luma-product-fields'),
                __('meters', 'luma-product-fields'),
                'm.',
                'metre', 'metres',
            ],
            'g' => [
                __('g', 'luma-product-fields'),
                __('gram', 'luma-product-fields'),
                __('grams', 'luma-product-fields'),
                'g.', 'gramm',
            ],
            'kg' => [
                __('kg', 'luma-product-fields'),
                __('kilogram', 'luma-product-fields'),
                __('kilograms', 'luma-product-fields'),
                'kilo', 'kilos',
            ],
            'pcs' => [
                __('pcs', 'luma-product-fields'),
                __('pieces', 'luma-product-fields'),
                __('stk', 'luma-product-fields'),
                'st.', 'enheter',
            ],
            'years' => [
                __('year', 'luma-product-fields'),
                __('years', 'luma-product-fields'),
                'yr', 'yrs', 'år', 'år.', 'årer',
            ],
            '%' => [
                '%',
                __('percent', 'luma-product-fields'),
                __('percents', 'luma-product-fields'),
                __('prosent', 'luma-product-fields'),
                __('prosenter', 'luma-product-fields'),
            ],
        ];

    
        /**
         * @hook Luma\ProductFields\unit_aliases
         * Filters the default unit alias map.
         *
         * @param array<string, string[]> $aliases Default unit aliases.
         *
         * @since 1.0.0
         */
        return apply_filters('Luma\ProductFields\unit_aliases', $aliases);
    }






    /**
     * Sanitize text value.
     *
     * @param string $value Raw string.
     * @return string Sanitized string.
     */
    protected function transform_text(string $value): string
    {
        return sanitize_text_field($value);
    }


    /**
     * Transform taxonomy string into term(s).
     *
     * @param string $value Raw string.
     * @param array $field Field definition.
     * @return array|string|null Array of terms, string, or null.
     */
    protected function transform_taxonomy(string $value, array $field): array|string|null
    {
        $value = trim($value);

        $field_type_definition = FieldTypeRegistry::get($field['type']);
        $supports_multiple = !empty($field_type_definition['supports']) && in_array('multiple_values', $field_type_definition['supports'], true);

        if ($supports_multiple) {
            $terms = array_filter(array_map('trim', explode(',', $value)));
            return $terms ?: null;
        }

        return $value !== '' ? $value : null;
    }


    /**
     * Transform a value like "3-5", "3+", or "5" into ['min' => ..., 'max' => ...].
     * Supports messy input like "22-24 m / 10 cm", or "18-22 m = 10 cm".
     *
     * @param string $value Raw legacy value from meta field.
     * @return array{min?: float, max?: float}|null Parsed range or null if no match.
     */
    protected function transform_minmax( $value): ?array
    {
        $value = str_replace(',', '.', $value); // Normalize decimals

        // ✅ First priority: full range match like "22-24" (even embedded)
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*[\-–—]\s*(\d+(?:\.\d+)?)/u', $value, $matches, PREG_SET_ORDER)) {
            $first = $matches[0];
            $a = (float) $first[1];
            $b = (float) $first[2];
            return [
                'min' => min($a, $b),
                'max' => max($a, $b),
            ];
        }

        // ✅ Second: open-ended "3+" or "100–"
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[\+–-]\s*$/u', $value, $match)) {
            return [ 'min' => (float) $match[1] ];
        }

        // ✅ Third: open-started "–100" or "- 100"
        if (preg_match('/^[\+–-]\s*(\d+(?:\.\d+)?)\s*$/u', $value, $match)) {
            return [ 'max' => (float) $match[1] ];
        }

        // ✅ Fourth: single number fallback
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*$/', $value, $match)) {
            $n = (float) $match[1];
            return [ 'min' => $n, 'max' => $n ];
        }

        return null;
    }




}
