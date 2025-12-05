<?php
namespace Luma\ProductFields\Taxonomy;

defined( 'ABSPATH' ) || exit;

use WP_Term;

/**
 * Manages shared term meta for taxonomies:
 * - Featured flag (boolean, stored as 'yes'/'no')
 * - Term thumbnail (attachment ID, meta key 'woocommerce_thumbnail')
 *
 * Wires admin UI, saving, list table columns, and helper methods.
 *
 * @since 3.1.0
 */
class TermMetaManager {

    /** @var string Meta key for featured flag */
    public const META_FEATURED = '_lpf_featured';


    /** @var string Meta key for term thumbnail (attachment ID), matches WooCommerce format */
    private const META_THUMBNAIL = 'woocommerce_thumbnail';


    /**
     * Bootstraps listeners.
     *
     * Call from your plugin bootstrap, e.g. in the main loader or TaxonomyManager::init().
     *
     * @since 1.7.0
     * @return void
     */
    public static function init(): void {
        add_action( 'Luma\ProductFields\taxonomy_registered', [ __CLASS__, 'register_for_taxonomy' ], 10, 2 );
        add_action( 'Luma\ProductFields\incoming_ajax_toggle_featured_term', [ __CLASS__ , 'toggle_featured_term'] );
        add_action( 'woocommerce_before_subcategory_title', [ __CLASS__, 'maybe_render_thumbnail_for_loop' ], 9 );
        add_action( 'init', [ __CLASS__, 'register_term_meta' ] );
    }


    /**
     * Registers admin UI and save handlers for a specific taxonomy.
     *
     * @since 1.7.0
     * @param string $taxonomy Taxonomy slug.
     * @param array  $args     The args used in register_taxonomy().
     * @return void
     */
    public static function register_for_taxonomy( string $taxonomy, array $args = [] ): void {
        // Add / Edit UI.
        add_action( "{$taxonomy}_add_form_fields", [ __CLASS__, 'render_add_fields' ] );
        add_action( "{$taxonomy}_edit_form_fields", [ __CLASS__, 'render_edit_fields' ], 10, 2 );

        // Save handlers.
        add_action( "created_{$taxonomy}", [ __CLASS__, 'save_term_meta_on_create' ] );
        add_action( "edited_{$taxonomy}", [ __CLASS__, 'save_term_meta_on_edit' ] );

        // List table columns.
        add_filter( "manage_edit-{$taxonomy}_columns", [ __CLASS__, 'filter_columns' ] );
        add_filter( "manage_{$taxonomy}_custom_column", [ __CLASS__, 'render_custom_column' ], 10, 3 );

        // Admin assets for media frame.
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
    }


    /**
     * Registers the two meta keys globally so they work with REST and meta queries.
     *
     * @since 1.7.0
     * @return void
     */
    public static function register_term_meta(): void {
        register_term_meta(
            '',
            self::META_FEATURED,
            [
                'type'              => 'string',
                'single'            => true,
                'sanitize_callback' => [ __CLASS__, 'sanitize_yes_no' ],
                'show_in_rest'      => true,
                'auth_callback'     => function () { return current_user_can( 'manage_woocommerce' ); },
            ]
        );

        register_term_meta(
            '',
            self::META_THUMBNAIL,
            [
                'type'              => 'integer',
                'single'            => true,
                'sanitize_callback' => 'absint',
                'show_in_rest'      => true,
                'auth_callback'     => function () { return current_user_can( 'manage_woocommerce' ); },
            ]
        );
    }


    /**
     * Admin UI: extra fields on the "Add New Term" screen.
     *
     * @since 1.7.0
     * @param string $taxonomy
     * @return void
     */
    public static function render_add_fields( string $taxonomy ): void { ?>
        <div class="form-field term-featured-wrap">
            <label for="lpf-featured"><?php esc_html_e( 'Featured', 'luma-product-fields' ); ?></label>
            <input type="checkbox" name="<?php echo esc_attr( self::META_FEATURED ); ?>" id="lpf-featured" value="yes">
            <p class="description"><?php esc_html_e( 'Mark this term as featured.', 'luma-product-fields' ); ?></p>
        </div>

        <div class="form-field term-thumbnail-wrap">
            <label for="lpf-term-thumbnail"><?php esc_html_e( 'Term thumbnail', 'luma-product-fields' ); ?></label>
            <div class="lpf-term-thumb-control">
                <input type="hidden" name="<?php echo esc_attr( self::META_THUMBNAIL ); ?>" id="lpf-term-thumbnail" value="">
                <div class="lpf-term-thumb-preview" style="margin-bottom:8px;"></div>
                <button type="button" class="button lpf-term-thumb-upload"><?php esc_html_e( 'Upload / Choose image', 'luma-product-fields' ); ?></button>
                <button type="button" class="button lpf-term-thumb-remove" style="display:none;"><?php esc_html_e( 'Remove', 'luma-product-fields' ); ?></button>
            </div>
            <p class="description"><?php esc_html_e( 'Select a thumbnail image for this term.', 'luma-product-fields' ); ?></p>
        </div>
    <?php
    }


