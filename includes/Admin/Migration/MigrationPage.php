<?php
/**
 * Luma Fields Migration UI 
 *
 * @package Luma\ProductFields
 */
namespace Luma\ProductFields\Admin\Migration;

use Luma\ProductFields\Migration\LegacyMetaMigrator;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Migration UI Page
 *
 * Displays a field-to-legacy-meta mapping UI, handles dry run and real migration,
 * and outputs a result table for admin feedback.
 *
 * @hook luma_product_fields_migration_field_options
 *      Allow extensions to render extra field-specific options.
 *      @param array $field Current field definition.
 */
class MigrationPage {

    /**
     * Option key for migration log.
     */
    public const OPTION_MIGRATION_LOG = 'luma_product_fields_meta_migration_log';


    /**
     * Register the admin submenu page (invisible under Products).
     */
    public static function register(): void {
        if ( 'no' === get_option( Settings::PREFIX . 'enable_migration_tool' ) ) {
            return;
        }

        add_action( 'admin_menu', [ static::class, 'add_admin_page' ] );
        add_action( 'luma_product_fields_field_manager_actions', [ static::class, 'show_migration_button' ] );
    }


    /**
     * Add the submenu page under "Products", but without a visible menu link.
     */
    public static function add_admin_page(): void {
        add_submenu_page(
            'edit.php?post_type=product',
            __( 'Migrate Legacy Meta', 'luma-product-fields' ),
            '',
            'manage_options',
            'luma-product-fields-migration',
            [ static::class, 'render' ]
        );
    }


    /**
     * Render "Migration tool" button in Field Manager.
     */
    public static function show_migration_button(): void {
        ?>
        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=luma-product-fields-migration' ) ); ?>"
           class="button button-large"
           style="margin-left: 1em;">
            <?php esc_html_e( 'Migration tool', 'luma-product-fields' ); ?>
        </a>
        <?php
    }


