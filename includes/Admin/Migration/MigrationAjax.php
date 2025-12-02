<?php
namespace Luma\ProductFields\Admin\Migration;

class MigrationAjax {

    /**
     * Register ajax handlers.
     */
    public static function register(): void {
        add_action(
            'Luma\ProductFields\incoming_ajax_migration_meta_preview',
            [ static::class, 'preview_meta_values' ]
        );
    }

    public static function preview_meta_values(): void {
        check_ajax_referer( 'luma_product_fields_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error([ 'error' => 'Access denied' ]);
        }

        $meta_key = sanitize_text_field( $_POST['meta_key'] ?? '' );
        $limit    = max( 1, min( (int)($_POST['limit'] ?? 10), 50 ) );

        global $wpdb;

        $values = $wpdb->get_col(
            $wpdb->prepare(
                "
                SELECT DISTINCT pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type IN ('product', 'product_variation')
                AND pm.meta_key = %s
                LIMIT %d
                ",
                $meta_key,
                $limit
            )
        ) ?? [];

        wp_send_json_success([
            'meta_key' => $meta_key,
            'values'   => array_values( $values )
        ]);
    }
}
