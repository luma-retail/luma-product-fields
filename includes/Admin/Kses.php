<?php
/**
 * KSES allowlists for Luma Product Fields.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers KSES contexts used by the plugin, so output can be sanitized "late"
 * with an extensible allowlist.
 */
class Kses {

	/**
	 * Register the KSES allowlist filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_kses_allowed_html', [ $this, 'filter_allowed_html' ], 10, 2 );
	}



	/**
	 * Provide allowed HTML tags/attributes for custom plugin contexts.
	 *
	 * @param array<string, array<string, bool>> $allowed_html Allowed tags/attributes.
	 * @param string                            $context      KSES context.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function filter_allowed_html( array $allowed_html, string $context ): array {
		if ( 'luma_product_fields_admin_fields' !== $context ) {
			return $allowed_html;
		}

		$allowed = $this->get_allowed_admin_fields_html();

		/**
		 * Allow extensions to expand the allowlist for admin field HTML.
		 *
		 * @param array<string, array<string, bool>> $allowed Base allowlist for admin fields.
		 */
		return (array) apply_filters( 'luma_product_fields_allowed_admin_fields_html', $allowed );
	}



	/**
	 * Base allowlist for WooCommerce-like admin form markup.
	 *
	 * Keep this strict. Extensions can expand via the filter.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function get_allowed_admin_fields_html(): array {
		return [
			'div'      => [ 'id' => true, 'class' => true ],
			'span'     => [
				'id'          => true,
				'class'       => true,
				'title'       => true,
				'data-tip'    => true,
				'aria-hidden' => true,
				'role'        => true,
			],
			'p'        => [ 
				'class' => true 
			],
			'label'    => [ 
                'for' => true, 
                'class' => true 
            ],
			'input'    => [
				'type'        => true,
				'name'        => true,
				'value'       => true,
				'id'          => true,
				'class'       => true,
				'checked'     => true,
				'disabled'    => true,
				'readonly'    => true,
				'required'    => true,
				'placeholder' => true,
				'min'         => true,
				'max'         => true,
				'step'        => true,
				'size'        => true,
			],
			'select'   => [
				'name'     => true,
				'id'       => true,
				'class'    => true,
				'multiple' => true,
				'disabled' => true,
				'required' => true,
				'data-taxonomy' => true,
			],
			'option'   => [
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			],
			'textarea' => [
				'name'        => true,
				'id'          => true,
				'class'       => true,
				'rows'        => true,
				'cols'        => true,
				'placeholder' => true,
				'readonly'    => true,
				'disabled'    => true,
				'required'    => true,
			],
            'fieldset' => [ 
                'class' => true, 
                'id' => true 
            ],
			'a'        => [
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			],
			'em'       => [],
			'strong'   => [],
			'small'    => [ 'class' => true ],
			'br'       => [],
			'legend'   => [],
		];
	}
}
