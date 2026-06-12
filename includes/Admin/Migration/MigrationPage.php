<?php
/**
 * Luma Fields Migration UI
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin\Migration;

use Luma\ProductFields\Admin\Admin;
use Luma\ProductFields\Admin\Settings;
use Luma\ProductFields\Migration\CategoryToTaxonomyMapper;
use Luma\ProductFields\Migration\LegacyMetaMigrator;
use Luma\ProductFields\Migration\NameExtractor;
use Luma\ProductFields\Product\VariationNumericAggregates;
use Luma\ProductFields\Utils\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Migration tools page.
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
     * Add the submenu page under "Products", but without visible menu text.
     */
    public static function add_admin_page(): void {
        add_submenu_page(
            'edit.php?post_type=product',
            __( 'Migration tools', 'luma-product-fields' ),
            '',
            'manage_options',
            'luma-product-fields-migration',
            [ static::class, 'render' ]
        );
    }

    /**
     * Render "Migration tools" button in Field Manager.
     */
    public static function show_migration_button(): void {
        ?>
        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=luma-product-fields-migration' ) ); ?>"
           class="button button-large"
           style="margin-left: 1em;">
            <?php esc_html_e( 'Migration tools', 'luma-product-fields' ); ?>
        </a>
        <?php
    }

    /**
     * Render migration tools page.
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'luma-product-fields' ) );
        }

        $tool = isset( $_GET['tool'] )
            ? sanitize_key( wp_unslash( (string) $_GET['tool'] ) )
            : '';

        echo '<div class="wrap">';

        if ( is_callable( [ '\\Luma\\ProductFields\\Admin\\Admin', 'show_back_button' ] ) ) {
            Admin::show_back_button();
        }

        if ( '' === $tool ) {
            self::render_tools_hub();
        } elseif ( 'meta-extractor' === $tool ) {
            self::render_meta_extractor_tool();
        } elseif ( 'category-mapper' === $tool ) {
            self::render_category_mapper_tool();
        } elseif ( 'name-extractor' === $tool ) {
            self::render_name_extractor_tool();
        } else {
            self::render_tools_hub();
        }

        echo '</div>';
    }

    /**
     * Render tool cards hub page.
     */
    protected static function render_tools_hub(): void {
        echo '<h1>' . esc_html__( 'Product Fields Migration Tools', 'luma-product-fields' ) . '</h1>';

        echo '<p>' . esc_html__(
            'Choose a migration tool. Each tool is independent and supports dry-run mode before saving.',
            'luma-product-fields'
        ) . '</p>';

        echo '<div class="luma-migration-tool-grid">';

        self::render_tool_card(
            __( 'Meta Extractor', 'luma-product-fields' ),
            __( 'Map legacy meta keys to Product Fields and convert values to structured field data.', 'luma-product-fields' ),
            'meta-extractor',
            __( 'Open Meta Extractor', 'luma-product-fields' )
        );

        self::render_tool_card(
            __( 'Category Mapper', 'luma-product-fields' ),
            __( 'Map full product category paths to taxonomy field terms for selected products.', 'luma-product-fields' ),
            'category-mapper',
            __( 'Open Category Mapper', 'luma-product-fields' )
        );

        self::render_tool_card(
            __( 'Name Extractor', 'luma-product-fields' ),
            __( 'Extract numeric values from product and variation names and store them in number fields.', 'luma-product-fields' ),
            'name-extractor',
            __( 'Open Name Extractor', 'luma-product-fields' )
        );

        echo '</div>';
    }

    /**
     * Render a tool card.
     *
     * @param string $title
     * @param string $description
     * @param string $tool_slug
     * @param string $button_label
     * @return void
     */
    protected static function render_tool_card( string $title, string $description, string $tool_slug, string $button_label ): void {
        $url = add_query_arg(
            [
                'post_type' => 'product',
                'page'      => 'luma-product-fields-migration',
                'tool'      => $tool_slug,
            ],
            admin_url( 'edit.php' )
        );

        echo '<div class="postbox luma-migration-tool-card">';
        echo '<div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div>';
        echo '<div class="inside">';
        echo '<p>' . esc_html( $description ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( $button_label ) . '</a></p>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render tool header with back link.
     *
     * @param string $title
     * @param string $description
     * @return void
     */
    protected static function render_tool_header( string $title, string $description ): void {
        $back_url = add_query_arg(
            [
                'post_type' => 'product',
                'page'      => 'luma-product-fields-migration',
            ],
            admin_url( 'edit.php' )
        );

        echo '<p><a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( 'Back to migration tools', 'luma-product-fields' ) . '</a></p>';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<p>' . esc_html( $description ) . '</p>';
    }

    /**
     * Render the current legacy meta extractor tool (renamed in UI).
     */
    protected static function render_meta_extractor_tool(): void {
        self::render_tool_header(
            __( 'Meta Extractor', 'luma-product-fields' ),
            __( 'Map existing legacy meta keys to Product Fields and migrate values.', 'luma-product-fields' )
        );

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

        if ( 'POST' === $request_method ) {
            $tool_action = isset( $_POST['lpf_migration_tool_action'] )
                ? sanitize_key( wp_unslash( (string) $_POST['lpf_migration_tool_action'] ) )
                : '';

            if ( 'meta-extractor' === $tool_action && check_admin_referer( 'luma_product_fields_fields_migration' ) ) {
                $is_dry_run    = isset( $_POST['dry_run'] );
                $skip_existing = filter_input( INPUT_POST, 'skip_existing', FILTER_VALIDATE_BOOLEAN ) ?? false;
                $should_rebuild_variation_aggregates = false;

                foreach ( $fields as $field ) {
                    $slug      = $field['slug'];
                    $map_key   = 'map_' . $slug;
                    $index_key = 'number_index_' . $slug;

                    $meta_key = isset( $_POST[ $map_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $map_key ] ) ) : '';
                    $index_value = isset( $_POST[ $index_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $index_key ] ) ) : '';

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

                    if ( $include_variations ) {
                        $should_rebuild_variation_aggregates = true;
                    }

                    $mapping[ $slug ] = [
                        'skip_existing'      => $skip_existing,
                        'meta_key'           => $meta_key,
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
                            $notice = sprintf( esc_html__( '%d products updated successfully.', 'luma-product-fields' ), $updated_count );
                        } else {
                            $notice = esc_html__( 'No products were updated.', 'luma-product-fields' );
                        }

                        if ( $should_rebuild_variation_aggregates ) {
                            $rebuilt_parent_count = self::rebuild_variation_aggregates_for_touched_variation_parents( $summary );
                            $notice .= ' ' . sprintf(
                                /* translators: %d: Number of variable parent products rebuilt. */
                                esc_html__( 'Rebuilt variation numeric aggregates for %d parent products.', 'luma-product-fields' ),
                                $rebuilt_parent_count
                            );
                        }
                    }
                }
            }
        }

        if ( ! empty( $notice ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        self::render_meta_extractor_intro();

        echo '<form method="post">';
        wp_nonce_field( 'luma_product_fields_fields_migration' );
        echo '<input type="hidden" name="lpf_migration_tool_action" value="meta-extractor">';

        echo '<table class="widefat striped">';
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
            $map_key      = 'map_' . $slug;
            $selected_key = isset( $_POST[ $map_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $map_key ] ) ) : '';
            foreach ( $distinct_keys as $key ) {
                $selected_attr = selected( $selected_key, $key, false );
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
                $index_key       = 'number_index_' . $slug;
                $selected_index  = isset( $_POST[ $index_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $index_key ] ) ) : '0';

                echo '<label>' . esc_html__( 'Which number?', 'luma-product-fields' ) . ' ';
                echo '<select name="number_index_' . esc_attr( $slug ) . '">';
                echo '<option value="0"' . selected( $selected_index, '0', false ) . '>' . esc_html__( '1st', 'luma-product-fields' ) . '</option>';
                echo '<option value="1"' . selected( $selected_index, '1', false ) . '>' . esc_html__( '2nd', 'luma-product-fields' ) . '</option>';
                echo '<option value="-1"' . selected( $selected_index, '-1', false ) . '>' . esc_html__( 'Last', 'luma-product-fields' ) . '</option>';
                echo '</select>';
                echo '</label>';

                $match_unit_key   = 'match_unit_' . $slug;
                $match_unit_check = filter_input( INPUT_POST, $match_unit_key, FILTER_VALIDATE_BOOLEAN ) ?? false;

                echo '<label>';
                echo '<input type="checkbox" name="match_unit_' . esc_attr( $slug ) . '" ' . checked( $match_unit_check, true, false ) . '> ';
                esc_html_e( 'Try to match unit', 'luma-product-fields' );
                echo '</label>';
            }

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
             esc_html__( 'Run Meta Extraction', 'luma-product-fields' ) .
             '</button></p>';

        echo '</form>';

        if ( $show_summary_ui && ! empty( $summary ) ) {
            self::render_summary_table( $summary, $fields );
        }

        self::render_meta_extractor_notes();
    }

    /**
     * Render category mapper tool.
     */
    protected static function render_category_mapper_tool(): void {
        self::render_tool_header(
            __( 'Category Mapper', 'luma-product-fields' ),
            __( 'Map full product category paths (for selected categories) into taxonomy field terms.', 'luma-product-fields' )
        );

        $fields = array_values(
            array_filter(
                Helpers::get_all_fields(),
                static function ( array $field ): bool {
                    return ! empty( $field['is_taxonomy'] ) || Helpers::is_taxonomy_field( (string) ( $field['slug'] ?? '' ) );
                }
            )
        );

        $selected_field_slug = isset( $_POST['mapper_field_slug'] )
            ? sanitize_key( wp_unslash( (string) $_POST['mapper_field_slug'] ) )
            : '';

        $selected_categories = isset( $_POST['mapper_category_ids'] )
            ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['mapper_category_ids'] ) ) ) )
            : [];

        $skip_existing = ! empty( $_POST['skip_existing'] );
        $is_dry_run    = true;
        $summary       = [];
        $notice        = '';

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : '';

        if ( 'POST' === $request_method ) {
            $tool_action = isset( $_POST['lpf_migration_tool_action'] )
                ? sanitize_key( wp_unslash( (string) $_POST['lpf_migration_tool_action'] ) )
                : '';

            if ( 'category-mapper' === $tool_action && check_admin_referer( 'luma_product_fields_category_mapper' ) ) {
                $is_dry_run = isset( $_POST['dry_run'] );

                $selected_field = self::find_field_by_slug( $fields, $selected_field_slug );

                if ( ! $selected_field ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Please choose a taxonomy field.', 'luma-product-fields' ) . '</p></div>';
                } elseif ( empty( $selected_categories ) ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Please select at least one product category.', 'luma-product-fields' ) . '</p></div>';
                } else {
                    $mapper = new CategoryToTaxonomyMapper();
                    $summary = $mapper->run(
                        [
                            'field'                 => $selected_field,
                            'selected_category_ids' => $selected_categories,
                            'skip_existing'         => $skip_existing,
                        ],
                        $is_dry_run
                    );

                    if ( ! $is_dry_run ) {
                        $updated_count = 0;
                        foreach ( $summary as $field_rows ) {
                            foreach ( $field_rows as $result ) {
                                if ( isset( $result['status'] ) && 'migrated' === $result['status'] ) {
                                    $updated_count++;
                                    break;
                                }
                            }
                        }

                        $rebuilt_parent_count = 0;
                        if ( $include_variations ) {
                            $rebuilt_parent_count = self::rebuild_variation_aggregates_for_touched_variation_parents( $summary );
                        }

                        if ( $updated_count > 0 ) {
                            $notice = sprintf( esc_html__( '%d products updated successfully.', 'luma-product-fields' ), $updated_count );
                        } else {
                            $notice = esc_html__( 'No products were updated.', 'luma-product-fields' );
                        }

                        if ( $include_variations ) {
                            $notice .= ' ' . sprintf(
                                /* translators: %d: Number of variable parent products rebuilt. */
                                esc_html__( 'Rebuilt variation numeric aggregates for %d parent products.', 'luma-product-fields' ),
                                $rebuilt_parent_count
                            );
                        }
                    }
                }
            }
        }

        if ( '' !== $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<p>' . esc_html__(
            'This tool reads product categories and uses full category paths (for example: Garnpakker / Dame) as field values.',
            'luma-product-fields'
        ) . '</p>';

        echo '<form method="post">';
        wp_nonce_field( 'luma_product_fields_category_mapper' );
        echo '<input type="hidden" name="lpf_migration_tool_action" value="category-mapper">';

        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="mapper_field_slug">' . esc_html__( 'Target taxonomy field', 'luma-product-fields' ) . '</label></th>';
        echo '<td>';
        echo '<select id="mapper_field_slug" name="mapper_field_slug">';
        echo '<option value="">' . esc_html_x( '-- Select field --', 'migration field select', 'luma-product-fields' ) . '</option>';
        foreach ( $fields as $field ) {
            $slug = (string) ( $field['slug'] ?? '' );
            $label = (string) ( $field['label'] ?? $slug );
            echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $selected_field_slug, $slug, false ) . '>' . esc_html( $label . ' (' . $slug . ')' ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Only taxonomy-based fields are available here.', 'luma-product-fields' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Only include products in categories', 'luma-product-fields' ) . '</th>';
        echo '<td>';

        $categories = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]
        );

        if ( is_wp_error( $categories ) || empty( $categories ) ) {
            echo '<p class="description">' . esc_html__( 'No product categories found.', 'luma-product-fields' ) . '</p>';
        } else {
            $sorted_category_options = [];

            foreach ( $categories as $cat ) {
                if ( ! $cat instanceof \WP_Term ) {
                    continue;
                }

                $sorted_category_options[] = [
                    'term_id' => (int) $cat->term_id,
                    'label'   => self::build_product_cat_path( $cat ),
                ];
            }

            usort(
                $sorted_category_options,
                static fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] )
            );

            echo '<fieldset>';
            echo '<legend class="screen-reader-text">' . esc_html__( 'Categories', 'luma-product-fields' ) . '</legend>';
            echo '<div class="luma-product-fields-migration-cat-list">';

            foreach ( $sorted_category_options as $category_option ) {
                echo '<label style="display:block; margin-bottom:4px;">';
                echo '<input type="checkbox" name="mapper_category_ids[]" value="' . esc_attr( (string) $category_option['term_id'] ) . '" ' . checked( in_array( (int) $category_option['term_id'], $selected_categories, true ), true, false ) . '> ';
                echo esc_html( (string) $category_option['label'] );
                echo '</label>';
            }

            echo '</div>';
            echo '</fieldset>';
        }

        echo '</td>';
        echo '</tr>';

        echo '</tbody>';
        echo '</table>';

        echo '<p><label>';
        echo '<input type="checkbox" name="skip_existing" ' . checked( $skip_existing, true, false ) . '> ';
        echo esc_html__( 'Skip if field already has a value', 'luma-product-fields' );
        echo '</label></p>';

        echo '<p><label>';
        echo '<input type="checkbox" name="dry_run" checked> ';
        echo esc_html__( 'Dry run (no changes will be saved)', 'luma-product-fields' );
        echo '</label></p>';

        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Run Category Mapping', 'luma-product-fields' ) . '</button></p>';

        echo '</form>';

        if ( $is_dry_run && ! empty( $summary ) ) {
            self::render_summary_table( $summary, $fields );
        }
    }

    /**
     * Render placeholder for next tool.
     */
    protected static function render_name_extractor_tool(): void {
        self::render_tool_header(
            __( 'Name Extractor', 'luma-product-fields' ),
            __( 'Extract numeric values from product and variation names into number fields.', 'luma-product-fields' )
        );

        $all_fields = Helpers::get_all_fields();
        $fields     = array_values(
            array_filter(
                $all_fields,
                static function ( array $field ): bool {
                    $type = (string) ( $field['type'] ?? '' );
                    return in_array( $type, [ 'number', 'integer', 'minmax' ], true );
                }
            )
        );

        $selected_field_slug = isset( $_POST['extractor_field_slug'] )
            ? sanitize_key( wp_unslash( (string) $_POST['extractor_field_slug'] ) )
            : '';

        $is_post_request    = isset( $_SERVER['REQUEST_METHOD'] )
            && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );

        $include_simple     = $is_post_request
            ? ! empty( $_POST['extractor_include_simple'] )
            : true;
        $include_variations = ! empty( $_POST['extractor_include_variations'] );
        $skip_existing      = ! empty( $_POST['skip_existing'] );
        $skip_product_ids   = self::parse_positive_int_list( $_POST['extractor_skip_product_ids'] ?? [] );
        $is_dry_run         = true;
        $summary            = [];
        $notice             = '';

        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : '';

        if ( 'POST' === $request_method ) {
            $tool_action = isset( $_POST['lpf_migration_tool_action'] )
                ? sanitize_key( wp_unslash( (string) $_POST['lpf_migration_tool_action'] ) )
                : '';

            if ( 'name-extractor' === $tool_action && check_admin_referer( 'luma_product_fields_name_extractor' ) ) {
                $is_dry_run = isset( $_POST['dry_run'] );

                $selected_field = self::find_field_by_slug( $fields, $selected_field_slug );
                if ( ! $selected_field ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Please choose a numeric field.', 'luma-product-fields' ) . '</p></div>';
                } elseif ( ! $include_simple && ! $include_variations ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose at least one source scope (products or variations).', 'luma-product-fields' ) . '</p></div>';
                } else {
                    $number_mode = isset( $_POST['extractor_number_mode'] )
                        ? sanitize_key( wp_unslash( (string) $_POST['extractor_number_mode'] ) )
                        : 'unit';

                    $number_index = isset( $_POST['extractor_number_index'] )
                        ? (int) sanitize_text_field( wp_unslash( (string) $_POST['extractor_number_index'] ) )
                        : 0;

                    $match_unit = ( 'unit' === $number_mode );

                    $extractor = new NameExtractor();
                    $summary   = $extractor->run(
                        [
                            'field'              => $selected_field,
                            'include_simple'     => $include_simple,
                            'include_variations' => $include_variations,
                            'number_index'       => $number_index,
                            'match_unit'         => $match_unit,
                            'skip_existing'      => $skip_existing,
                            'exclude_product_ids' => $skip_product_ids,
                        ],
                        $is_dry_run
                    );

                    if ( ! $is_dry_run ) {
                        $updated_count = 0;
                        foreach ( $summary as $field_rows ) {
                            foreach ( $field_rows as $result ) {
                                if ( isset( $result['status'] ) && 'migrated' === $result['status'] ) {
                                    $updated_count++;
                                    break;
                                }
                            }
                        }

                        $rebuilt_parent_count = 0;
                        if ( $include_variations ) {
                            $rebuilt_parent_count = self::rebuild_variation_aggregates_for_touched_variation_parents( $summary );
                        }

                        if ( $updated_count > 0 ) {
                            $notice = sprintf( esc_html__( '%d products updated successfully.', 'luma-product-fields' ), $updated_count );
                        } else {
                            $notice = esc_html__( 'No products were updated.', 'luma-product-fields' );
                        }

                        if ( $include_variations ) {
                            $notice .= ' ' . sprintf(
                                /* translators: %d: Number of variable parent products rebuilt. */
                                esc_html__( 'Rebuilt variation numeric aggregates for %d parent products.', 'luma-product-fields' ),
                                $rebuilt_parent_count
                            );
                        }
                    }
                }
            }
        }

        if ( '' !== $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<p>' . esc_html__(
            'This tool extracts numeric values from product names. If the field has a configured unit, choose unit-based extraction first; otherwise choose number position.',
            'luma-product-fields'
        ) . '</p>';

        echo '<p class="description">' . esc_html__(
            'After a dry run, tick "Skip this" for rows you want to exclude, then run again.',
            'luma-product-fields'
        ) . '</p>';

        $selected_number_mode = isset( $_POST['extractor_number_mode'] )
            ? sanitize_key( wp_unslash( (string) $_POST['extractor_number_mode'] ) )
            : 'unit';
        $selected_number_index = isset( $_POST['extractor_number_index'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['extractor_number_index'] ) )
            : '0';

        echo '<form method="post">';
        wp_nonce_field( 'luma_product_fields_name_extractor' );
        echo '<input type="hidden" name="lpf_migration_tool_action" value="name-extractor">';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="extractor_field_slug">' . esc_html__( 'Target numeric field', 'luma-product-fields' ) . '</label></th>';
        echo '<td>';
        echo '<select id="extractor_field_slug" name="extractor_field_slug">';
        echo '<option value="">' . esc_html_x( '-- Select field --', 'extractor field select', 'luma-product-fields' ) . '</option>';
        foreach ( $fields as $field ) {
            $slug  = (string) ( $field['slug'] ?? '' );
            $label = (string) ( $field['label'] ?? $slug );
            echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $selected_field_slug, $slug, false ) . '>' . esc_html( $label . ' (' . $slug . ')' ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Only number, integer, and range fields are available.', 'luma-product-fields' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Source scope', 'luma-product-fields' ) . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="extractor_include_simple" ' . checked( $include_simple, true, false ) . '> ' . esc_html__( 'Products (simple/variable/grouped)', 'luma-product-fields' ) . '</label><br>';
        echo '<label><input type="checkbox" name="extractor_include_variations" ' . checked( $include_variations, true, false ) . '> ' . esc_html__( 'Variations', 'luma-product-fields' ) . '</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__( 'Extraction mode', 'luma-product-fields' ) . '</th>';
        echo '<td>';
        echo '<label><input type="radio" name="extractor_number_mode" value="unit" ' . checked( $selected_number_mode, 'unit', false ) . '> ' . esc_html__( 'Match configured field unit aliases', 'luma-product-fields' ) . '</label><br>';
        echo '<label><input type="radio" name="extractor_number_mode" value="position" ' . checked( $selected_number_mode, 'position', false ) . '> ' . esc_html__( 'Use number position', 'luma-product-fields' ) . '</label>';
        echo '<div style="margin-top:8px;">';
        echo '<label>' . esc_html__( 'Which number?', 'luma-product-fields' ) . ' ';
        echo '<select name="extractor_number_index">';
        echo '<option value="0"' . selected( $selected_number_index, '0', false ) . '>' . esc_html__( '1st', 'luma-product-fields' ) . '</option>';
        echo '<option value="1"' . selected( $selected_number_index, '1', false ) . '>' . esc_html__( '2nd', 'luma-product-fields' ) . '</option>';
        echo '<option value="-1"' . selected( $selected_number_index, '-1', false ) . '>' . esc_html__( 'Last', 'luma-product-fields' ) . '</option>';
        echo '</select>';
        echo '</label>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        echo '<p><label>';
        echo '<input type="checkbox" name="skip_existing" ' . checked( $skip_existing, true, false ) . '> ';
        echo esc_html__( 'Skip if field already has a value', 'luma-product-fields' );
        echo '</label></p>';

        echo '<p><label><input type="checkbox" name="dry_run" checked> ';
        echo esc_html__( 'Dry run (no changes will be saved)', 'luma-product-fields' );
        echo '</label></p>';

        if ( $is_dry_run && ! empty( $summary ) ) {
            self::render_summary_table_with_options(
                $summary,
                $all_fields,
                [
                    'allow_skip_selection'      => true,
                    'selected_skip_product_ids' => $skip_product_ids,
                ]
            );
        }

        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Run Name Extraction', 'luma-product-fields' ) . '</button></p>';

        echo '</form>';
    }

    /**
     * Find field in local list by slug.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param string $slug
     * @return array<string,mixed>|null
     */
    protected static function find_field_by_slug( array $fields, string $slug ): ?array {
        foreach ( $fields as $field ) {
            if ( (string) ( $field['slug'] ?? '' ) === $slug ) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Build full category path label.
     *
     * @param \WP_Term $term
     * @return string
     */
    protected static function build_product_cat_path( \WP_Term $term ): string {
        $parts     = [];
        $ancestors = array_reverse( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) );

        foreach ( $ancestors as $ancestor_id ) {
            $ancestor = get_term( (int) $ancestor_id, 'product_cat' );
            if ( $ancestor instanceof \WP_Term ) {
                $parts[] = $ancestor->name;
            }
        }

        $parts[] = $term->name;

        return implode( ' / ', array_filter( $parts ) );
    }

    /**
     * Retrieve distinct meta keys from the postmeta table.
     *
     * @return array<int,string>
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
     * Render introductory text for meta extractor.
     */
    protected static function render_meta_extractor_intro(): void {
        echo '<div class="luma-migration-intro">';
        echo '<p>';
        echo esc_html__(
            'This tool migrates existing product meta values into Luma Product Fields. During migration, existing meta values are read and converted to match the structure required by each field type.',
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
     * Render notes for meta extractor.
     */
    protected static function render_meta_extractor_notes(): void {
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
            'Original meta values are not deleted or modified. If other plugins or custom code continue to write to old meta keys, those changes are not reflected automatically.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '<li>';
        echo esc_html__(
            'Conversion is best-effort. Values that do not match expected field formats may be skipped or partially converted.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '<li>';
        echo esc_html__(
            'Review a subset of products after migration before relying on migrated values.',
            'luma-product-fields'
        );
        echo '</li>';

        echo '</ul>';
        echo '</div>';
    }

    /**
     * Render migration summary as a compact table.
     *
     * @param array<int,array<string,array<string,mixed>>> $summary
     * @param array<int,array<string,mixed>> $fields
     */
    protected static function render_summary_table( array $summary, array $fields ): void {
        self::render_summary_table_with_options( $summary, $fields, [] );
    }

    /**
     * Render migration summary as a compact table with optional controls.
     *
     * @param array<int,array<string,array<string,mixed>>> $summary
     * @param array<int,array<string,mixed>>               $fields
     * @param array<string,mixed>                          $options
     */
    protected static function render_summary_table_with_options( array $summary, array $fields, array $options ): void {
        $field_labels = [];
        $allow_skip_selection = ! empty( $options['allow_skip_selection'] );
        $selected_skip_ids    = self::parse_positive_int_list( $options['selected_skip_product_ids'] ?? [] );
        $selected_skip_lookup = array_fill_keys( $selected_skip_ids, true );

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

        foreach ( $summary as $field_results ) {
            foreach ( $field_results as $row ) {
                $status = $row['status'] ?? '';

                if ( 'migrated' === $status ) {
                    $counts['migrated']++;
                } elseif ( 'dry-run' === $status ) {
                    $counts['dry_run']++;
                } elseif ( 'skipped' === $status && ( $row['reason'] ?? '' ) === 'Existing value present' ) {
                    $counts['skipped_existing']++;
                } elseif ( 'skipped' === $status ) {
                    $counts['skipped_invalid']++;
                } elseif ( strpos( (string) $status, 'external save' ) !== false ) {
                    $counts['external_save']++;
                }
            }
        }

        echo '<h2>' . esc_html__( 'Migration Result (dry run)', 'luma-product-fields' ) . '</h2>';

        echo '<div class="luma-product-fields-migration-counters">';
        echo '<p><strong>' . esc_html__( 'Migration Summary', 'luma-product-fields' ) . '</strong></p>';
        echo '<ul class="lumaprfi-counters-list">';
        echo '<li><span class="luma-product-fields-count lumaprfi-count-green">' . esc_html( (string) $counts['migrated'] ) . '</span> ' . esc_html__( 'migrated', 'luma-product-fields' ) . '</li>';
        echo '<li><span class="luma-product-fields-count lumaprfi-count-blue">' . esc_html( (string) $counts['dry_run'] ) . '</span> ' . esc_html__( 'dry-run changes', 'luma-product-fields' ) . '</li>';
        echo '<li><span class="luma-product-fields-count lumaprfi-count-orange">' . esc_html( (string) $counts['skipped_existing'] ) . '</span> ' . esc_html__( 'skipped (existing value)', 'luma-product-fields' ) . '</li>';
        echo '<li><span class="luma-product-fields-count lumaprfi-count-gray">' . esc_html( (string) $counts['skipped_invalid'] ) . '</span> ' . esc_html__( 'skipped (no valid data)', 'luma-product-fields' ) . '</li>';
        if ( $counts['external_save'] > 0 ) {
            echo '<li><span class="luma-product-fields-count lumaprfi-count-purple">' . esc_html( (string) $counts['external_save'] ) . '</span> ' . esc_html__( 'handled by external save callback', 'luma-product-fields' ) . '</li>';
        }
        echo '</ul>';
        echo '</div>';

        echo '<div style="overflow:auto; max-height:600px; border:1px solid #ccc; padding:1em;">';
        echo '<table class="widefat fixed striped luma-product-fields-summary-table">';
        echo '<thead><tr>';
        if ( $allow_skip_selection ) {
            echo '<th>' . esc_html__( 'Skip this', 'luma-product-fields' ) . '</th>';
        }
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

                $product_link = get_edit_post_link( (int) $product_id );
                $product_cell = $product_link
                    ? '<a href="' . esc_url( $product_link ) . '">#' . (int) $product_id . '</a>'
                    : '#' . (int) $product_id;

                $field_label = $field_labels[ $slug ] ?? $slug;

                $status = (string) ( $result['status'] ?? '' );
                $orig   = $result['original'] ?? '';
                $new    = $result['new'] ?? '';
                $existing_val = $result['existing'] ?? '';
                $reason = (string) ( $result['reason'] ?? '' );

                if ( is_array( $orig ) ) {
                    $orig = wp_json_encode( $orig );
                }
                if ( is_array( $new ) ) {
                    $new = wp_json_encode( $new );
                }
                if ( is_array( $existing_val ) ) {
                    $existing_val = wp_json_encode( $existing_val );
                }

                if ( 'migrated' === $status ) {
                    $cls = 'lumaprfi-status-migrated';
                } elseif ( 'dry-run' === $status ) {
                    $cls = 'lumaprfi-status-dry-run';
                } elseif ( 'skipped' === $status && 'Existing value present' === $reason ) {
                    $cls = 'lumaprfi-status-skipped-exists';
                } elseif ( strpos( $status, 'external save' ) !== false ) {
                    $cls = 'lumaprfi-status-external';
                } else {
                    $cls = 'lumaprfi-status-skipped';
                }

                echo '<tr class="' . esc_attr( $cls ) . '">';
                if ( $allow_skip_selection ) {
                    $is_checked = isset( $selected_skip_lookup[ (int) $product_id ] );
                    echo '<td><label><input type="checkbox" name="extractor_skip_product_ids[]" value="' . esc_attr( (string) (int) $product_id ) . '" ' . checked( $is_checked, true, false ) . '> ' . esc_html__( 'Skip', 'luma-product-fields' ) . '</label></td>';
                }
                echo '<td>' . wp_kses_post( $product_cell ) . '</td>';
                echo '<td><strong>' . esc_html( (string) $field_label ) . '</strong><br><code>' . esc_html( (string) $slug ) . '</code></td>';
                echo '<td>' . esc_html( $status ) . '</td>';
                echo '<td>' . esc_html( (string) $orig ) . '</td>';
                echo '<td>' . esc_html( (string) $existing_val ) . '</td>';
                echo '<td>' . esc_html( (string) $new ) . '</td>';
                echo '<td>' . esc_html( $reason ) . '</td>';
                echo '</tr>';
            }
        }

        if ( ! $rows ) {
            $colspan = $allow_skip_selection ? 8 : 7;
            echo '<tr><td colspan="' . esc_attr( (string) $colspan ) . '">' . esc_html__( 'No changes were detected in this dry run.', 'luma-product-fields' ) . '</td></tr>';
        }

        echo '</tbody></table>';

        if ( $truncated ) {
            echo '<p><em>' . esc_html__( 'Showing only the first 500 rows.', 'luma-product-fields' ) . '</em></p>';
        }

        echo '</div>';
    }

    /**
     * Parse a mixed value into unique positive integer IDs.
     *
     * @param mixed $raw
     * @return array<int,int>
     */
    protected static function parse_positive_int_list( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $ids = [];
        foreach ( $raw as $raw_id ) {
            $id = absint( $raw_id );
            if ( $id > 0 ) {
                $ids[ $id ] = $id;
            }
        }

        return array_values( $ids );
    }

    /**
     * Rebuild variation numeric aggregates for parent products touched by variation rows.
     *
     * @param array<int,array<string,array<string,mixed>>> $summary
     * @return int Number of parent products rebuilt.
     */
    protected static function rebuild_variation_aggregates_for_touched_variation_parents( array $summary ): int {
        $parent_ids = [];

        foreach ( $summary as $product_id => $field_results ) {
            $product_id = (int) $product_id;
            if ( $product_id <= 0 || 'product_variation' !== get_post_type( $product_id ) ) {
                continue;
            }

            if ( empty( $field_results ) ) {
                continue;
            }

            $parent_id = (int) wp_get_post_parent_id( $product_id );
            if ( $parent_id > 0 ) {
                $parent_ids[ $parent_id ] = true;
            }
        }

        foreach ( array_keys( $parent_ids ) as $parent_id ) {
            VariationNumericAggregates::rebuild_for_parent( (int) $parent_id );
        }

        return count( $parent_ids );
    }
}
