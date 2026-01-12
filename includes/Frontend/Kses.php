<?php
/**
 * Frontend KSES context registration.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a plugin-specific KSES context for frontend field output.
 *
 * Extensions can expand the allowlist via:
 * - filter: luma_product_fields_allowed_frontend_fields_html
 *
 * Output can then be sanitized late using:
 * wp_kses( $html, wp_kses_allowed_html( 'luma_product_fields_frontend_fields' ) )
 */
class Kses {

	/**
	 * Register the KSES context filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_kses_allowed_html', [ $this, 'filter_allowed_html' ], 10, 2 );
	}



	/**
	 * Provide allowed HTML tags/attributes for the plugin frontend context.
	 *
	 * @param array<string, array<string, bool>> $allowed_html Allowed tags/attributes.
	 * @param string                            $context      KSES context.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function filter_allowed_html( array $allowed_html, string $context ): array {
		if ( 'luma_product_fields_frontend_fields' !== $context ) {
			return $allowed_html;
		}

		$allowed = $this->get_allowed_frontend_fields_html();

		/**
		 * Allow extensions to expand the allowlist for frontend field HTML.
		 *
		 * @param array<string, array<string, bool>> $allowed Base allowlist for frontend fields.
		 */
		return (array) apply_filters( 'luma_product_fields_allowed_frontend_fields_html', $allowed );
	}


    /**
     * Base allowlist for frontend field markup.
     *
     * @return array<string, array<string, bool>>
     */
    private function get_allowed_frontend_fields_html(): array {
        return [
            'dl' => [ 'class' => true, 'data-slug' => true ],
            'dt' => [ 'class' => true ],
            'dd' => [ 'class' => true ],

            'span' => [
                'id'               => true,
                'style'            => true,
                'class'            => true,
                'tabindex'         => true,
                'role'             => true,
                'aria-describedby' => true,
                'aria-label'       => true,
                'itemprop'         => true,
            ],

            'p'      => [ 
                'class' => true,
                'style' => true
            ],
            'br'     => [],
            'strong' => [],
            'em'     => [],

            'ul' => [ 'class' => true ],
            'ol' => [ 'class' => true ],
            'li' => [ 'class' => true ],

            // Keep only these headings.
            'h3' => [],
            'h4' => [],

            'pre'  => [],
            'code' => [],

            'a' => [
                'href'   => true,
                'class'  => true,
                'target' => true,
                'rel'    => true,
                'title'  => true,
            ],

            'meta' => [ 'itemprop' => true, 'content' => true ],
        ];
    }


}
