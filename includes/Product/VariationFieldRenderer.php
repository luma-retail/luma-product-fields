<?php
/**
 * Admin field renderer class for variations
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Product;

use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Product\FieldStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles rendering and saving of custom product fields in variation edit view.
 */
class VariationFieldRenderer {


    /**
     * Render custom fields in each variation tab.
     *
     * @param int     $loop           Loop index.
     * @param array   $variation_data Variation data array.
     * @param \WP_Post $post          Variation post object.
     */
    public function render_variation_fields( $loop, $variation_data, $post ): void {
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $product_id = (int) $post->post_parent;
        if ( ! $product_id ) {
            return;
        }

        $group_slug = Helpers::get_product_group_slug( $product_id );

        // This method is a template renderer for the variation admin UI.
        // It delegates escaping to internal renderers (render_field_by_type and helpers).
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<fieldset class="lpf-variation-fields">';

        foreach ( Helpers::get_fields_for_group( $group_slug ) as $field ) {
            if ( empty( $field['variation'] ) ) {
                continue;
            }

            if ( ! FieldTypeRegistry::supports( $field['type'] ?? '', 'variations' ) ) {
                continue;
            }

            echo $this->render_field_by_type( $field, (int) $loop, (int) $post->ID );
        }

        echo '</fieldset>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }


    /**
     * Render a variation field based on its type.
     *
     * @param array $field    Field definition.
     * @param int   $loop     Index of the variation in the loop.
     * @param int   $post_id  Post ID of the variation.
     *
     * @return string Rendered HTML for the variation field.
     */
    protected function render_field_by_type( array $field, int $loop, int $post_id ): string {
        $type = $field['type'] ?? 'text';

        if ( FieldTypeRegistry::is_core_type( $type ) ) {
            $method = "render_{$type}_field";

            return method_exists( $this, $method )
                ? $this->$method( $field, $loop, $post_id )
                : $this->render_text_field( $field, $loop, $post_id );
        }

        $cb = FieldTypeRegistry::get_callback( $type, 'admin_render_variation_callback' );

        if ( is_callable( $cb ) ) {
            return (string) call_user_func( $cb, $field['slug'], $loop, $post_id, $field );
        }

        return '<p class="form-row"><em>' . esc_html__( 'Unsupported variation field type.', 'luma-product-fields' ) . '</em></p>';
    }


    /**
     * Render a text field for variation.
     *
     * @param array $field Field definition.
     * @param int   $loop  Variation index.
     * @param int   $post_id Variation post ID.
     * @return string
     */
    protected function render_text_field( array $field, int $loop, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $name_attr   = 'variable_' . $field['slug'] . '[' . $loop . ']';
        $unit        = $field['unit'] ?? '';
        $label       = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        ob_start();
        ?>
        <p class="form-row lpf-fieldtype-text">
            <label>
                <?php echo esc_html( $label ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <input
                type="text"
                name="<?php echo esc_attr( $name_attr ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
            />
            <?php echo $unit ? ' ' . esc_html( $unit ) : ''; ?>
        </p>
        <?php
        $html = ob_get_clean();
        return $html;
    }


    /**
     * Render a number field for variation (locale-aware float).
     *
     * @param array $field Field definition.
     * @param int   $loop  Variation index.
     * @param int   $post_id Variation post ID.
     * @return string
     */
    protected function render_number_field( array $field, int $loop, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $name_attr   = 'variable_' . $field['slug'] . '[' . $loop . ']';
        $unit        = $field['unit'] ?? '';
        $label       = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        ob_start();
        ?>
        <p class="form-row lpf-fieldtype-number">
            <label>
                <?php echo esc_html( $label ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <input
                type="text"
                name="<?php echo esc_attr( $name_attr ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                inputmode="decimal"
                pattern="[0-9]+([.,][0-9]+)?"
            />
            <?php echo $unit ? ' ' . esc_html( $unit ) : ''; ?>
        </p>
        <?php
        $html = ob_get_clean();
        return $html;
    }


    /**
     * Render an integer field for variation.
     *
     * @param array $field Field definition.
     * @param int   $loop  Variation index.
     * @param int   $post_id Variation post ID.
     * @return string
     */
    protected function render_integer_field( array $field, int $loop, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $name_attr   = 'variable_' . $field['slug'] . '[' . $loop . ']';
        $unit        = $field['unit'] ?? '';
        $label       = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        ob_start();
        ?>
        <p class="form-row lpf-fieldtype-integer">
            <label>
                <?php echo esc_html( $label ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <input
                type="text"
                name="<?php echo esc_attr( $name_attr ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                inputmode="numeric"
                pattern="\d+"
            />
            <?php echo $unit ? ' ' . esc_html( $unit ) : ''; ?>
        </p>
        <?php
        $html = ob_get_clean();
        return $html;
    }


    /**
     * Render a min/max field for variation.
     *
     * @param array $field Field definition.
     * @param int   $loop  Variation index.
     * @param int   $post_id Variation post ID.
     * @return string
     */
    protected function render_minmax_field( array $field, int $loop, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $min         = $value['min'] ?? '';
        $max         = $value['max'] ?? '';
        $unit        = $field['unit'] ?? '';
        $label       = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        $base_name = 'variable_' . $field['slug'] . '[' . $loop . ']';

        ob_start();
        ?>
        <p class="form-row lpf-fieldtype-minmax">
            <label>
                <?php echo esc_html( $label ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <span class="lpf-minmax-wrapper">
                <span class="label"><?php esc_html_e( 'Min:', 'luma-product-fields' ); ?></span>
                <input
                    type="text"
                    name="<?php echo esc_attr( $base_name . '[min]' ); ?>"
                    value="<?php echo esc_attr( $min ); ?>"
                    inputmode="decimal"
                    pattern="[0-9]+([.,][0-9]+)?"
                />
                <span style="margin-left:.5em;" class="label"><?php esc_html_e( 'Max:', 'luma-product-fields' ); ?></span>
                <input
                    type="text"
                    name="<?php echo esc_attr( $base_name . '[max]' ); ?>"
                    value="<?php echo esc_attr( $max ); ?>"
                    inputmode="decimal"
                    pattern="[0-9]+([.,][0-9]+)?"
                />
                <?php echo $unit ? ' ' . esc_html( $unit ) : ''; ?>
            </span>
        </p>
        <?php
        $html = ob_get_clean();
        return $html;
    }


    /**
     * Render a single-select taxonomy field for a variation.
     *
     * @param array $field Field definition.
     * @param int   $loop  Index of the variation in the loop.
     * @param int   $post_id Variation post ID.
     * @return string
     */
    protected function render_single_field( array $field, int $loop, int $post_id ): string {
        $value      = Helpers::get_field_value( $post_id, $field['slug'] );
        $terms      = get_terms(
            [
                'taxonomy'   => $field['slug'],
                'hide_empty' => false,
            ]
        );
        $desc_tip   = ! empty( $field['description'] );
        $label      = $field['label'] ?? '';
        $desc       = $field['description'] ?? '';
        $field_id   = "variable_{$field['slug']}[$loop]";
        $options    = [ '' => __( 'Select', 'luma-product-fields' ) ];

        if ( ! is_wp_error( $terms ) ) {
            $options += wp_list_pluck( $terms, 'name', 'slug' );
        }

        ob_start();
        // woocommerce_wp_select() echoes full HTML, escaping internally.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        woocommerce_wp_select(
            [
                'id'            => $field_id,
                'label'         => $label,
                'wrapper_class' => 'form-row form-row-full',
                'options'       => $options,
                'value'         => $value,
                'desc_tip'      => $desc_tip,
                'description'   => $desc,
            ]
        );
        $html = ob_get_clean();
        return $html;
    }


    /**
     * Save custom field values for a product variation.
     *
     * @param int $variation_id ID of the variation being saved.
     * @param int $loop         Index of the variation in the variations loop.
     * @return void
     */
    public function save_the_fields( int $variation_id, int $loop ): void {
        $product   = wc_get_product( $variation_id );
        $parent_id = $product ? $product->get_parent_id() : 0;

        if ( ! $parent_id ) {
            return;
        }

        $parent_group_slug = Helpers::get_product_group_slug( $parent_id ) ?: 'general';
        $fields            = Helpers::get_fields_for_group( $parent_group_slug );

        foreach ( $fields as $field ) {
            $type = $field['type'] ?? 'text';

            if ( ! FieldTypeRegistry::supports( $type, 'variations' ) ) {
                continue;
            }

            $slug       = $field['slug'];
            $input_name = 'variable_' . $slug;

            if ( isset( $_POST[ $input_name ][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing	               
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
                $raw_value = wp_unslash( $_POST[ $input_name ][ $loop ] );
                FieldStorage::save_field( $variation_id, $slug, $raw_value );
            }
        }
    }

}
