<?php
/**
 * Field Options overview class
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Admin;

use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Meta\MetaManager;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Admin\NotificationManager;

defined('ABSPATH') || exit;


/**
 * Field Options overview class
 * 
 * Displays and manages the global overview of all available fields and their group associations.
 
 
 * @hook Luma\ProductFields\field_manager_actions
 *      Fires after action buttons in field manager page are displayed
 *      Useful for adding extra action buttons
 *
 */
class FieldOptionsOverview
{

    /**
     * Constructor.
     *
     * Registers menu and field deletion handler.
     */
    public function __construct()
    {
        add_action( 'admin_menu', [$this, 'register_menu'] );
        add_action( 'admin_init', [$this, 'maybe_delete_field'] );
    }


    /**
     * Registers the submenu item under the WooCommerce Products menu.
     *
     * @return void
     */
    public function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=product',
            __('Product fields', 'luma-product-fields'),
            __('Product fields', 'luma-product-fields'),
            'manage_woocommerce',
            'lpf-fields',
            [$this, 'render_panel'],
            4
        );
    }


    /**
     * Renders the unified field manager interface.
     *
     * @return void
     */
    public function render_panel(): void
    {
        ?>
        <div class="lpf-admin-panel">
            <h2><?php _e('Product Field Manager', 'luma-product-fields'); ?></h2>
            <?php NotificationManager::render( 'field_editor' ); ?>
            <div class="lpf-filters">
                <form method="GET">
                    <input type="hidden" name="post_type" value="product">
                    <input type="hidden" name="page" value="lpf-fields">
                    <label for="group"><?php _e('Filter product group', 'luma-product-fields'); ?></label>           
                     <?php 
                     echo (new Admin)->get_product_group_select(
                       'group',
                       $_GET['group'] ?? 'all',
                       null,
                       [
                         'include_all'     => true,
                         'include_general' => true,
                         'general_label'  => __('No groups', 'luma-product-fields'),
                       ]
                   );
                   ?>
                    <input type="submit" value="<?php _e('Filter' , 'luma-product-fields'); ?>"> 
                </form>
            </div>

            <?php $this->render_table(); ?>
            
            <div class="lpf-actions">
                <a href="<?php echo admin_url('admin.php?page=lpf-new-field'); ?>" class="button button-primary button-large" style="margin-left: 1em;">
                    <?php _e('Add New Field', 'luma-product-fields'); ?>
                </a>
                
                <a href="<?php echo admin_url('edit-tags.php?taxonomy=lpf_product_group'); ?>" class="button button-large" style="margin-left: 1em;">
                    <?php _e('Edit product groups', 'luma-product-fields'); ?>
                </a>
                                
                <?php do_action( 'Luma\ProductFields\field_manager_actions' ); ?>
                                            
            </div>       
        </div>
        <?php
    }


    /**
     * Renders the admin table containing all fields and their metadata.
     *
     * @return void
     */
    public function render_table(): void
    {
        $group = $_GET['group'] ?? 'all';

        if ($group === 'all') {
            $fields = Helpers::get_all_fields(null); // show everything
        } else {
            $fields = Helpers::get_all_fields($group);
        }
                    
        
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        do_action( 'Luma\ProductFields\FieldOptionsOverview\tableHeadStart' );
        echo '<th>' . __('Label', 'luma-product-fields') . '</th>' .
             '<th>' . __('Slug', 'luma-product-fields') . '</th>' .
             '<th>' . __('Type', 'luma-product-fields') . '</th>' .            
             '<th>' . __('Product Groups', 'luma-product-fields') . '</th>' .
             '<th>' . __('Frontend', 'luma-product-fields') . '</th>' .
             '<th>' . __('Variation', 'luma-product-fields') . '</th>' .
             '<th>' . __('Actions', 'luma-product-fields') . '</th>' .
             '</tr></thead><tbody>';

        foreach ($fields as $key => $field) {
            $is_tax = $field['is_taxonomy'] ?? false;
            $edit_url = admin_url('admin.php?page=lpf-new-field&edit=' . urlencode($field['slug']));
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=lpf-fields&lpf_delete_field=' . urlencode($field['slug'])),
                'lpf_delete_field_' . $field['slug']
            );
            $manage_terms_url = $is_tax ? admin_url('edit-tags.php?post_type=product&taxonomy=' . urlencode($field['slug'])) : '';

            echo '<tr data-slug="' . esc_attr( $field['slug'] ) . '">';
            do_action( 'Luma\ProductFields\FieldOptionsOverview\tableRowStart', esc_attr( $field['slug']) );
            echo '<td>' . esc_html($field['label']) . '</td>';
            echo '<td><code>' . esc_html($field['slug']) . '</code></td>';
            echo '<td>' . esc_html(\Luma\ProductFields\Registry\FieldTypeRegistry::get_field_type_label($field['type'] ?? '')) . '</td>';
            echo '<td>' . implode(', ', array_map('esc_html', $field['groups'] ?? ['general'])) . '</td>';
            echo '<td>' . ($field['hide_in_frontend'] ? __('Hidden', 'luma-product-fields') : __('Visible', 'luma-product-fields')) . '</td>';
            echo '<td>' . ($field['variation'] ? __('Yes', 'luma-product-fields') : __('No', 'luma-product-fields')) . '</td>';
            echo '<td><a class="button" href="' . esc_url($edit_url) . '">' . __('Edit', 'luma-product-fields') . '</a>';
            if ($is_tax) {
                echo '<a class="button" style="margin-left: 0.5em;" href="' . esc_url($manage_terms_url) . '">' . __('Manage Terms', 'luma-product-fields') . '</a>';
            }
            echo '<a class="button" 
                    href="' . esc_url($delete_url) . '" 
                    style="margin-left: 0.5em; color: darkred;" 
                    onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this field? All data will be deleted, and there is no going back.', 'luma-product-fields')) . '\');"
                >' . __('Delete', 'luma-product-fields') . '</a>';                                    
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
    public function maybe_delete_field(): void
    {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $slug = sanitize_title( $_GET['lpf_delete_field'] ?? '' );

        if ( ! $slug || ! check_admin_referer( 'lpf_delete_field_' . $slug ) ) {
            return;
        }


        if ( Helpers::is_taxonomy_field( $slug ) ) {
            TaxonomyManager::delete_field( $slug, true );
        } 
        else {
            MetaManager::delete_field( $slug, true );
        }

        NotificationManager::add_notice([
            'type'    => 'success',
            'message' => __( 'Field deleted successfully. All associated data has been removed.', 'luma-product-fields' ),
            'context' => 'field_editor',
        ]);

        wp_safe_redirect( admin_url( 'admin.php?page=lpf-fields' ) );
        exit;
    }


}
