<?php
/**
 * Notification Manager
 *
 * Handles admin notices across the plugin. Stores notices per-user and 
 * displays them through the WordPress `admin_notices` hook. Supports 
 * contextual filtering, multiple notices, and custom messages via filters.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class NotificationManager
 *
 * Provides a centralized system for managing and rendering admin notices.
 * Notices persist across redirects using user meta, and are automatically
 * displayed once and then cleared.
 */
class NotificationManager
{
    /**
     * User meta key where notices are stored.
     */
    protected const META_KEY = 'luma_product_fields_notices';



    /**
     * Registers the render callback on the admin_notices hook.
     *
     * @return void
     */
    public static function init(): void
    {       
        add_action( 'admin_notices', [ __CLASS__, 'render' ] );
    }



    /**
     * Add a new notice to the user's notice stack.
     *
     * Expected structure:
     * [
     *     'type'        => 'success' | 'error' | 'warning' | 'info',
     *     'message'     => 'string message',
     *     'context'     => 'optional string for filtering',
     *     'dismissible' => true|false,
     * ]
     *
     * @param array $notice Notice data.
     *
     * @return void
     */
    public static function add_notice( array $notice ): void
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $defaults = [
            'type'        => 'info',
            'message'     => '',
            'context'     => '',
            'dismissible' => true,
        ];

        $notice = wp_parse_args( $notice, $defaults );

        $notice = apply_filters(
            'luma_product_fields_notification',
            $notice
        );

        if ( empty( $notice['message'] ) ) {
            return;
        }

        $stack = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $stack ) ) {
            $stack = [];
        }

        $stack[] = $notice;

        update_user_meta( $user_id, self::META_KEY, $stack );
    }



    /**
     * Retrieve all notices for the current user.
     *
     * @return array List of notices.
     */
    protected static function get_notices(): array
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return [];
        }

        $stack = get_user_meta( $user_id, self::META_KEY, true );
        return is_array( $stack ) ? $stack : [];
    }



    /**
     * Clear all stored notices for the current user.
     *
     * @return void
     */
    protected static function clear_notices(): void
    {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            delete_user_meta( $user_id, self::META_KEY );
        }
    }



    /**
     * Render notices for the current user.
     *
     * Only notices matching the provided context are rendered.
     * Others remain stored for future requests.
     *
     * @param string|null $context Context filter (optional).
     *
     * @return void
     */
    public static function render(?string $context = null): void
    {
        $user_id = get_current_user_id();
        if (! $user_id) {
            return;
        }

        $notices = self::get_notices();
        if (empty($notices)) {
            return;
        }

        $remaining = [];

        foreach ($notices as $notice) {

            $notice_context = $notice['context'] ?? '';
            $should_render = (
                $context === null ||
                $context === $notice_context
            );

            if (! $should_render) {
                $remaining[] = $notice;
                continue;
            }

            $type = esc_attr($notice['type']);
            $classes = 'notice notice-' . $type;

            if (!empty($notice['dismissible'])) {
                $classes .= ' is-dismissible';
            }

            echo '<div class="' . esc_attr( $classes ) . '"><p>' .
                 wp_kses_post($notice['message']) .
                 '</p></div>';
        }
        update_user_meta($user_id, self::META_KEY, $remaining);
    }

}
