<?php
/**
 * Admin interface for editing a single product field
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

use Luma\ProductFields\Taxonomy\TaxonomyManager;
use Luma\ProductFields\Meta\MetaManager;
use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Registry\FieldTypeRegistry;
use Luma\ProductFields\Admin\NotificationManager;
use Luma\ProductFields\Taxonomy\ProductGroup;
use Luma\ProductFields\Utils\CacheInvalidator;
use Luma\ProductFields\Utils\Helpers;

defined( 'ABSPATH' ) || exit;

 /**
 * Field Editor class
 *
 * Manages the admin UI for editing field definitions (name, type, visibility, etc.).
 *
 * @hook luma_product_fields_field_editor_after_label
 *      Fires directly below label input in the field editor.
 *      To add additional data to the field editor.
 *      @param array $field 
 *
 * @hook luma_product_fields_field_editor_form_bottom
 *      Fires at the bottom of the form, inside the table and before the submit button.
 *      To add additional data to the field editor.
 *      @param array $field 
 *
 * @hook luma_product_fields_field_saved
 *       Fires after a field has been saved (created or updated).
 *       @param array  $data          The field data that was sanitized and saved.
 *       @param string $action        The action performed: 'created' or 'updated'.
 *       @param bool   $is_tax        Whether the field is taxonomy-backed.
 *       @param string $original_slug The original slug when editing, or empty when creating.
 *
 * @hook luma_product_fields_field_editor_success_message
 *      Filters the success message shown after a field is created or updated.
 *      @param string $message  The success message to be displayed.
 *      @param string $action   The action performed: 'created' or 'updated'.
 *      @param array  $data     The field data that was saved.
 *      @param bool   $is_tax   Whether the field is a taxonomy field.
 *      @return string Filtered success message.
 */