    /**
     * Admin UI: extra fields on the "Edit Term" screen.
     *
     * @since 1.7.0
     * @param WP_Term $term
     * @param string  $taxonomy
     * @return void
     */
    public static function render_edit_fields( WP_Term $term, string $taxonomy ): void {
        $featured  = get_term_meta( $term->term_id, self::META_FEATURED, true ) === 'yes';
        $thumb_id  = (int) get_term_meta( $term->term_id, self::META_THUMBNAIL, true );
        $thumb_src = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'thumbnail' ) : false; ?>
        <tr class="form-field term-featured-wrap">
            <th scope="row"><label for="lpf-featured"><?php esc_html_e( 'Featured', 'luma-product-fields' ); ?></label></th>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr( self::META_FEATURED ); ?>" id="lpf-featured" value="yes" <?php checked( $featured ); ?>> <?php esc_html_e( 'Mark this term as featured.', 'luma-product-fields' ); ?></label>
            </td>
        </tr>

        <tr class="form-field term-thumbnail-wrap">
            <th scope="row"><label for="lpf-term-thumbnail"><?php esc_html_e( 'Term thumbnail', 'luma-product-fields' ); ?></label></th>
            <td>
                <div class="lpf-term-thumb-control">
                    <input type="hidden" name="<?php echo esc_attr( self::META_THUMBNAIL ); ?>" id="lpf-term-thumbnail" value="<?php echo esc_attr( $thumb_id ); ?>">
                    <div class="lpf-term-thumb-preview" style="margin-bottom:8px;">
                        <?php if ( $thumb_src ) { echo wp_get_attachment_image( $thumb_id, 'woocommerce_gallery_thumbnail' ); } ?>
                    </div>
                    <button type="button" class="button lpf-term-thumb-upload"><?php esc_html_e( 'Upload / Choose image', 'luma-product-fields' ); ?></button>
                    <button type="button" class="button lpf-term-thumb-remove" <?php echo $thumb_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'luma-product-fields' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'Select a thumbnail image for this term.', 'luma-product-fields' ); ?></p>
            </td>
        </tr>
    <?php
    }


    /**
     * Save handler for "Add New Term".
     *
     * @since 1.7.0
     * @param int $term_id
     * @return void
     */
    public static function save_term_meta_on_create( int $term_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $featured_raw = isset( $_POST[ self::META_FEATURED ] ) ? wp_unslash( $_POST[ self::META_FEATURED ] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $thumb_raw    = isset( $_POST[ self::META_THUMBNAIL ] ) ? wp_unslash( $_POST[ self::META_THUMBNAIL ] ) : '';

        $source = [
            self::META_FEATURED  => sanitize_text_field( $featured_raw ),
            self::META_THUMBNAIL => absint( $thumb_raw ),
        ];

        self::persist_featured( $term_id, $source );
        self::persist_thumbnail( $term_id, $source );
    }


    /**
     * Save handler for "Edit Term".
     *
     * @since 1.7.0
     * @param int $term_id
     * @return void
     */
    public static function save_term_meta_on_edit( int $term_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $featured_raw = isset( $_POST[ self::META_FEATURED ] ) ? wp_unslash( $_POST[ self::META_FEATURED ] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $thumb_raw    = isset( $_POST[ self::META_THUMBNAIL ] ) ? wp_unslash( $_POST[ self::META_THUMBNAIL ] ) : '';

        $source = [
            self::META_FEATURED  => sanitize_text_field( $featured_raw ),
            self::META_THUMBNAIL => absint( $thumb_raw ),
        ];

        self::persist_featured( $term_id, $source );
        self::persist_thumbnail( $term_id, $source );
    }


    /**
     * Adds "Featured" (⭐) and "Thumbnail" columns.
     *
     * @since 1.7.2
     * @param array $columns
     * @return array
     */
    public static function filter_columns( array $columns ): array {
        $injected = [];

        foreach ( $columns as $key => $label ) {
            if ( 'cb' === $key ) {
                $injected[ $key ] = $label;
                continue;
            }

            if ( 'name' === $key ) {
                $injected[ $key ]      = $label;
                $injected['featured']  = __( 'Fav', 'luma-product-fields' );
                $injected['thumbnail'] = __( 'Thumb', 'luma-product-fields' );
                continue;
            }

            $injected[ $key ] = $label;
        }

        return $injected;
    }


    /**
     * Renders custom column content for Featured and Thumbnail.
     *
     * @since 1.7.2
     * @param string $out
     * @param string $column_name
     * @param int    $term_id
     * @return void
    */
    public static function render_custom_column( $out, string $column_name, int $term_id ): void {
        if ( 'featured' === $column_name ) {
            $is_featured = get_term_meta( $term_id, self::META_FEATURED, true ) === 'yes';
            $icon_class  = $is_featured ? 'dashicons-star-filled' : 'dashicons-star-empty';

            echo '<a href="#" class="lpf-toggle-featured" data-term-id="' . esc_attr( $term_id ) . '" aria-label="' . esc_attr( $is_featured ? __( 'Unfeature term', 'luma-product-fields' ) : __( 'Feature term', 'luma-product-fields' ) ) . '">';
            echo '<span class="dashicons ' . esc_attr( $icon_class ) . '" aria-hidden="true"></span>';
            echo '</a>';
            return;
        }

        if ( 'thumbnail' === $column_name ) {
            $id = (int) get_term_meta( $term_id, self::META_THUMBNAIL, true );
            if ( $id ) {
                echo wp_get_attachment_image( $id, [ 48, 48 ], false, [ 'style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px;' ] );
            }
            return;
        }
    }


    /**
     * Enqueues media frame and a robust controller for the term thumbnail UI.
     *
     * @since 1.7.1
     * @return void
     */
    public static function enqueue_admin_assets(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return;
        }
        if ( ! in_array( $screen->base, [ 'edit-tags', 'term' ], true ) ) {
            return;
        }

        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        $handle = 'lpf-term-meta';
        wp_register_script(
            $handle,
            false,
            [ 'jquery', 'media-editor' ],
            '1.0',
            true
        );
        wp_enqueue_script( $handle );

        ob_start();
        ?>
        jQuery(function($){
            function bindControl($wrap){
                var frame;
                var $input     = $wrap.find('#lpf-term-thumbnail');
                var $preview   = $wrap.find('.lpf-term-thumb-preview');
                var $btnUpload = $wrap.find('.lpf-term-thumb-upload');
                var $btnRemove = $wrap.find('.lpf-term-thumb-remove');

                $btnUpload.on('click', function(e){
                    e.preventDefault();

                    if (frame) {
                        frame.open();
                        return;
                    }

                    frame = wp.media({
                        title: 'Choose image',
                        button: { text: 'Use this image' },
                        multiple: false
                    });

                    frame.on('select', function(){
                        var selection  = frame.state().get('selection');
                        var model      = selection.first ? selection.first() : null;
                        var attachment = model && model.toJSON ? model.toJSON() : null;
                        if (!attachment) {
                            return;
                        }

                        var thumbUrl = '';
                        if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                            thumbUrl = attachment.sizes.thumbnail.url;
                        } else if (attachment.url) {
                            thumbUrl = attachment.url;
                        }

                        $input.val(attachment.id);
                        if (thumbUrl) {
                            $preview.html(
                                '<img src="' +
                                thumbUrl.replace(/"/g, '&quot;') +
                                '" style="max-width:150px;height:auto;border-radius:4px;" />'
                            );
                        } else {
                            $preview.empty();
                        }
                        $btnRemove.show();
                    });

                    frame.open();
                });

                $btnRemove.on('click', function(e){
                    e.preventDefault();
                    $input.val('');
                    $preview.empty();
                    $btnRemove.hide();
                });
            }

            jQuery('.lpf-term-thumb-control').each(function(){
                bindControl(jQuery(this));
            });

            jQuery(document).on('ajaxComplete', function(){
                jQuery('.lpf-term-thumb-control').each(function(){
                    var $wrap = jQuery(this);
                    if ( ! $wrap.data('fkBound') ) {
                        bindControl($wrap);
                        $wrap.data('fkBound', true);
                    }
                });
            });
        });
        <?php
        $js = ob_get_clean();

        wp_add_inline_script( $handle, $js, 'after' );
    }


    /**
     * Persist the featured checkbox.
     *
     * @since 1.7.0
     * @param int   $term_id
     * @param array $source
     * @return void
     */
    private static function persist_featured( int $term_id, array $source ): void {
        $val = isset( $source[ self::META_FEATURED ] ) && 'yes' === $source[ self::META_FEATURED ] ? 'yes' : 'no';
        update_term_meta( $term_id, self::META_FEATURED, $val );
    }


    /**
     * Persist the thumbnail attachment ID.
     *
     * @since 1.7.0
     * @param int   $term_id
     * @param array $source
     * @return void
     */
    private static function persist_thumbnail( int $term_id, array $source ): void {
        $id = isset( $source[ self::META_THUMBNAIL ] ) ? absint( $source[ self::META_THUMBNAIL ] ) : 0;
        if ( $id ) {
            update_term_meta( $term_id, self::META_THUMBNAIL, $id );
        } else {
            delete_term_meta( $term_id, self::META_THUMBNAIL );
        }
    }


    /**
     * Sanitize helper: normalize to 'yes' or 'no'.
     *
     * @since 1.7.0
     * @param mixed $value
     * @return string
     */
    public static function sanitize_yes_no( $value ): string {
        return (string) $value === 'yes' ? 'yes' : 'no';
    }


    /**
     * Helper: Is term featured?
     *
     * @since 1.7.0
     * @param int|WP_Term $term
     * @return bool
     */
    public static function is_featured( $term ): bool {
        $term_id = $term instanceof WP_Term ? $term->term_id : absint( $term );
        return get_term_meta( $term_id, self::META_FEATURED, true ) === 'yes';
    }


    /**
     * Helper: Get term thumbnail ID.
     *
     * @since 1.7.0
     * @param int|WP_Term $term
     * @return int Attachment ID or 0
     */
    public static function get_thumbnail_id( $term ): int {
        $term_id = $term instanceof WP_Term ? $term->term_id : absint( $term );
        return (int) get_term_meta( $term_id, self::META_THUMBNAIL, true );
    }


    /**
     * Helper: Get thumbnail URL (or empty string).
     *
     * @since 1.7.0
     * @param int|WP_Term $term
     * @param string      $size
     * @return string
     */
    public static function get_thumbnail_url( $term, string $size = 'thumbnail' ): string {
        $id = self::get_thumbnail_id( $term );
        if ( ! $id ) {
            return '';
        }
        $src = wp_get_attachment_image_src( $id, $size );
        return $src ? (string) $src[0] : '';
    }


    /**
     * Helper: Fetch featured terms for a taxonomy (meta_query wrapper).
     *
     * @since 1.7.0
     * @param string $taxonomy
     * @param array  $args Extra WP_Term_Query args.
     * @return array<WP_Term>
     */
    public static function get_featured_terms( string $taxonomy, array $args = [] ): array {
        $q = new \WP_Term_Query( wp_parse_args( $args, [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'meta_query' => [  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                [
                    'key'     => self::META_FEATURED,
                    'value'   => 'yes',
                    'compare' => '=',
                ],
            ],
        ] ) );

        return is_array( $q->terms ) ? $q->terms : [];
    }
    
    
    
    /**
     * Toggle featured flag for a taxonomy term (admin list table star).
     *
     * Expects POST:
     * - term_id (int)
     *
     * Security:
     * - Nonce checked in handle_request() via 'luma_product_fields_admin_nonce'
     * - Capability: manage_woocommerce
     *
     * Response:
     * {
     *   success: true,
     *   data: {
     *     term_id: 123,
     *     featured: "yes"|"no"
     *   }
     * }
     *
     * @since 3.x
     *
     * @return void
     */
    public static function toggle_featured_term(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error(
                [ 'message' => __( 'Insufficient permissions.', 'luma-product-fields' ) ],
                403
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

        if ( ! $term_id ) {
            wp_send_json_error(
                [ 'message' => __( 'Missing term_id.', 'luma-product-fields' ) ],
                400
            );
        }

        $current = get_term_meta( $term_id, self::META_FEATURED, true ) === 'yes';
        $next    = $current ? 'no' : 'yes';

        update_term_meta( $term_id, self::META_FEATURED, $next );

        wp_send_json_success(
            [
                'term_id'  => $term_id,
                'featured' => $next,
            ]
        );
    }


    /**
     * Print a thumbnail for non-product_cat taxonomies in subcategory loops,
     * using our 'woocommerce_thumbnail' term meta. Runs before Woo's own title hook.
     *
     * @since 1.7.x
     * @param \WP_Term $term
     * @return void
     */
    public static function maybe_render_thumbnail_for_loop( $term ): void {
        if ( ! ( $term instanceof \WP_Term ) ) {
            return;
        }

        if ( 'product_cat' === $term->taxonomy ) {
            return;
        }

        $thumb_id = (int) get_term_meta( $term->term_id, self::META_THUMBNAIL, true );
        if ( ! $thumb_id ) {
            if ( function_exists( 'wc_placeholder_img' ) ) {
                echo wp_kses_post( wc_placeholder_img( 'woocommerce_thumbnail' ) );
            }
            return;
        }

        echo wp_get_attachment_image(
            $thumb_id,
            'woocommerce_thumbnail',
            false,
            [
                'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail',
                'alt'   => $term->name,
            ]
        );
    }



}
