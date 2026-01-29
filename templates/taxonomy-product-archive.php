<?php
/**
 * WooCommerce-style archive template for LPF taxonomies.
 *
 * Used for linkable dynamic field taxonomies (and product groups) so that
 * term archives render products using the standard Woo product loop.
 *
 * @package Luma\ProductFields
 */

defined( 'ABSPATH' ) || exit;

// Block themes (e.g. Twenty Twenty-Four) do not provide header.php/footer.php.
// Calling get_header()/get_footer() triggers a deprecated notice in WP_DEBUG.
if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	?><!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php wp_head(); ?>
	</head>
	<body <?php body_class( [ 'woocommerce' ] ); ?>>
		<?php wp_body_open(); ?>

		<?php
		// Render theme header/footer via template-part blocks.
		echo do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
		?>

		<?php
		do_action( 'woocommerce_before_main_content' );
		do_action( 'woocommerce_archive_description' );

		if ( woocommerce_product_loop() ) {
			do_action( 'woocommerce_before_shop_loop' );

			woocommerce_product_loop_start();

			if ( wc_get_loop_prop( 'total' ) ) {
				while ( have_posts() ) {
					the_post();

					do_action( 'woocommerce_shop_loop' );
					wc_get_template_part( 'content', 'product' );
				}
			}

			woocommerce_product_loop_end();
			do_action( 'woocommerce_after_shop_loop' );
		} else {
			do_action( 'woocommerce_no_products_found' );
		}

		do_action( 'woocommerce_after_main_content' );

		// Block themes typically do not ship sidebar.php.
		// Calling the Woo sidebar hook can trigger get_sidebar() and cause a deprecation notice.
		// If a site wants a sidebar on archives in a block theme, it should be handled by the theme template.

		echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
		wp_footer();
		?>
	</body>
	</html>
	<?php
	return;
}

get_header( 'shop' );

/**
 * Hook: woocommerce_before_main_content.
 *
 * @hooked woocommerce_output_content_wrapper - 10 (outputs opening divs for the content)
 * @hooked woocommerce_breadcrumb - 20
 * @hooked WC_Structured_Data::generate_website_data() - 30
 */
do_action( 'woocommerce_before_main_content' );

/**
 * Hook: woocommerce_archive_description.
 *
 * @hooked woocommerce_taxonomy_archive_description - 10
 * @hooked woocommerce_product_archive_description - 10
 */
do_action( 'woocommerce_archive_description' );

if ( woocommerce_product_loop() ) {
	/**
	 * Hook: woocommerce_before_shop_loop.
	 *
	 * @hooked woocommerce_output_all_notices - 10
	 * @hooked woocommerce_result_count - 20
	 * @hooked woocommerce_catalog_ordering - 30
	 */
do_action( 'woocommerce_before_shop_loop' );

	woocommerce_product_loop_start();

	if ( wc_get_loop_prop( 'total' ) ) {
		while ( have_posts() ) {
			the_post();

			/**
			 * Hook: woocommerce_shop_loop.
			 */
			do_action( 'woocommerce_shop_loop' );

			wc_get_template_part( 'content', 'product' );
		}
	}

	woocommerce_product_loop_end();

	/**
	 * Hook: woocommerce_after_shop_loop.
	 *
	 * @hooked woocommerce_pagination - 10
	 */
	do_action( 'woocommerce_after_shop_loop' );
} else {
	/**
	 * Hook: woocommerce_no_products_found.
	 *
	 * @hooked wc_no_products_found - 10
	 */
	do_action( 'woocommerce_no_products_found' );
}

/**
 * Hook: woocommerce_after_main_content.
 *
 * @hooked woocommerce_output_content_wrapper_end - 10 (outputs closing divs for the content)
 */
do_action( 'woocommerce_after_main_content' );

/**
 * Hook: woocommerce_sidebar.
 *
 * @hooked woocommerce_get_sidebar - 10
 */
do_action( 'woocommerce_sidebar' );

get_footer( 'shop' );