    /**
     * Render the migration UI form, handle POSTed data, and display results.
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'luma-product-fields' ) );
        }

        $fields          = Helpers::get_all_fields();
        $distinct_keys   = self::get_distinct_meta_keys();
        $migration_log   = get_option( static::OPTION_MIGRATION_LOG, [] );
        $mapping         = [];
        $summary         = [];
        $is_dry_run      = true;
        $notice          = '';
        $show_summary_ui = false;

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : '';

        if ( 'POST' === $request_method && check_admin_referer( 'luma_product_fields_fields_migration' ) ) {
            $is_dry_run    = isset( $_POST['dry_run'] );
            $skip_existing = filter_input( INPUT_POST, 'skip_existing', FILTER_VALIDATE_BOOLEAN ) ?? false;

            foreach ( $fields as $field ) {
                $slug      = $field['slug'];
                $map_key   = 'map_' . $slug;
                $index_key = 'number_index_' . $slug;

                $meta_key_raw = isset( $_POST[ $map_key ] ) ? wp_unslash( $_POST[ $map_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $meta_key     = sanitize_text_field( $meta_key_raw );

                $index_raw   = isset( $_POST[ $index_key ] ) ? wp_unslash( $_POST[ $index_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $index_value = (int) $index_raw;

                if ( '' === $meta_key ) {
                    continue;
                }

                $match_unit = filter_input(
                    INPUT_POST,
                    'match_unit_' . $slug,
                    FILTER_VALIDATE_BOOLEAN
                ) ?? false;

                $include_variations = filter_input(
                    INPUT_POST,
                    'include_variations_' . $slug,
                    FILTER_VALIDATE_BOOLEAN
                ) ?? false;

                $mapping[ $slug ] = [
                    'skip_existing'      => $skip_existing,
                    'meta_key'           => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'field'              => $field,
                    'number_index'       => $index_value,
                    'match_unit'         => $match_unit,
                    'include_variations' => $include_variations,
                ];
            }

            if ( ! empty( $mapping ) ) {
                $migrator = new LegacyMetaMigrator();
                $summary  = $migrator->run( $mapping, $is_dry_run );

                if ( $is_dry_run ) {
                    $show_summary_ui = true;
                } else {
                    $log = get_option( static::OPTION_MIGRATION_LOG, [] );
                    foreach ( array_keys( $mapping ) as $slug ) {
                        $log[ $slug ] = current_time( 'mysql' );
                    }
                    update_option( static::OPTION_MIGRATION_LOG, $log );
                    $migration_log = $log;

                    $updated_count = 0;
                    foreach ( $summary as $product ) {
                        foreach ( $product as $result ) {
                            if ( isset( $result['status'] ) && 'migrated' === $result['status'] ) {
                                $updated_count++;
                                break;
                            }
                        }
                    }

                    if ( $updated_count > 0 ) {
                        /* translators: %d: number of products updated */
                        $notice = sprintf( esc_html__( '%d products updated successfully.', 'luma-product-fields' ), $updated_count );
                    } else {
                        $notice = esc_html__( 'No products were updated.', 'luma-product-fields' );
                    }
                }
            }
        }


        echo '<div class="wrap">';

        if ( is_callable( [ '\Luma\ProductFields\Admin\Admin', 'show_back_button' ] ) ) {
            Admin::show_back_button();
        }

        echo '<h1>' . esc_html__( 'Product Fields Meta Migration', 'luma-product-fields' ) . '</h1>';

        self::render_migration_intro();

        if ( ! empty( $notice ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'luma_product_fields_fields_migration' );

        echo '<table class="widefat  striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Field', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Legacy Meta Key', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Options', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'luma-product-fields' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $fields as $field ) {
            $slug         = $field['slug'];
            $migrated_at  = $migration_log[ $slug ] ?? null;
            $status_label = $migrated_at
                ? '<span style="color:green;">&#10004; ' .
                  esc_html__( 'Migrated on', 'luma-product-fields' ) . ' ' .
                  esc_html( $migrated_at ) .
                  '</span>'
                : '<span style="color:gray;">' .
                  esc_html__( 'Not migrated', 'luma-product-fields' ) .
                  '</span>';

            echo '<tr>';
            echo '<td><strong>' . esc_html( $field['label'] ?? $slug ) . '</strong><br><code>' . esc_html( $slug ) . '</code></td>';

            echo '<td><select name="map_' . esc_attr( $slug ) . '"><option value="">' . esc_html_x( '--', 'no meta key selected', 'luma-product-fields' ) . '</option>';
            $map_key           = 'map_' . $slug;
            $selected_key_raw  = isset( $_POST[ $map_key ] ) ? wp_unslash( $_POST[ $map_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $selected_key      = sanitize_text_field( $selected_key_raw );
            foreach ( $distinct_keys as $key ) {
                $selected_attr = selected( $selected_key, $key, false );
                // "selected" attribute string is safe, all dynamic pieces must be escaped above.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<option value="' . esc_attr( $key ) . '"' . $selected_attr . '>' . esc_html( $key ) . '</option>';
            }
            echo '</select></td>';

            echo '<td class="luma-product-fields-migration-options">';
            if ( empty( $field['is_taxonomy'] ) ) {
                echo '<label>';
                echo '<input type="checkbox" name="include_variations_' . esc_attr( $slug ) . '" ' .
                     checked( ! empty( $_POST[ 'include_variations_' . $slug ] ), true, false ) . '> ';
                esc_html_e( 'Include variations', 'luma-product-fields' );
                echo '</label>';
            }
            
            if ( in_array( $field['type'], [ 'number', 'integer' ], true ) ) {
                $index_key          = 'number_index_' . $slug;
                $selected_index_raw = isset( $_POST[ $index_key ] ) ? wp_unslash( $_POST[ $index_key ] ) : '0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $selected_index     = sanitize_text_field( $selected_index_raw );

                echo '<label>' . esc_html__( 'Which number?', 'luma-product-fields' ) . ' ';
                echo '<select name="number_index_' . esc_attr( $slug ) . '">';
                echo '<option value="0"'  . selected( $selected_index, '0', false )  . '>' . esc_html__( '1st',  'luma-product-fields' ) . '</option>';
                echo '<option value="1"'  . selected( $selected_index, '1', false )  . '>' . esc_html__( '2nd',  'luma-product-fields' ) . '</option>';
                echo '<option value="-1"' . selected( $selected_index, '-1', false ) . '>' . esc_html__( 'Last', 'luma-product-fields' ) . '</option>';
                echo '</select>';
                echo '</label>';

                $match_unit_key   = 'match_unit_' . $slug;
                $match_unit_raw   = isset( $_POST[ $match_unit_key ] ) ? wp_unslash( $_POST[ $match_unit_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $match_unit_check = ! empty( $match_unit_raw );

                echo '<label>';
                echo '<input type="checkbox" name="match_unit_' . esc_attr( $slug ) . '" ' .
                     checked( $match_unit_check, true, false ) . '> ';
                esc_html_e( 'Try to match unit', 'luma-product-fields' );
                echo '</label>';
            }

            /**
             * Allow extensions to render extra field-specific options.
             *
             * @hook luma_product_fields_migration_field_options
             *
             * @param array $field Current field definition.
             */
            do_action( 'luma_product_fields_migration_field_options', $field );

            echo '</td>';

            echo '<td>' . wp_kses_post( $status_label ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p><label>';
        echo '<input type="checkbox" name="skip_existing" ' . checked( ! empty( $_POST['skip_existing'] ), true, false ) . '>';
        echo ' ' . esc_html__( 'Skip if field already has a value', 'luma-product-fields' );
        echo '</label></p>';

        echo '<p><label><input type="checkbox" name="dry_run" checked> ' .
             esc_html__( 'Dry run (no changes will be saved)', 'luma-product-fields' ) .
             '</label></p>';

        echo '<p><button type="submit" class="button button-primary">' .
             esc_html__( 'Run Migration', 'luma-product-fields' ) .
             '</button></p>';

        echo '</form>';

        if ( $show_summary_ui && ! empty( $summary ) ) {
            self::render_summary_table( $summary, $fields );
        }

        self::render_migration_notes();

        echo '</div>';
    }


    /**
     * Render migration summary as a compact table.
     *
     * @param array $summary Full migration summary from LegacyMetaMigrator::run().
     * @param array $fields  Field definitions.
     */
    protected static function render_summary_table( array $summary, array $fields ): void {
        $field_labels = [];

        foreach ( $fields as $field ) {
            $field_labels[ $field['slug'] ] = $field['label'] ?? $field['slug'];
        }

        $counts = [
            'migrated'         => 0,
            'dry_run'          => 0,
            'skipped_existing' => 0,
            'skipped_invalid'  => 0,
            'external_save'    => 0,
        ];

        foreach ( $summary as $product_id => $field_results ) {
            foreach ( $field_results as $slug => $row ) {
                $status = $row['status'] ?? '';

                if ( 'migrated' === $status ) {
                    $counts['migrated']++;
                } elseif ( 'dry-run' === $status ) {
                    $counts['dry_run']++;
                } elseif ( 'skipped' === $status && ( $row['reason'] ?? '' ) === 'Existing value present' ) {
                    $counts['skipped_existing']++;
                } elseif ( 'skipped' === $status ) {
                    $counts['skipped_invalid']++;
                } elseif ( strpos( $status, 'external save' ) !== false ) {
                    $counts['external_save']++;
                }
            }
        }

        echo '<h2>' . esc_html__( 'Migration Result (dry run)', 'luma-product-fields' ) . '</h2>';

        echo '<div class="luma-product-fields-migration-counters">';
        echo '<p><strong>' . esc_html__( 'Migration Summary', 'luma-product-fields' ) . '</strong></p>';

        echo '<ul class="luma-product-fields-counters-list">';
        echo '<li><span class="luma-product-fields-count lpf-count-green">' . esc_html( (string) $counts['migrated'] ) . '</span> ' . esc_html__( 'migrated', 'luma-product-fields' ) . '</li>';
        echo '<li><span class="luma-product-fields-count lpf-count-blue">' . esc_html( (string) $counts['dry_run'] ) . '</span> ' . esc_html__( 'dry-run changes', 'luma-product-fields' ) . '</li>';
        echo '<li><span class="luma-product-fields-count lpf-count-orange">' . esc_html( (string) $counts['skipped_existing'] ) . '</span> ' . esc_html__( 'skipped (existing value)', 'luma-product-fields' ) . '</li>';
        echo '<li><span class="luma-product-fields-count lpf-count-gray">' . esc_html( (string) $counts['skipped_invalid'] ) . '</span> ' . esc_html__( 'skipped (no valid data)', 'luma-product-fields' ) . '</li>';
        if ( $counts['external_save'] > 0 ) {
            echo '<li><span class="luma-product-fields-count lpf-count-purple">' . esc_html( (string) $counts['external_save'] ) . '</span> ' . esc_html__( 'handled by external save callback', 'luma-product-fields' ) . '</li>';
        }
        echo '</ul>';
        echo '</div>';

        echo '<div style="overflow:auto; max-height:600px; border:1px solid #ccc; padding:1em;">';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Product', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Field', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Original', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Existing', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'New value', 'luma-product-fields' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason / Details', 'luma-product-fields' ) . '</th>';
        echo '</tr></thead><tbody>';

        $rows      = 0;
        $max_rows  = 500;
        $truncated = false;

        foreach ( $summary as $product_id => $field_results ) {
            foreach ( $field_results as $slug => $result ) {
                $rows++;
                if ( $rows > $max_rows ) {
                    $truncated = true;
                    break 2;
                }

                $product_link = get_edit_post_link( $product_id );
                $product_cell = $product_link
                    ? '<a href="' . esc_url( $product_link ) . '">#' . (int) $product_id . '</a>'
                    : '#' . (int) $product_id;

                $field_label = $field_labels[ $slug ] ?? $slug;

                $status = $result['status'] ?? '';
                $orig   = $result['original'] ?? '';
                $new    = $result['new'] ?? '';
                if ( is_array( $new ) ) {
                    $new = wp_json_encode( $new );
                }
                $existing_val = $result['existing'] ?? '';
                if ( is_array( $existing_val ) ) {
                    $existing_val = wp_json_encode( $existing_val );
                }
                $reason = $result['reason'] ?? '';

                if ( 'migrated' === $status ) {
                    $cls = 'luma-product-fields-status-migrated';
                } elseif ( 'dry-run' === $status ) {
                    $cls = 'luma-product-fields-status-dry-run';
                } elseif ( 'skipped' === $status && 'Existing value present' === $reason ) {
                    $cls = 'luma-product-fields-status-skipped-exists';
                } elseif ( strpos( $status, 'external save' ) !== false ) {
                    $cls = 'luma-product-fields-status-external';
                } else {
                    $cls = 'luma-product-fields-status-skipped';
                }

                echo '<tr class="' . esc_attr( $cls ) . '">';
                echo '<td>' . wp_kses_post( $product_cell ) . '</td>';
                echo '<td><strong>' . esc_html( $field_label ) . '</strong><br><code>' . esc_html( $slug ) . '</code></td>';
                echo '<td>' . esc_html( (string) $status ) . '</td>';
                echo '<td>' . esc_html( (string) $orig ) . '</td>';
                echo '<td>' . esc_html( (string) $existing_val ) . '</td>';
                echo '<td>' . esc_html( (string) $new ) . '</td>';
                echo '<td>' . esc_html( (string) $reason ) . '</td>';
                echo '</tr>';
            }
        }

        if ( ! $rows ) {
            echo '<tr><td colspan="6">' . esc_html__( 'No changes were detected in this dry run.', 'luma-product-fields' ) . '</td></tr>';
        }

        echo '</tbody></table>';

        if ( $truncated ) {
            echo '<p><em>' .
                 esc_html__( 'Showing only the first 500 rows.', 'luma-product-fields' ) .
                 '</em></p>';
        }

        echo '</div>';
    }


    /**
     * Retrieve distinct meta keys from the postmeta table.
     *
     * Excluding WooCommerce internal keys and known technical ones.
     *
     * @return array
     */
    protected static function get_distinct_meta_keys(): array {
        global $wpdb;

        $store     = \WC_Data_Store::load( 'product' );
        $protected = array_flip( $store->get_internal_meta_keys() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            "
            SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type IN ('product', 'product_variation')
            AND pm.meta_key NOT LIKE '_oembed%'
            AND pm.meta_key NOT REGEXP '^field_[a-zA-Z0-9]{8,}'
            ORDER BY pm.meta_key ASC
        "
        );

        if ( ! is_array( $results ) ) {
            return [];
        }

        return array_values(
            array_filter(
                $results,
                static fn( $key ) => ! isset( $protected[ $key ] )
            )
        );
    }


    /**
     * Render the main explanation block for the metadata migration tool.
     *
     * @return void
     */
    protected static function render_migration_intro(): void {

        echo '<div class="luma-migration-intro">';



        echo '<p>';
        echo esc_html__(
            'This tool migrates existing product meta values into Luma Product Fields. During migration, existing
             meta values are read and converted to match the structure required by each field type.',
            'luma-product-fields'
        );
        echo '</p>';

        echo '<p>';
        echo esc_html__(
            'For example, a value like "15 grams" can be converted into a numeric value of 15 and a unit of grams when migrating into a number field with unit support.',
            'luma-product-fields'
        );
        echo '</p>';

        echo '<p>';
        echo esc_html__(
            'By default, the migration runs in dry-run mode. In this mode, no data is written to the database. Disable dry-run only when you are confident in the results.',
            'luma-product-fields'
        );
        echo '</p>';

        echo '</div>';
    }


    /**
     * Render important notes and warnings for the metadata migration tool.
     *
     * This section clarifies limitations, non-destructive behavior,
     * and the fact that this is a one-time migration, not synchronization.
     *
     * Intended to be displayed at the bottom of the migration page.
     *
     * @return void
     */
    protected static function render_migration_notes(): void {

        echo '<div class="luma-migration-notes">';

        echo '<h3>' . esc_html__( 'Important notes', 'luma-product-fields' ) . '</h3>';

        echo '<ul>';

        echo '<li>';
        echo esc_html__(
            'This tool performs a one-time migration only. It does not synchronize data.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '<li>';
        echo esc_html__(
            'Original meta values are not deleted or modified. If other plugins or custom continue to write to the 
             old/existing meta keys, those changes will not be reflected in Luma Product Fields.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '<li>';
        echo esc_html__(
            'Conversion is best-effort. Values that do not match the expected format of a field type may be skipped or partially converted.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '<li>';
        echo esc_html__(
            'It is recommended to review a subset of products after migration before relying on the migrated field data.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '</ul>';

        echo '</div>';
    }



}