class FieldEditor {

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_editor_page']);
        add_action('admin_post_luma_product_fields_save_field_editor', [$this, 'handle_save']);
    }


    /**
     * Registers the hidden submenu page for editing fields.
     *
     * This page is intended to be reached via a direct link from the overview page,
     * not via the admin menu UI.
     *
     * @return void
     */
    public function register_editor_page(): void {

        $parent_slug = 'edit.php?post_type=product';
        $menu_slug   =  'luma-product-fields-edit';

        add_submenu_page(
            $parent_slug,
            __( 'Edit Field', 'luma-product-fields' ),
            __( 'Edit Field', 'luma-product-fields' ),
            'manage_woocommerce',
            $menu_slug,
            [ $this, 'render_editor' ]
        );

        // Hide it from the submenu UI while keeping the page accessible via direct URL.
//        remove_submenu_page( $parent_slug, $menu_slug );
    }


    /**
     * Render the field editor screen.
     *
     * @return void
     */
    public function render_editor(): void
    { 

        $slug = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $field          = MetaManager::get_field( $slug ) ?? TaxonomyManager::get_field( $slug );
        $field = is_array( $field ) ? $field : [];
        $field_defaults = [
            'type'             => '',
            'label'            => '',
            'schema_prop'      => '',
            'description'      => '',
            'frontend_desc'    => '',
            'unit'             => '',
            'groups'           => [ 'general' ],
            'hide_in_frontend' => false,
            'variation'        => false,
            'show_links'       => false,
        ];

        $field = wp_parse_args( $field, $field_defaults );

        $types              = FieldTypeRegistry::get_all();
        $current_type       = $field['type'] ?? null;
        $supports_unit      = $current_type ? FieldTypeRegistry::supports( $current_type, 'unit' ) : false;
        $supports_links     = $current_type ? FieldTypeRegistry::supports( $current_type, 'link' ) : false;
        $supports_variation = $current_type ? FieldTypeRegistry::supports( $current_type, 'variations' ) : false;

        $unit_row_class       = 'field-unit-row' . ( $supports_unit ? '' : ' hidden' );
        $links_row_class      = 'field-show-tax-links-row' . ( $supports_links ? '' : ' hidden' );
        $variations_row_class = 'field-variations-row' . ( $supports_variation ? '' : ' hidden' );

        $types_desc = '<ul class="luma-product-fields-types-desc">';
        foreach ( $types as $type_slug => $type ) {
            $types_desc .= sprintf(
                '<li id="luma-product-fields-type-%1$s" data-type="%1$s"><strong>%2$s:</strong> %3$s</li>',
                esc_attr( $type_slug ),
                esc_html( $type['label'] ),
                esc_html( $type['description'] )
            );
        }
        $types_desc .= '</ul>';

        Admin::show_back_button();

        echo '<div class="wrap"><h1>';
        echo esc_html(
            $slug
                ? __( 'Edit Field', 'luma-product-fields' )
                : __( 'Add New Field', 'luma-product-fields' )
        );
        echo '</h1>';
        NotificationManager::render( 'field_editor' ); 

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="luma-product-fields-field-editor">';
        echo '<input type="hidden" name="action" value="luma_product_fields_save_field_editor" />';
        wp_nonce_field( 'luma_product_fields_save_field_editor', 'luma_product_fields_nonce' );
        if ( $slug ) {
            echo '<input type="hidden" name="lrpf_original_slug" value="' . esc_attr( $slug ) . '" />';
        }

        echo '<table class="form-table">';

        // Type.
        echo '<tr><th><label>' . esc_html__( 'Type', 'luma-product-fields' ) . '</label></th>';
        echo '<td><div class="field-types">';
        echo '<select name="lrpf_type" id="luma_product_fields_fields_type_selector">';
        foreach ( $types as $type_slug => $info ) {
            echo '<option value="' . esc_attr( $type_slug ) . '"' . selected( $field['type'] ?? '', $type_slug, false ) . '>' . esc_html( $info['label'] ) . '</option>';
        }
        echo '</select>';

        echo wp_kses_post( $types_desc );
        echo '</div></td></tr>';

        // Label.
        echo '<tr><th><label>' . esc_html__( 'Label', 'luma-product-fields' ) . '</label></th>';
        echo '<td><input name="lrpf_label" type="text" value="' . esc_attr( $field['label'] ?? '' ) . '" class="regular-text" /></td></tr>';

        do_action( 'luma_product_fields_field_editor_after_label', $field );

        // Tooltip (front end).
        echo '<tr><th><label>' . esc_html__( 'Tooltip (front end)', 'luma-product-fields' ) . '</label></th>';
        echo '<td>';

        wp_editor(
            $field['frontend_desc'],
            'luma_product_fields_fields_frontend_desc',
            [
                'textarea_rows' => 5,
                'media_buttons' => false,
                'tinymce'       => [
                    'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4;Heading 5=h5',
                    'toolbar1'      => 'formatselect,bold,italic,underline,bullist,numlist,alignleft,aligncenter,alignright,alignjustify',
                    'toolbar2'      => 'forecolor,link,unlink,removeformat,undo,redo',
                ],
            ]
        );
        echo '<p>' . esc_html__( 'A tooltip that pops up by the label on the frontend. Just leave this field empty to omit.', 'luma-product-fields' ) . '</p>';
        echo '</td></tr>';

        // Tooltip (admin).
        echo '<tr><th><label>' . esc_html__( 'Tooltip (for admin)', 'luma-product-fields' ) . '</label></th>';
        echo '<td><textarea name="lrpf_description" rows="3" class="large-text">';
        echo esc_textarea( (string) ( $field['description'] ?? '' ) );
        echo '</textarea>';
        echo '<p>' . esc_html__( 'A tooltip for the shop manager to better understand what to do. Just leave this field empty to omit.', 'luma-product-fields' ) . '</p>';
        echo '</td></tr>';

        // Unit.
        echo '<tr class="' . esc_attr( $unit_row_class ) . '"><th><label>' . esc_html__( 'Unit', 'luma-product-fields' ) . '</label></th>';
        echo '<td><select name="lrpf_unit]">';
        echo '<option value="">' . esc_html__( 'None', 'luma-product-fields' ) . '</option>';

        foreach ( FieldTypeRegistry::get_units() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $field['unit'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';

        // Product groups.
        echo '<tr><th><label>' . esc_html__( 'Product Groups', 'luma-product-fields' ) . '</label></th>';
        echo '<td>';
        $html = ( new Admin() )->get_product_group_checkboxes( 'lrpf_groups', $field['groups'] ?? [ 'general' ] );
        echo wp_kses( $html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );
        echo '<p>' . esc_html__( 'Leave empty to show across all products', 'luma-product-fields' ) . '</p>';
        echo '</td></tr>';

        // Frontend visibility.
        echo '<tr><th><label>' . esc_html__( 'Frontend Visibility', 'luma-product-fields' ) . '</label></th>';
        echo '<td><label><input type="checkbox" name="lrpf_hide_in_frontend" value="1"' . checked( $field['hide_in_frontend'] ?? false, true, false ) . ' /> ';
        echo esc_html__( 'Hide in frontend', 'luma-product-fields' ) . '</label></td></tr>';

        // Variations.
        echo '<tr class="' . esc_attr( $variations_row_class ) . '"><th><label>' . esc_html__( 'Use in variations', 'luma-product-fields' ) . '</label></th>';
        echo '<td><label><input type="checkbox" name="lrpf_variation" value="1"' . checked( $field['variation'] ?? false, true, false ) . ' /> ';
        echo esc_html__( 'Yes', 'luma-product-fields' ) . '</label></td></tr>';

        // Schema property.
        echo '<tr>';
        echo '<th><label for="lrpf_schema_prop">' . esc_html__( 'Schema Property', 'luma-product-fields' ) . '</label></th>';
        echo '<td>';
        echo '<input id="lrpf_schema_prop" name="lrpf_schema_prop" type="text" value="' . esc_attr( $field['schema_prop'] ?? '' ) . '" class="regular-text"/>';
        echo '<p class="description">';
        echo esc_html__(
            'Schema Property controls how the value is included in your product’s structured data (schema.org). Adding a valid schema property (e.g. weight, brand, material) helps Google and other search engines better understand your product, which may improve how it appears in search results.',
            'luma-product-fields'
        );
        echo ' ';
        echo '<a href="' . esc_url( 'https://schema.org/Product' ) . '" target="_blank" rel="noopener noreferrer">';
        echo esc_html__( 'See list of available schema.org properties for products', 'luma-product-fields' );
        echo '</a></p>';
        echo '</td>';
        echo '</tr>';


        // Taxonomy links.
        echo '<tr class="' . esc_attr( $links_row_class ) . '"><th><label>' . esc_html__( 'Show Taxonomy Links', 'luma-product-fields' ) . '</label></th>';
        echo '<td><label><input type="checkbox" name="lrpf_show_links" value="1"' . checked( $field['show_links'] ?? false, true, false ) . ' /> ';
        echo esc_html__( 'Link to products with same value in front end', 'luma-product-fields' ) . '</label></td></tr>';

        do_action( 'luma_product_fields_field_editor_form_bottom', $field );

        echo '</table>';

        submit_button( __( 'Save Field', 'luma-product-fields' ) );

        echo '</form></div>';
    }


/**
 * Handles saving the field definition.
 *
 * @return void
 */
public function handle_save(): void {

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'luma-product-fields' ) );
    }

    check_admin_referer( 'luma_product_fields_save_field_editor', 'luma_product_fields_nonce' );

    $label_raw = isset( $_POST['lrpf_label'] ) ? wp_unslash( $_POST['lrpf_label'] ) : '';
    $type_raw  = isset( $_POST['lrpf_type'] ) ? wp_unslash( $_POST['lrpf_type'] ) : '';

    $label = sanitize_text_field( is_string( $label_raw ) ? $label_raw : '' );
    $type  = sanitize_key( is_string( $type_raw ) ? $type_raw : '' );

    if ( '' === $label || '' === $type ) {
        $this->redirect_with_notice( __( 'You must enter both label and type.', 'luma-product-fields' ), 'error' );
    }

    if ( ! FieldTypeRegistry::get( $type ) ) {
        $this->redirect_with_notice( __( 'Invalid field type.', 'luma-product-fields' ), 'error' );
    }

    $original_slug = isset( $_POST['lrpf_original_slug'] )
        ? sanitize_key( wp_unslash( $_POST['lrpf_original_slug'] ) )
        : '';

    $slug = $original_slug ?: sanitize_title( $label );

    if ( '' === $slug ) {
        $this->redirect_with_notice( __( 'Could not generate a valid slug from the label.', 'luma-product-fields' ), 'error' );
    }

    if ( empty( $original_slug ) && $this->slug_conflicts( $slug ) ) {
        $this->redirect_with_notice(
            __( 'A field or taxonomy with this name already exists. Please choose another name.', 'luma-product-fields' ),
            'error'
        );
    }

    $info   = FieldTypeRegistry::get( $type ) ?? [];
    $is_tax = ( ( $info['storage'] ?? '' ) === 'taxonomy' );

    $before = null;
    if ( $is_tax && ! empty( $original_slug ) ) {
        $before = TaxonomyManager::get_field( $original_slug );
    }

    // Product groups (optional).
    $groups = [];
    if ( isset( $_POST['lrpf_groups'] ) ) {
        $raw_groups = wp_unslash( $_POST['lrpf_groups'] );
        $raw_groups = is_array( $raw_groups ) ? $raw_groups : [];

        $submitted = array_map(
            'sanitize_key',
            array_filter( $raw_groups )
        );

        if ( ! empty( $submitted ) ) {
            $allowed = array_keys( ProductGroup::get_product_groups() );

            $groups = array_values(
                array_intersect( $submitted, $allowed )
            );
        }
    }

    // Unit (optional, allowlisted).
    $unit_raw = isset( $_POST['lrpf_unit'] ) ? wp_unslash( $_POST['lrpf_unit'] ) : '';
    $unit     = sanitize_key( is_string( $unit_raw ) ? $unit_raw : '' );

    $allowed_units = array_keys( FieldTypeRegistry::get_units() );
    if ( '' !== $unit && ! in_array( $unit, $allowed_units, true ) ) {
        $unit = '';
    }

    // Schema property (optional).
    $schema_prop_raw = isset( $_POST['lrpf_schema_prop'] ) ? wp_unslash( $_POST['lrpf_schema_prop'] ) : '';
    $schema_prop     = sanitize_key( is_string( $schema_prop_raw ) ? $schema_prop_raw : '' );

    // Tooltip (admin).
    $description_raw = isset( $_POST['lrpf_description'] ) ? wp_unslash( $_POST['lrpf_description'] ) : '';
    $description     = sanitize_textarea_field( is_string( $description_raw ) ? $description_raw : '' );

    // Tooltip (frontend) via wp_editor field name.
    $frontend_desc_raw = isset( $_POST['luma_product_fields_fields_frontend_desc'] )
        ? wp_unslash( $_POST['luma_product_fields_fields_frontend_desc'] )
        : '';
    $frontend_desc = wp_kses_post( is_string( $frontend_desc_raw ) ? $frontend_desc_raw : '' );

    $data = [
        'label'            => $label,
        'description'      => $description,
        'frontend_desc'    => $frontend_desc,
        'slug'             => $slug,
        'type'             => $type,
        'unit'             => $unit,
        'schema_prop'      => $schema_prop,
        'groups'           => $groups,
        'hide_in_frontend' => ! empty( $_POST['lrpf_hide_in_frontend'] ),
        'variation'        => ! empty( $_POST['lrpf_variation'] ),
        'show_links'       => ! empty( $_POST['lrpf_show_links'] ),
    ];

    // Save field data.
    if ( $is_tax ) {
        TaxonomyManager::save_field( $data );
    } else {
        MetaManager::save_field( $data );
    }

    $action = $original_slug ? 'updated' : 'created';

    /**
     * Fires after a field definition is saved.
     *
     * This hook is intended for extensions that store their data separately
     * from the core field definition.
     *
     * @hook luma_product_fields_field_saved
     *
     * @param array  $data          The field data that was saved (sanitized).
     * @param string $action        The action performed: 'created' or 'updated'.
     * @param bool   $is_tax        Whether the field is taxonomy-backed.
     * @param string $original_slug The original slug when editing, or empty when creating.
     *
     * @return void
     */
    do_action( 'luma_product_fields_field_saved', $data, $action, $is_tax, $original_slug );

    if ( $this->should_flag_rewrite_flush( $before, $data ) ) {
        $this->flag_rewrite_flush();
    }

    CacheInvalidator::invalidate_all_meta_caches();

    if ( $is_tax ) {
        $message = ( 'created' === $action )
            ? __( 'Field created successfully. You can now add terms via the Manage Terms button.', 'luma-product-fields' )
            : __( 'Field updated successfully. Manage Terms is available for editing values.', 'luma-product-fields' );
    } else {
        $message = ( 'created' === $action )
            ? __( 'Field created successfully.', 'luma-product-fields' )
            : __( 'Field updated successfully.', 'luma-product-fields' );
    }

    /**
     * Filters the success message shown after a field is created or updated.
     *
     * @hook luma_product_fields_field_editor_success_message
     *
     * @param string $message The success message to be displayed.
     * @param string $action  The action performed: 'created' or 'updated'.
     * @param array  $data    The field data that was saved.
     * @param bool   $is_tax  Whether the field is a taxonomy field.
     *
     * @return string
     */
    $message = apply_filters(
        'luma_product_fields_field_editor_success_message',
        $message,
        $action,
        $data,
        $is_tax
    );

    $this->redirect_with_notice(
        $message,
        'success',
        admin_url( 'edit.php?post_type=product&page=luma-product-fields' )
    );
}

    
    /**
     * Check if a slug conflicts with any existing taxonomy or meta field.
     *
     * @param string $slug Slug to check.
     * @return bool True if slug already exists.
     */
    protected function slug_conflicts(string $slug): bool {
        // Check this plugin's taxonomies
        $plugin_tax_slugs = array_column( TaxonomyManager::get_all(), 'slug' );

        // Check this plugin's meta fields
        $plugin_meta_slugs = array_column( MetaManager::get_all(), 'slug' );

        // Check registered WordPress taxonomies
        $wp_tax_slugs = get_taxonomies([], 'names');

        return in_array($slug, $plugin_tax_slugs, true)
            || in_array($slug, $plugin_meta_slugs, true)
            || in_array($slug, $wp_tax_slugs, true);
    }


    /**
     * Determine whether this save operation requires a rewrite flush.
     *
     * Triggers when:
     * - A field becomes (or stops being) a linkable taxonomy (show_links + type supports 'link' + taxonomy storage).
     * - A linkable taxonomy field changes its slug.
     * - A field switches storage between taxonomy ↔ meta while linkability changes.
     *
     * @param array<string,mixed>|null $before Field definition before save (null on create).
     * @param array<string,mixed>      $after  Field definition after save (the data we just persisted).
     * @return bool True if the plugin should flush rewrite rules on next request.
     */
    protected function should_flag_rewrite_flush( ?array $before, array $after ): bool
    {
        $before_linkable = $this->is_linkable_taxonomy_field( $before );
        $after_linkable  = $this->is_linkable_taxonomy_field( $after );

        // If linkability toggled (false→true or true→false), we must flush.
        if ( $before_linkable !== $after_linkable ) {
            return true;
        }

        // If both were/are linkable, flush if the slug changed (route base is /{slug}/).
        if ( $before_linkable && $after_linkable ) {
            $before_slug = (string) ( $before['slug'] ?? '' );
            $after_slug  = (string) ( $after['slug'] ?? '' );
            if ( $before_slug && $after_slug && $before_slug !== $after_slug ) {
                return true;
            }
        }

        // On create: if the new field is linkable, we need a flush to add /{slug}/.
        if ( ! $before && $after_linkable ) {
            return true;
        }

        return false;
    }


    /**
     * Check whether a field definition represents a linkable taxonomy.
     *
     * A field is considered linkable when:
     * - It exists and has a valid 'type'.
     * - The field type's storage is 'taxonomy'.
     * - The field type supports 'link'.
     * - The field has 'show_links' truthy.
     *
     * @param array<string,mixed>|null $def Field definition, or null.
     * @return bool
     */
    protected function is_linkable_taxonomy_field( ?array $def ): bool
    {
        if ( ! is_array( $def ) ) {
            return false;
        }

        $type = (string) ( $def['type'] ?? '' );
        if ( ! $type ) {
            return false;
        }

        $info      = FieldTypeRegistry::get( $type );
        $is_tax    = isset($info['storage']) && $info['storage'] === 'taxonomy';
        $supports  = FieldTypeRegistry::supports( $type, 'link' );
        $show      = ! empty( $def['show_links'] );

        return $is_tax && $supports && $show;
    }


    /**
     * Set a one-time flag so the bootstrap flushes rewrite rules
     * after taxonomies and routes have been registered on the next request.
     *
     * @return void
     */
    protected function flag_rewrite_flush(): void
    {
        update_option( 'luma_product_fields_flush_rewrite', 1, true );
    }


    /**
     * Add an admin notice and redirect safely, then exit.
     *
     * @param string      $message   Notice message (HTML allowed; escaped on render).
     * @param string      $type      Notice type: success|error|warning|info.
     * @param string|null $redirect  Optional redirect URL.
     *
     * @return void
     */
    protected function redirect_with_notice(
        string $message,
        string $type = 'info',
        ?string $redirect = null
    ): void {

        NotificationManager::add_notice(
            [
                'type'    => $type,
                'message' => $message,
                'context' => 'field_editor',
            ]
        );

        if ( ! $redirect ) {
            $redirect = wp_get_referer();
        }

        if ( ! $redirect ) {
            $redirect = admin_url( 'edit.php?post_type=product&page=luma-product-fields' );
        }

        wp_safe_redirect( $redirect );
        exit;
    }


    
}
