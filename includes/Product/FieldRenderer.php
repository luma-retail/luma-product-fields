<?php
/**
 * Admin field renderer class
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Product;

use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Product\FieldStorage;
use Luma\ProductFields\Registry\FieldTypeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Admin field renderer class
 *
 * Handles rendering and saving of custom product fields in the WooCommerce product editor.
 *
 * @hook luma_product_fields_allow_external_field_slug
 *      Filter to allow external plugins to define non-HPF slugs that should
 *      be rendered as simple text fields in the inline editor.
 *      @param bool   $allowed Whether the slug is allowed.
 *      @param string $slug    Requested field slug.
 */
class FieldRenderer {

    /**
     * Add custom WooCommerce product data tab.
     *
     * @param array $tabs Existing product tabs.
     *
     * @return array Modified tabs.
     */
    public function add_product_data_tab( array $tabs ): array {
        $tabs['lpf-product-data'] = [
            'label'    => __( 'Product Fields', 'luma-product-fields' ),
            'target'   => 'lpf_product_data',
            'priority' => 80,
        ];

        return $tabs;
    }


    /**
     * Display custom product data panel content.
     *
     * @return void
     */
    public function display_product_data_fields_content(): void {
        global $post;

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $product_id      = (int) $post->ID;
        $group_slug      = Helpers::get_product_group_slug( $product_id );
        $no_group_label  = __( 'No group set', 'luma-product-fields' );

        // We output complex admin form HTML here. All dynamic pieces inside helpers/renderers
        // (get_product_group_select(), render_form_fields()) are escaped internally.
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div id="lpf_product_data" class="panel woocommerce_options_panel">
            <?php wp_nonce_field( 'lpf_save_product_fields', 'lpf_product_fields_nonce' ); ?>
            <div class="toolbar toolbar-top options-group">
                <p class="form-field">
                    <label><?php esc_html_e( 'Product group', 'luma-product-fields' ); ?></label>
                    <?php
                    echo ( new Admin() )->get_product_group_select(
                        'lpf-product-group-select',
                        $group_slug,
                        $no_group_label
                    );
                    ?>
                </p>
                <div id="lpf-product-group-fields">
                    <?php
                    echo $this->render_form_fields(
                        $group_slug,
                        $product_id
                    );
                    ?>
                </div>
            </div>
        </div>
        <?php
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }


    /**
     * Render all fields for the given product group or "general".
     *
     * "general" = product has no group → show global fields only.
     *
     * @param string $slug    Product group slug or "general".
     * @param int    $post_id Product ID.
     * @return string Rendered HTML.
     */
    public function render_form_fields( string $slug, int $post_id ): string {
        $fields = Helpers::get_fields_for_group( $slug );
        $output = '';

        if ( ! empty( $fields ) ) {
            foreach ( $fields as $field ) {
                $output .= $this->render_field_by_type( $field, $post_id );
            }
        } elseif ( 'general' !== $slug ) {
            $output .= '<p class="form-field">' .
                esc_html__( 'No fields defined yet for this product group', 'luma-product-fields' ) .
            '</p>';
        }

        return $output;
    }

    /**
     * Render a single form field for a given slug and product ID.
     *
     * @param string $slug    Field slug.
     * @param int    $post_id Product ID.
     *
     * @return string Rendered HTML.
     */
    public function render_form_field( string $slug, int $post_id ): string {
        $field = Helpers::get_field_definition_by_slug( $slug );

        if ( ! $field ) {
            /**
             * Allow external plugins to define non-HPF slugs that should
             * be rendered as simple text fields in the inline editor.
             *
             * @filter luma_product_fields_allow_external_field_slug
             *
             * @param bool   $allowed Whether the slug is allowed.
             * @param string $slug    Requested field slug.
             */
            $allowed = apply_filters(
                'luma_product_fields_allow_external_field_slug',
                false,
                $slug
            );

            if ( ! $allowed ) {
                $msg = __FUNCTION__ . ' ' . __( 'Unknown field.', 'luma-product-fields' );

                return '<p class="form-field"><em>' . esc_html( $msg ) . '</em></p>';
            }

            // Minimal external text-field definition.
            $field = [
                'slug'  => $slug,
                'label' => ucfirst( $slug ),
                'type'  => 'text',
                'unit'  => '',
            ];
        }

        return $this->render_field_by_type( $field, $post_id );
    }


