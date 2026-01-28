<?php

namespace Luma\ProductFields\Admin;

use Luma\ProductFields\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Onboarding helper for admin UI.
 *
 * Currently only shows a one-time welcome notice.
 */
class Onboarding {

    public const OPTION_WELCOME_DISMISSED = 'luma_product_fields_onboarding_welcome_dismissed';


    /**
     * Initialize onboarding hooks.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_init', [ static::class, 'handle_dismiss' ] );
        add_action( 'admin_notices', [ static::class, 'maybe_render_welcome_notice' ] );
    }
    
    
    /**
     * Handle dismissal of the welcome notice.
     *
     * @return void
     */
    public static function handle_dismiss(): void {
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'luma_product_fields_dismiss_welcome' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $dismiss = isset( $_GET['luma_product_fields_dismiss_welcome'] )
            ? absint( wp_unslash( $_GET['luma_product_fields_dismiss_welcome'] ) )
            : 0;

        if ( 1 !== $dismiss ) {
            return;
        }

        update_option( static::OPTION_WELCOME_DISMISSED, 'yes' );
    }


    
    
    /**
     * Render the welcome notice on first use.
     *
     * @return void
     */
    public static function maybe_render_welcome_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( 'yes' === get_option( static::OPTION_WELCOME_DISMISSED, '' ) ) {
            return;
        }

        $screen = get_current_screen();

        if ( empty( $screen ) ) {
            return;
        }

        $allowed_screens = [
            'plugins',
            'edit-product',
            'product',
        ];

        if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'luma_product_fields_dismiss_welcome', '1' ),
            'luma_product_fields_dismiss_welcome'
        );

        $fields_url = admin_url( 'edit.php?post_type=product&page=luma-product-fields' );

        $settings_url = add_query_arg(
            [
                'page'    => 'wc-settings',
                'tab'     => 'products',
                'section' => 'luma_product_fields',
            ],
            admin_url( 'admin.php' )
        );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>
                    <?php esc_html_e( 'Luma Product Fields is now active.', 'luma-product-fields' ); ?>
                </strong>
            </p>
            <p>
                <?php esc_html_e( 'Define product fields per product group under Products → Product Fields, and control frontend output under WooCommerce → Settings → Products.', 'luma-product-fields' ); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( $fields_url ); ?>">
                    <?php esc_html_e( 'Open Product Fields', 'luma-product-fields' ); ?>
                </a>
                <a class="button" href="<?php echo esc_url( $settings_url ); ?>">
                    <?php esc_html_e( 'Open Settings', 'luma-product-fields' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>">
                    <?php esc_html_e( 'Dismiss', 'luma-product-fields' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
