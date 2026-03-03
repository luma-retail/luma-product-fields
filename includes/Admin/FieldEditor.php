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

    /**
     * User meta key for one-time field editor draft persistence across redirects.
     */
    protected const DRAFT_META_KEY = 'luma_product_fields_field_editor_draft';

    /**
     * Field editor page slug (admin.php?page=...).
     */
    public const PAGE_SLUG = 'luma-product-fields-edit';

    /**
     * Field editor screen ID for get_current_screen().
     */
    public const SCREEN_ID = 'product_page_luma-product-fields-edit';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_editor_page']);
        add_action('admin_post_luma_product_fields_save_field_editor', [$this, 'handle_save']);
        add_filter( 'parent_file', [ $this, 'filter_parent_file' ] );
        add_filter( 'submenu_file', [ $this, 'filter_submenu_file' ] );
        add_action( 'admin_head', [ $this, 'hide_editor_submenu_css' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_menu_script' ], 100 );
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
        $menu_slug   =  self::PAGE_SLUG;

        add_submenu_page(
            $parent_slug,
            __( 'Edit Field', 'luma-product-fields' ),
            __( 'Edit Field', 'luma-product-fields' ),
            'manage_woocommerce',
            $menu_slug,
            [ $this, 'render_editor' ]
        );

    }


    /**
     * Render the field editor screen.
     *
     * @return void
     */
    public function render_editor(): void
    { 
        $edit_input = filter_input( INPUT_GET, 'edit', FILTER_DEFAULT );
        $slug = is_string( $edit_input ) ? sanitize_key( wp_unslash( $edit_input ) ) : '';
        global $parent_file, $submenu_file;
        $parent_file  = 'edit.php?post_type=product';
        $submenu_file = 'edit.php?post_type=product&page=luma-product-fields';

        $field          = MetaManager::get_field( $slug ) ?? TaxonomyManager::get_field( $slug );
        $field = is_array( $field ) ? $field : [];
        $field_defaults = [
            'type'             => '',
            'label'            => '',
            'description'      => '',
            'frontend_desc'    => '',
            'unit'             => '',
            'groups'           => [ 'general' ],
            'hide_in_frontend' => false,
            'variation'        => false,
            'show_links'       => false,
        ];

        $field = wp_parse_args( $field, $field_defaults );

        $draft = $this->consume_editor_draft( $slug );
        if ( ! empty( $draft ) ) {
            $field = wp_parse_args( $draft, $field );
        }

        $types              = FieldTypeRegistry::get_all();
        $type_slugs         = array_keys( $types );
        $default_type       = $type_slugs[0] ?? '';
        $current_type       = (string) ( $field['type'] ?? $default_type );
        $supports_unit      = $current_type ? FieldTypeRegistry::supports( $current_type, 'unit' ) : false;
        $supports_links     = $current_type ? FieldTypeRegistry::supports( $current_type, 'link' ) : false;
        $supports_variation = $current_type ? FieldTypeRegistry::supports( $current_type, 'variations' ) : false;
        $supports_initial_terms = $current_type ? $this->is_initial_terms_eligible_type( (string) $current_type ) : false;
        $is_new_field = '' === $slug;
        $initial_terms_values = $this->normalize_initial_terms_for_render( $field['initial_terms'] ?? [] );

        $unit_row_class       = 'field-unit-row' . ( $supports_unit ? '' : ' hidden' );
        $links_row_class      = 'field-show-tax-links-row' . ( $supports_links ? '' : ' hidden' );
        $variations_row_class = 'field-variations-row' . ( $supports_variation ? '' : ' hidden' );
        $initial_terms_row_class = 'field-initial-terms-row' . ( $is_new_field ? '' : ' hidden' );

        $existing_terms_count = 0;
        if ( ! $is_new_field ) {
            $existing_terms_count = wp_count_terms(
                [
                    'taxonomy'   => $slug,
                    'hide_empty' => false,
                ]
            );

            if ( is_wp_error( $existing_terms_count ) ) {
                $existing_terms_count = 0;
            }
        }

        $types_desc = '<ul class="luma-product-fields-types-desc">';
        foreach ( $types as $type_slug => $type ) {
            $choice_id = 'luma-product-fields-type-choice-' . $type_slug;
            $types_desc .= sprintf(
                '<li id="luma-product-fields-type-%1$s" data-type="%1$s"><label for="%4$s" class="lumaprfi-type-choice"><input type="radio" id="%4$s" name="lrpf_type" value="%1$s" %5$s /> <span><strong>%2$s:</strong> %3$s</span></label></li>',
                esc_attr( $type_slug ),
                esc_html( $type['label'] ),
                esc_html( $type['description'] ),
                esc_attr( $choice_id ),
                checked( $current_type, $type_slug, false )
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

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lumaprfi-field-editor">';
        echo '<input type="hidden" name="action" value="luma_product_fields_save_field_editor" />';
        wp_nonce_field( 'luma_product_fields_save_field_editor', 'luma_product_fields_nonce' );
        if ( $slug ) {
            echo '<input type="hidden" name="lrpf_original_slug" value="' . esc_attr( $slug ) . '" />';
        }

        echo '<table class="form-table">';

        // Type.
        echo '<tr><th><label>' . esc_html__( 'Type', 'luma-product-fields' ) . '</label></th>';
        echo '<td><div class="field-types">';
        echo wp_kses( $types_desc, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );
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
        echo '<td><select name="lrpf_unit">';
        echo '<option value="">' . esc_html__( 'None', 'luma-product-fields' ) . '</option>';

        foreach ( FieldTypeRegistry::get_units() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $field['unit'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p>' . esc_html__( 'Need to add or rename units? You can manage units in WooCommerce settings.', 'luma-product-fields' ) . '</p>';
        echo '</td></tr>';

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

        // Taxonomy links.
        echo '<tr class="' . esc_attr( $links_row_class ) . '"><th><label>' . esc_html__( 'Show Taxonomy Links', 'luma-product-fields' ) . '</label></th>';
        echo '<td><label><input type="checkbox" name="lrpf_show_links" value="1"' . checked( $field['show_links'] ?? false, true, false ) . ' /> ';
        echo esc_html__( 'Link to products with same value in front end', 'luma-product-fields' ) . '</label></td></tr>';

        // Initial terms.
        echo '<tr class="' . esc_attr( $initial_terms_row_class ) . '" data-lumaprfi-eligible-types="' . esc_attr( implode( ',', self::get_initial_terms_eligible_types() ) ) . '"><th><label>' . esc_html__( 'Initial values', 'luma-product-fields' ) . '</label></th>';
        echo '<td>';

        if ( $is_new_field ) {
            echo '<div id="luma-product-fields-initial-terms" class="lumaprfi-initial-terms">';
            echo '<div class="lumaprfi-initial-terms-list">';
            foreach ( $initial_terms_values as $index => $initial_value ) {
                echo '<div class="lumaprfi-initial-term-row">';
                echo '<input type="text" name="lrpf_initial_terms[]" class="regular-text" value="' . esc_attr( $initial_value ) . '" />';
                $remove_class = ( 0 === $index && 1 === count( $initial_terms_values ) ) ? ' hidden' : '';
                echo '<button type="button" class="button-link-delete lumaprfi-remove-initial-term' . esc_attr( $remove_class ) . '">' . esc_html__( 'Remove', 'luma-product-fields' ) . '</button>';
                echo '</div>';
            }
            echo '</div>';
            echo '<p><button type="button" class="button lumaprfi-add-initial-term">' . esc_html__( 'Add value', 'luma-product-fields' ) . '</button></p>';
            echo '<p>' . esc_html__( 'You can add and remove these terms later in the term editor.', 'luma-product-fields' ) . '</p>';
            echo '</div>';
        } elseif ( $this->is_initial_terms_eligible_type( (string) ( $field['type'] ?? '' ) ) && $existing_terms_count > 0 ) {
            $manage_terms_url = admin_url( 'edit-tags.php?post_type=product&taxonomy=' . urlencode( (string) $slug ) );
            printf(
                '<p>%1$s <a href="%2$s">%3$s</a>.</p>',
                esc_html__( 'You can add and remove these terms later in the term editor.', 'luma-product-fields' ),
                esc_url( $manage_terms_url ),
                esc_html__( 'Open term editor', 'luma-product-fields' )
            );
        }

        echo '</td></tr>';

        do_action( 'luma_product_fields_field_editor_form_bottom', $field );

        echo '</table>';

        submit_button( __( 'Save Field', 'luma-product-fields' ) );

        echo '</form></div>';
    }


    /**
     * Hide the field editor submenu item while keeping it registered.
     *
     * @return void
     */
    public function hide_editor_submenu_css(): void
    {
        echo '<style>#menu-posts-product .wp-submenu a[href="edit.php?post_type=product&page=luma-product-fields-edit"]{display:none;}</style>';
    }


    /**
     * Enqueue admin JS to force menu highlighting on the editor screen.
     *
     * @return void
     */
    public function enqueue_editor_menu_script(): void
    {
        if ( ! self::is_field_editor_screen() ) {
            return;
        }

        wp_enqueue_script(
            'luma-product-fields-field-editor-js',
            LUMA_PRODUCT_FIELDS_PLUGIN_URL . 'js/admin/field-editor.js',
            [ 'jquery', 'luma-product-fields-admin-js' ],
            LUMA_PRODUCT_FIELDS_PLUGIN_VER,
            true
        );

    }


    /**
     * Force Products > Product Fields to highlight when viewing the field editor.
     *
     * @param string $parent_file Current parent file.
     * @return string
     */
    public function filter_parent_file( string $parent_file ): string
    {
        return self::is_field_editor_screen()
            ? 'edit.php?post_type=product'
            : $parent_file;
    }


    /**
     * Force the Product Fields submenu highlight when viewing the field editor.
     *
     * @param string|null $submenu_file Current submenu file.
     * @return string|null
     */
    public function filter_submenu_file( ?string $submenu_file ): ?string
    {
        return self::is_field_editor_screen()
            ? 'edit.php?post_type=product&page=luma-product-fields'
            : $submenu_file;
    }


    /**
     * Check if the current admin screen is the field editor.
     *
     * @return bool
     */
    /**
     * Helper for core + extensions to detect the field editor screen.
     *
     * @return bool
     */
    public static function is_field_editor_screen(): bool
    {
        if ( ! is_admin() ) {
            return false;
        }

        $page_input = filter_input( INPUT_GET, 'page', FILTER_DEFAULT );
        $post_type_input = filter_input( INPUT_GET, 'post_type', FILTER_DEFAULT );

        $page = is_string( $page_input ) ? sanitize_key( wp_unslash( $page_input ) ) : '';
        $post_type = is_string( $post_type_input ) ? sanitize_key( wp_unslash( $post_type_input ) ) : '';

        if ( $page !== self::PAGE_SLUG ) {
            return false;
        }

        // Some links omit post_type, so accept both.
        return ( '' === $post_type ) || ( 'product' === $post_type );
    }


    /**
     * Field types that can receive initial terms during first-time creation.
     *
     * @return string[]
     */
    protected static function get_initial_terms_eligible_types(): array
    {
        return [ 'single', 'multiple', 'autocomplete' ];
    }


    /**
     * Check whether the provided field type can seed initial terms.
     *
     * @param string $type Field type slug.
     * @return bool
     */
    protected function is_initial_terms_eligible_type( string $type ): bool
    {
        return in_array( $type, self::get_initial_terms_eligible_types(), true );
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

    $original_slug = isset( $_POST['lrpf_original_slug'] )
        ? sanitize_key( wp_unslash( $_POST['lrpf_original_slug'] ) )
        : '';

    $form_draft = $this->build_editor_draft_from_request( $original_slug );

    $label = ( isset( $_POST['lrpf_label'] ) && is_scalar( $_POST['lrpf_label'] ) )
        ? sanitize_text_field( wp_unslash( (string) $_POST['lrpf_label'] ) )
        : '';
    $type = ( isset( $_POST['lrpf_type'] ) && is_scalar( $_POST['lrpf_type'] ) )
        ? sanitize_key( wp_unslash( (string) $_POST['lrpf_type'] ) )
        : '';

    if ( '' === $label || '' === $type ) {
        $this->redirect_with_notice( __( 'You must enter both label and type.', 'luma-product-fields' ), 'error', null, $form_draft );
    }

    if ( ! FieldTypeRegistry::get( $type ) ) {
        $this->redirect_with_notice( __( 'Invalid field type.', 'luma-product-fields' ), 'error', null, $form_draft );
    }

    $slug = $original_slug ?: sanitize_title( $label );

    if ( '' === $slug ) {
        $this->redirect_with_notice( __( 'Could not generate a valid slug from the label.', 'luma-product-fields' ), 'error', null, $form_draft );
    }

    if ( empty( $original_slug ) && $this->slug_conflicts( $slug ) ) {
        $this->redirect_with_notice(
            __( 'A field or taxonomy with this name already exists. Please choose another name.', 'luma-product-fields' ),
            'error',
            null,
            $form_draft
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
    if ( isset( $_POST['lrpf_groups'] ) && is_array( $_POST['lrpf_groups'] ) ) {
        $submitted = array_values(
            array_filter(
                array_map( 'sanitize_key', wp_unslash( $_POST['lrpf_groups'] ) )
            )
        );

        if ( ! empty( $submitted ) ) {
            $allowed = array_keys( ProductGroup::get_product_groups() );

            $groups = array_values(
                array_intersect( $submitted, $allowed )
            );
        }
    }

    // Unit (optional, allowlisted).
    $unit = ( isset( $_POST['lrpf_unit'] ) && is_scalar( $_POST['lrpf_unit'] ) )
        ? FieldTypeRegistry::normalize_unit_slug( wp_unslash( (string) $_POST['lrpf_unit'] ) )
        : '';

    $allowed_units = array_keys( FieldTypeRegistry::get_units() );
    if ( '' !== $unit && ! in_array( $unit, $allowed_units, true ) ) {
        $unit = '';
    }

    // Tooltip (admin).
    $description = ( isset( $_POST['lrpf_description'] ) && is_scalar( $_POST['lrpf_description'] ) )
        ? sanitize_textarea_field( wp_unslash( (string) $_POST['lrpf_description'] ) )
        : '';

    // Tooltip (frontend) via wp_editor field name.
    $frontend_desc = ( isset( $_POST['luma_product_fields_fields_frontend_desc'] ) && is_scalar( $_POST['luma_product_fields_fields_frontend_desc'] ) )
        ? wp_kses_post( wp_unslash( (string) $_POST['luma_product_fields_fields_frontend_desc'] ) )
        : '';

    $data = [
        'label'            => $label,
        'description'      => $description,
        'frontend_desc'    => $frontend_desc,
        'slug'             => $slug,
        'type'             => $type,
        'unit'             => $unit,
        'groups'           => $groups,
        'hide_in_frontend' => ! empty( $_POST['lrpf_hide_in_frontend'] ),
        'variation'        => ! empty( $_POST['lrpf_variation'] ),
        'show_links'       => ! empty( $_POST['lrpf_show_links'] ),
    ];

    /**
     * Filters the normalized field data array before it is saved.
     *
     * Extensions can use this to persist extra keys alongside the core field definition.
     *
     * @hook luma_product_fields_field_editor_form_data
     *
     * @param array<string,mixed> $data Field data, including label, description, type, etc.
     * @return array<string,mixed>
     */
    $data = apply_filters( 'luma_product_fields_field_editor_form_data', $data );

    // Ensure filter callbacks can't break the expected array shape.
    $data = is_array( $data ) ? $data : [];

    // Save field data.
    if ( $is_tax ) {
        TaxonomyManager::save_field( $data );
    } else {
        MetaManager::save_field( $data );
    }

    $action = $original_slug ? 'updated' : 'created';

    $created_initial_terms = 0;
    if ( 'created' === $action && $is_tax && $this->is_initial_terms_eligible_type( $type ) ) {
        $initial_terms = $this->sanitize_initial_terms_from_request();
        if ( ! empty( $initial_terms ) ) {
            $created_initial_terms = $this->create_initial_terms_for_taxonomy_field( (string) ( $data['slug'] ?? '' ), $initial_terms );
        }
    }

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

        if ( 'created' === $action && $created_initial_terms > 0 ) {
            /* translators: %d: number of terms created. */
            $message = sprintf( __( 'Field created successfully. %d terms were added.', 'luma-product-fields' ), $created_initial_terms );
        }
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
     * Read and sanitize initial terms from the field editor form.
     *
     * @return string[]
     */
    protected function sanitize_initial_terms_from_request(): array
    {
        if ( ! isset( $_POST['lrpf_initial_terms'] ) || ! is_array( $_POST['lrpf_initial_terms'] ) ) {
            return [];
        }

        $terms = array_map(
            static function ( $value ): string {
                return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
            },
            $_POST['lrpf_initial_terms']
        );

        $terms = array_values( array_filter( $terms ) );

        return array_values( array_unique( $terms ) );
    }


    /**
     * Normalize initial terms for rendering as repeater rows.
     *
     * @param mixed $terms Candidate initial terms.
     * @return string[]
     */
    protected function normalize_initial_terms_for_render( $terms ): array
    {
        if ( ! is_array( $terms ) ) {
            return [ '' ];
        }

        $normalized = array_map(
            static function ( $value ): string {
                return is_scalar( $value ) ? (string) $value : '';
            },
            $terms
        );

        $normalized = array_values( array_filter( $normalized, static fn( string $value ): bool => '' !== trim( $value ) ) );

        return empty( $normalized ) ? [ '' ] : $normalized;
    }


    /**
     * Build an editor form draft from current request input.
     *
     * @param string $editor_slug Current editor slug context.
     * @return array<string,mixed>
     */
    protected function build_editor_draft_from_request( string $editor_slug ): array
    {
        $label = ( isset( $_POST['lrpf_label'] ) && is_scalar( $_POST['lrpf_label'] ) )
            ? sanitize_text_field( wp_unslash( (string) $_POST['lrpf_label'] ) )
            : '';

        $type = ( isset( $_POST['lrpf_type'] ) && is_scalar( $_POST['lrpf_type'] ) )
            ? sanitize_key( wp_unslash( (string) $_POST['lrpf_type'] ) )
            : '';

        $description = ( isset( $_POST['lrpf_description'] ) && is_scalar( $_POST['lrpf_description'] ) )
            ? sanitize_textarea_field( wp_unslash( (string) $_POST['lrpf_description'] ) )
            : '';

        $frontend_desc = ( isset( $_POST['luma_product_fields_fields_frontend_desc'] ) && is_scalar( $_POST['luma_product_fields_fields_frontend_desc'] ) )
            ? wp_kses_post( wp_unslash( (string) $_POST['luma_product_fields_fields_frontend_desc'] ) )
            : '';

        $unit = ( isset( $_POST['lrpf_unit'] ) && is_scalar( $_POST['lrpf_unit'] ) )
            ? FieldTypeRegistry::normalize_unit_slug( wp_unslash( (string) $_POST['lrpf_unit'] ) )
            : '';

        $allowed_units = array_keys( FieldTypeRegistry::get_units() );
        if ( '' !== $unit && ! in_array( $unit, $allowed_units, true ) ) {
            $unit = '';
        }

        $groups = [];
        if ( isset( $_POST['lrpf_groups'] ) && is_array( $_POST['lrpf_groups'] ) ) {
            $submitted = array_values(
                array_filter(
                    array_map( 'sanitize_key', wp_unslash( $_POST['lrpf_groups'] ) )
                )
            );

            if ( ! empty( $submitted ) ) {
                $allowed = array_keys( ProductGroup::get_product_groups() );
                $groups = array_values( array_intersect( $submitted, $allowed ) );
            }
        }

        return [
            '__editor_slug'     => $editor_slug,
            'label'             => $label,
            'type'              => $type,
            'description'       => $description,
            'frontend_desc'     => $frontend_desc,
            'unit'              => $unit,
            'groups'            => $groups,
            'hide_in_frontend'  => ! empty( $_POST['lrpf_hide_in_frontend'] ),
            'variation'         => ! empty( $_POST['lrpf_variation'] ),
            'show_links'        => ! empty( $_POST['lrpf_show_links'] ),
            'initial_terms'     => $this->sanitize_initial_terms_from_request(),
        ];
    }


    /**
     * Persist a one-time field editor draft for the current user.
     *
     * @param array<string,mixed> $draft Draft payload.
     * @return void
     */
    protected function save_editor_draft( array $draft ): void
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        update_user_meta( $user_id, self::DRAFT_META_KEY, $draft );
    }


    /**
     * Consume and clear one-time field editor draft for this screen.
     *
     * @param string $current_slug Current editor slug context.
     * @return array<string,mixed>
     */
    protected function consume_editor_draft( string $current_slug ): array
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return [];
        }

        $draft = get_user_meta( $user_id, self::DRAFT_META_KEY, true );
        delete_user_meta( $user_id, self::DRAFT_META_KEY );

        if ( ! is_array( $draft ) ) {
            return [];
        }

        $draft_slug = isset( $draft['__editor_slug'] ) && is_string( $draft['__editor_slug'] )
            ? sanitize_key( $draft['__editor_slug'] )
            : '';

        if ( $draft_slug !== $current_slug ) {
            return [];
        }

        unset( $draft['__editor_slug'] );

        return $draft;
    }


    /**
     * Create initial terms for a newly created taxonomy-backed field.
     *
     * @param string   $taxonomy Taxonomy slug.
     * @param string[] $terms    Terms to create.
     * @return int Number of terms successfully created.
     */
    protected function create_initial_terms_for_taxonomy_field( string $taxonomy, array $terms ): int
    {
        if ( '' === $taxonomy || empty( $terms ) ) {
            return 0;
        }

        if ( ! taxonomy_exists( $taxonomy ) ) {
            register_taxonomy(
                $taxonomy,
                'product',
                [
                    'public'            => false,
                    'show_ui'           => false,
                    'show_in_menu'      => false,
                    'show_admin_column' => false,
                    'show_in_rest'      => false,
                    'rewrite'           => false,
                    'query_var'         => false,
                ]
            );
        }

        $created_count = 0;

        foreach ( $terms as $term_name ) {
            $existing_term = get_term_by( 'name', $term_name, $taxonomy );
            if ( $existing_term ) {
                continue;
            }

            $result = wp_insert_term( $term_name, $taxonomy );
            if ( ! is_wp_error( $result ) ) {
                $created_count++;
            }
        }

        return $created_count;
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
        ?string $redirect = null,
        ?array $draft = null
    ): void {

        NotificationManager::add_notice(
            [
                'type'    => $type,
                'message' => $message,
                'context' => 'field_editor',
            ]
        );

        if ( is_array( $draft ) && ! empty( $draft ) ) {
            $this->save_editor_draft( $draft );
        }

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