    /**
     * Render a product field based on its type.
     *
     * @param array $field   Field definition array.
     * @param int   $post_id Post ID of the product.
     *
     * @return string Rendered HTML for the field.
     */
    protected function render_field_by_type( array $field, int $post_id ): string {
        $type = $field['type'] ?? 'text';

        if ( FieldTypeRegistry::is_core_type( $type ) ) {
            $method = "render_{$type}_field";

            return method_exists( $this, $method )
                ? $this->$method( $field, $post_id )
                : $this->render_text_field( $field, $post_id ); // Fallback for unknown core type.
        }

        $cb = FieldTypeRegistry::get_callback( $type, 'admin_edit_cb' );

        if ( is_callable( $cb ) ) {
            return (string) call_user_func( $cb, $field['slug'], $post_id, $field );
        }

        $msg = __FUNCTION__ . ' ' . __( 'Unsupported field type: ', 'luma-product-fields' );

        return '<p class="form-field"><em>' . esc_html( $msg ) . '</em> ' . esc_html( $type ) . '</p>';
    }
    

    /**
     * Render a text field.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string
     */
    protected function render_text_field( array $field, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $unit_html   = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );
        $label       = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        ob_start();

        echo "<p class='form-field lpf-fieldtype-text'>";
        echo '<label>' . esc_html( $label );
        if ( $tip_html ) {
            echo wp_kses_post( $tip_html );
        }
        echo '</label>';
        echo "<input type='text' name='lpf-" . esc_attr( $field['slug'] ) . "' value='" . esc_attr( $value ) . "' />";
        if ( $unit_html ) {
            echo wp_kses_post( $unit_html );
        }
        echo '</p>';

