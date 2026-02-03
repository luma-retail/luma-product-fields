<?php
/**
 * Field Options overview class
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Meta\MetaManager;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Taxonomy\ProductGroup;

defined( 'ABSPATH' ) || exit;

/**
 * Field Options overview class.
 *
 * Displays and manages the global overview of all available fields and their group associations.
 *
 * @hook luma_product_fields_field_manager_actions
 *      Fires after action buttons in field manager page are displayed.
 *      Useful for adding extra action buttons.
 */
class FieldOptionsOverview {

    /**
     * Constructor.
     *
     * Registers menu and field deletion handler.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'maybe_delete_field' ] );
    }

    /**
     * Registers the submenu item under the WooCommerce Products menu.
     *
     * @return void
     */
    public function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=product',
            __( 'Product fields', 'luma-product-fields' ),
            __( 'Product fields', 'luma-product-fields' ),
            'manage_woocommerce',
            'luma-product-fields',
            [ $this, 'render_panel' ],
            4
        );
    }

    /**
     * Renders the unified field manager interface.
     *
     * @return void
     */
    public function render_panel(): void {
        $selected_group = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="luma-product-fields-admin-panel">
            <h2><?php esc_html_e( 'Product Field Manager', 'luma-product-fields' ); ?></h2>
            <?php NotificationManager::render( 'field_editor' ); ?>
            <div class="luma-product-fields-filters">
                <form method="get">
                    <input type="hidden" name="post_type" value="product" />
                    <input type="hidden" name="page" value="luma-product-fields" />
                    <label for="group"><?php esc_html_e( 'Filter product group', 'luma-product-fields' ); ?></label>
                    <?php
                    $args = array(
                        'include_all'     => true,
                        'include_general' => true,
                        'general_label'   => __( 'No groups', 'luma-product-fields' ),
                    );

                    $select_html = ( new Admin() )->get_product_group_select( 'group', $selected_group, null, $args );
                    echo wp_kses( $select_html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );
                    ?>
                    <input type="submit" value="<?php echo esc_attr__( 'Filter', 'luma-product-fields' ); ?>" />
                </form>
            </div>

            <?php $this->render_table(); ?>

            <div class="lumaprfi-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=luma-product-fields-edit' ) ); ?>" class="button button-primary button-large" style="margin-left: 1em;">
                    <span class="dashicons dashicons-plus-alt"></span><?php esc_html_e( 'Add New Field', 'luma-product-fields' ); ?>
                </a>

                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . ProductGroup::$tax_name ) ); ?>" class="button button-large" style="margin-left: 1em;">
                    <?php esc_html_e( 'Edit product groups', 'luma-product-fields' ); ?>
                </a>

                <?php do_action( 'luma_product_fields_field_manager_actions' ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders the admin table containing all fields and their metadata.
     *
     * @return void
     */
    public function render_table(): void {
        $selected_group = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'all' === $selected_group ) {
            $fields = Helpers::get_all_fields( null ); // Show everything.
        } else {
            $fields = Helpers::get_all_fields( $selected_group );
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        // Hook name (documented) with access to the full field list.
        do_action( 'luma_product_fields_field_options_overview_table_head_start', $fields );
        echo '<th>' . esc_html__( 'Label', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Slug', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Product Groups', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Frontend', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Variation', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'luma-product-fields' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $fields as $field ) {
            $is_taxonomy      = $field['is_taxonomy'] ?? false;
            $slug             = $field['slug'] ?? '';
            $label            = $field['label'] ?? '';
            $groups           = $field['groups'] ?? [ 'general' ];
            $hide_in_frontend = ! empty( $field['hide_in_frontend'] );
            $variation        = ! empty( $field['variation'] );

            $edit_url   = admin_url( 'admin.php?page=luma-product-fields-edit&edit=' . urlencode( $slug ) );
            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=luma-product-fields&luma_product_fields_delete_field=' . urlencode( $slug ) ),
                'luma_product_fields_delete_field_' . $slug
            );

            $manage_terms_url = $is_taxonomy
                ? admin_url( 'edit-tags.php?post_type=product&taxonomy=' . urlencode( $slug ) )
                : '';

            echo '<tr data-slug="' . esc_attr( $slug ) . '">';
            do_action( 'luma_product_fields_field_options_overview_table_row_start', $slug );

            echo '<td>' . esc_html( $label ) . '</td>';
            echo '<td><code>' . esc_html( $slug ) . '</code></td>';
            echo '<td>' . esc_html( \Luma\ProductFields\Registry\FieldTypeRegistry::get_field_type_label( $field['type'] ?? '' ) ) . '</td>';

            echo '<td>' . implode( ', ', array_map( 'esc_html', $groups ) ) . '</td>';

            echo '<td>' . ( $hide_in_frontend ? esc_html__( 'Hidden', 'luma-product-fields' ) : esc_html__( 'Visible', 'luma-product-fields' ) ) . '</td>';
            echo '<td>' . ( $variation ? esc_html__( 'Yes', 'luma-product-fields' ) : esc_html__( 'No', 'luma-product-fields' ) ) . '</td>';

            echo '<td>';
            echo '<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'luma-product-fields' ) . '</a>';

            if ( $is_taxonomy && $manage_terms_url ) {
                echo '<a class="button" style="margin-left: 0.5em;" href="' . esc_url( $manage_terms_url ) . '">';
                esc_html_e( 'Manage Terms', 'luma-product-fields' );
                echo '</a>';
            }

            $confirm_message = __( 'Are you sure you want to delete this field? All data will be deleted, and there is no going back.', 'luma-product-fields' );

            echo '<a class="button" href="' . esc_url( $delete_url ) . '" style="margin-left: 0.5em; color: darkred;" onclick="return confirm(\'' . esc_js( $confirm_message ) . '\');">';
            echo esc_html__( 'Delete', 'luma-product-fields' );
            echo '</a>';

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Deletes a field if requested via GET param and user has permissions.
     *
     * @return void
     */
    public function maybe_delete_field(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $slug = isset( $_GET['luma_product_fields_delete_field'] ) ? sanitize_title( wp_unslash( $_GET['luma_product_fields_delete_field'] ) ) : '';

        if ( ! $slug || ! check_admin_referer( 'luma_product_fields_delete_field_' . $slug ) ) {
            return;
        }

        if ( Helpers::is_taxonomy_field( $slug ) ) {
            TaxonomyManager::delete_field( $slug, true );
        } else {
            MetaManager::delete_field( $slug, true );
        }

        NotificationManager::add_notice(
            [
                'type'    => 'success',
                'message' => __( 'Field deleted successfully. All associated data has been removed.', 'luma-product-fields' ),
                'context' => 'field_editor',
            ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=luma-product-fields' ) );
        exit;
    }
}
