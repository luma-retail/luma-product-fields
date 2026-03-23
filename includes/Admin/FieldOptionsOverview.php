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
     * Field manager page slug (admin.php?page=...).
     */
    public const PAGE_SLUG = 'luma-product-fields';

    /**
     * Field manager screen ID for get_current_screen().
     */
    public const SCREEN_ID = 'product_page_luma-product-fields';

    /**
     * Number of fields where grouping into separate spec tables is recommended.
     */
    public const GROUPING_RECOMMENDATION_THRESHOLD = 10;

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
            self::PAGE_SLUG,
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
        $selected_group = $this->get_selected_group_filter();
        $available_groups = ProductGroup::get_product_groups();
        $all_fields_count = count( Helpers::get_all_fields( null ) );
        $threshold_reached = $all_fields_count > self::GROUPING_RECOMMENDATION_THRESHOLD;
        $group_count = count( $available_groups );
        $show_grouping_warning = $threshold_reached && 0 === $group_count;
        ?>
        <div class="luma-product-fields-admin-panel">
            <h2><?php esc_html_e( 'Product Field Manager', 'luma-product-fields' ); ?></h2>
            <?php NotificationManager::render( 'field_editor' ); ?>
            <?php if ( ! empty( $available_groups ) ) : ?>
                <div class="luma-product-fields-filters">
                    <form method="get">
                        <input type="hidden" name="post_type" value="product" />
                        <input type="hidden" name="page" value="luma-product-fields" />
                        <?php wp_nonce_field( 'luma_product_fields_overview_filter', 'luma_product_fields_overview_nonce', false ); ?>
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
            <?php else : ?>
                <?php if ( ! $threshold_reached ) : ?>
                    <p class="description">
                        <?php
                        /* translators: %s: opening and closing anchor tags around "Create product groups". */
                        $create_groups_text = __( 'No Product groups exist yet. %s to organize fields into separate spec tables.', 'luma-product-fields' );
                        printf(
                            wp_kses(
                                $create_groups_text,
                                wp_kses_allowed_html( 'post' )
                            ),
                            '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . ProductGroup::$tax_name ) ) . '">' . esc_html__( 'Create product groups', 'luma-product-fields' ) . '</a>'
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( $show_grouping_warning ) : ?>
                <div class="notice notice-warning inline">
                    <h2><?php esc_html_e( 'Time to consider product groups', 'luma-product-fields' ); ?></h2>
                    <p>
                        <?php
                        /* translators: %s: opening and closing anchor tags around "Edit product groups now". */
                        $grouping_warning = __( 'You now have many fields, so consider using Product groups to keep specs manageable. %s.', 'luma-product-fields' );
                        printf(
                            wp_kses( $grouping_warning, wp_kses_allowed_html( 'post' ) ),
                            '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . ProductGroup::$tax_name ) ) . '"><strong>' . esc_html__( 'Edit product groups now', 'luma-product-fields' ) . '</strong></a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php $this->render_table(); ?>

            <div class="lumaprfi-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=luma-product-fields-edit' ) ); ?>" class="button button-primary button-large" style="margin-left: 1em;">
                    <span class="dashicons dashicons-plus-alt"></span><?php esc_html_e( 'Add New Field', 'luma-product-fields' ); ?>
                </a>

                <?php
                $highlight_groups_cta = false;

                if ( $show_grouping_warning ) {
                    $highlight_groups_cta = true;
                }

                $groups_button_classes = 'button button-large' . ( $highlight_groups_cta ? ' button-primary' : '' );
                ?>
                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . ProductGroup::$tax_name ) ); ?>" class="<?php echo esc_attr( $groups_button_classes ); ?>" style="margin-left: 1em;">
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
        $selected_group = $this->get_selected_group_filter();
        $available_groups = ProductGroup::get_product_groups();

        if ( 'all' === $selected_group ) {
            $fields = Helpers::get_all_fields( null ); // Show everything.
        } else {
            $fields = Helpers::get_all_fields( $selected_group );
        }

        echo '<table class="widefat striped lumaprfi-fields-options-overview">';
        echo '<thead><tr>';
        // Hook name (documented) with access to the full field list.
        do_action( 'luma_product_fields_field_options_overview_table_head_start', $fields );
        echo '<th>' . esc_html__( 'Label', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Product Groups', 'luma-product-fields' ) . '</th>';
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

            $row_class_name = $hide_in_frontend ? 'lumaprfi-frontend-hidden-row' : '';
            echo '<tr data-slug="' . esc_attr( $slug ) . '"' . ( '' !== $row_class_name ? ' class="' . esc_attr( $row_class_name ) . '"' : '' ) . '>';
            do_action( 'luma_product_fields_field_options_overview_table_row_start', $slug );

            $label_classes = 'lumaprfi-field-label';
            if ( $hide_in_frontend ) {
                $label_classes .= ' lumaprfi-frontend-hidden';
            }

            echo '<td class="' . esc_attr( $label_classes ) . '"' . ( $hide_in_frontend ? ' title="' . esc_attr__( 'Not shown in front end', 'luma-product-fields' ) . '"' : '' ) . '><span class="lumaprfi-field-label-text">' . esc_html( $label ) . '</span></td>';
            echo '<td>' . esc_html( \Luma\ProductFields\Registry\FieldTypeRegistry::get_field_type_label( $field['type'] ?? '' ) ) . '</td>';

            $group_labels = array_map(
                static function ( $group_slug ) use ( $available_groups ) {
                    $group_slug = sanitize_key( (string) $group_slug );

                    if ( isset( $available_groups[ $group_slug ] ) ) {
                        return (string) $available_groups[ $group_slug ];
                    }

                    if ( 'general' === $group_slug ) {
                        return __( 'No group', 'luma-product-fields' );
                    }

                    return (string) $group_slug;
                },
                is_array( $groups ) ? $groups : [ $groups ]
            );

            echo '<td>' . implode( ', ', array_map( 'esc_html', $group_labels ) ) . '</td>';
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
     * Read the selected group from the overview filter form.
     *
     * @return string
     */
    private function get_selected_group_filter(): string {
        if ( ! isset( $_GET['group'] ) ) {
            return 'all';
        }

        $nonce = isset( $_GET['luma_product_fields_overview_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['luma_product_fields_overview_nonce'] ) )
            : '';

        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'luma_product_fields_overview_filter' ) ) {
            return 'all';
        }

        return sanitize_text_field( wp_unslash( (string) $_GET['group'] ) );
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