        return ob_get_clean();
    }
    

    /**
     * Render a localized float (decimal) input field.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string HTML markup for the field.
     */
    protected function render_number_field( array $field, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $unit_html   = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        ob_start();
        ?>
        <p class="form-field lpf-fieldtype-number">
            <label>
                <?php echo esc_html( $field['label'] ?? '' ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <input
                type="text"
                name="lpf-<?php echo esc_attr( $field['slug'] ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                inputmode="decimal"
                pattern="[0-9]+([.,][0-9]+)?"
            />
            <?php echo $unit_html ? wp_kses_post( $unit_html ) : ''; ?>
        </p>
        <?php
        return ob_get_clean();
    }


    /**
     * Render an integer-only input field.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string
     */
    protected function render_integer_field( array $field, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $unit_html   = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        ob_start();
        ?>
        <p class="form-field lpf-fieldtype-integer">
            <label>
                <?php echo esc_html( $field['label'] ?? '' ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <input
                type="text"
                name="lpf-<?php echo esc_attr( $field['slug'] ); ?>"
                value="<?php echo esc_attr( $value ); ?>"
                inputmode="numeric"
                pattern="\d+"
            />
            <?php echo $unit_html ? wp_kses_post( $unit_html ) : ''; ?>
        </p>
        <?php
        return ob_get_clean();
    }


    /**
     * Render a min/max field supporting localized floats.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string
     */
    protected function render_minmax_field( array $field, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $min         = $value['min'] ?? '';
        $max         = $value['max'] ?? '';
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';
        $unit_html   = empty( $field['unit'] ) ? '' : Helpers::get_formatted_unit_html( $field['unit'] );

        ob_start();
        ?>
        <p class="form-field lpf-fieldtype-minmax">
            <label>
                <?php echo esc_html( $field['label'] ?? '' ); ?>
                <?php echo $tip_html ? wp_kses_post( $tip_html ) : ''; ?>
            </label>
            <span class="lpf-minmax-wrapper">
                <span class="label">Min:</span>
                <input
                    type="text"
                    name="lpf-<?php echo esc_attr( $field['slug'] ); ?>[min]"
                    value="<?php echo esc_attr( $min ); ?>"
                    inputmode="decimal"
                    pattern="[0-9]+([.,][0-9]+)?"
                />
                <span style="margin-left:.5em;" class="label">Max:</span>
                <input
                    type="text"
                    name="lpf-<?php echo esc_attr( $field['slug'] ); ?>[max]"
                    value="<?php echo esc_attr( $max ); ?>"
                    inputmode="decimal"
                    pattern="[0-9]+([.,][0-9]+)?"
                />
                <?php echo $unit_html ? wp_kses_post( $unit_html ) : ''; ?>
            </span>
        </p>
        <?php
        return ob_get_clean();
    }
    

    /**
     * Render a select dropdown for single taxonomy term.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string
     */
    protected function render_single_field( array $field, int $post_id ): string {
        $value       = Helpers::get_field_value( $post_id, $field['slug'] );
        $terms       = get_terms(
            [
                'taxonomy'   => $field['slug'],
                'hide_empty' => false,
            ]
        );
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        if ( is_wp_error( $terms ) ) {
            $terms = [];
        }

        $options = array_map(
            static function ( $term ) use ( $value ) {
                return sprintf(
                    "<option value='%s'%s>%s</option>",
                    esc_attr( $term->slug ),
                    selected( $term->slug, $value, false ),
                    esc_html( $term->name )
                );
            },
            $terms
        );

        if ( '' === $value || null === $value ) {
            array_unshift(
                $options,
                sprintf(
                    "<option value='' selected>%s</option>",
                    esc_html__( 'Value not set', 'luma-product-fields' )
                )
            );
        } else {
            array_unshift(
                $options,
                sprintf(
                    "<option value=''>%s</option>",
                    esc_html__( 'Value not set', 'luma-product-fields' )
                )
            );
        }

        return sprintf(
            "<p class='form-field lpf-fieldtype-single'><label>%s%s</label><select name='lpf-%s'>%s</select></p>",
            esc_html( $field['label'] ?? '' ),
            $tip_html ? wp_kses_post( $tip_html ) : '',
            esc_attr( $field['slug'] ),
            implode( '', $options )
        );
    }
    

    /**
     * Render checkboxes for multiple taxonomy terms.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string
     */
    protected function render_multiple_field( array $field, int $post_id ): string {
        $values      = (array) Helpers::get_field_value( $post_id, $field['slug'] );
        $terms       = get_terms(
            [
                'taxonomy'   => $field['slug'],
                'hide_empty' => false,
            ]
        );
        $description = $field['description'] ?? '';
        $tip_html    = $description ? wc_help_tip( $description ) : '';

        if ( is_wp_error( $terms ) ) {
            $terms = [];
        }

        $options = array_map(
            static function ( $term ) use ( $values ) {
                return sprintf(
                    "<span class='label'><input type='checkbox' name='lpf-%s[]' value='%s'%s /> %s</span><br>",
                    esc_attr( $term->taxonomy ),
                    esc_attr( $term->slug ),
                    in_array( $term->slug, $values, true ) ? ' checked' : '',
                    esc_html( $term->name )
                );
            },
            $terms
        );

        return sprintf(
            "<p class='form-field lpf-fieldtype-multiple'><label>%s%s</label>%s</p>",
            esc_html( $field['label'] ?? '' ),
            $tip_html ? wp_kses_post( $tip_html ) : '',
            implode( '', $options )
        );
    }
    

    /**
     * Render an autocomplete taxonomy field input.
     *
     * @param array $field   Field definition.
     * @param int   $post_id Product ID.
     *
     * @return string HTML markup for the field.
     */
    protected function render_autocomplete_field( array $field, int $post_id ): string {
        $slug         = $field['slug'];
        $selected     = wp_get_post_terms(
            $post_id,
            $slug,
            [
                'fields' => 'all',
            ]
        );
        $description  = $field['description'] ?? '';
        $tip_html     = $description ? wc_help_tip( $description ) : '';
        $options_html = '';

        if ( ! is_wp_error( $selected ) ) {
            foreach ( $selected as $term ) {
                $options_html .= sprintf(
                    '<option value="%s" selected="selected">%s</option>',
                    esc_attr( $term->slug ),
                    esc_html( $term->name )
                );
            }
        }

        return sprintf(
            '<p class="form-field lpf-fieldtype-%1$s">
                <label>%2$s %3$s</label>
                <select name="lpf-%4$s[]" multiple="multiple" class="lpf-autocomplete-select" data-taxonomy="%5$s" style="width: 100%%;">%6$s</select>
            </p>',
            esc_attr( $field['type'] ),
            esc_html( $field['label'] ?? '' ),
            $tip_html ? wp_kses_post( $tip_html ) : '',
            esc_attr( $slug ),
            esc_attr( $slug ),
            $options_html // Already escaped above.
        );
    }
    

    /**
     * Save all custom fields for a product when it is saved in the admin.
     *
     * @param int $post_id Product ID.
     * @return void
     */
    public function save_the_fields( int $post_id ): void {

        if (
            ! isset( $_POST['lpf_product_fields_nonce'] )
            || ! wp_verify_nonce( wp_unslash( $_POST['lpf_product_fields_nonce'] ), 'lpf_save_product_fields' )  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $group_slug = isset( $_POST['lpf-product-group-select'] )
            ? sanitize_text_field( wp_unslash( $_POST['lpf-product-group-select'] ) )
            : '';

        $group_term = $group_slug
            ? get_term_by( 'slug', $group_slug, 'lpf_product_group' )
            : null;

        if ( $group_term && ! is_wp_error( $group_term ) ) {
            wp_set_post_terms( $post_id, [ (int) $group_term->term_id ], 'lpf_product_group' );
            $effective_group = $group_term->slug;
        } else {
            wp_set_post_terms( $post_id, [], 'lpf_product_group' );
            $effective_group = 'general';
        }

        $fields = Helpers::get_fields_for_group( $effective_group );

        foreach ( $fields as $field ) {
            $slug = $field['slug'];
            $key  = 'lpf-' . $slug;

            if ( isset( $_POST[ $key ] ) ) {
                $raw_value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                FieldStorage::save_field( $post_id, $slug, $raw_value );
            }
        }
    }

}
