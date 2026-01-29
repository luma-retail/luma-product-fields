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
	// Block themes usually render via template-canvas.php, which takes care of
	// global styles + block assets. This archive uses a custom PHP template for
	// Woo loop compatibility, so we must ensure those styles are still loaded,
	// otherwise headers/menus/breadcrumbs can look unstyled (e.g. TT4/TT5).
	if ( function_exists( 'wp_enqueue_style' ) ) {
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-block-library-theme' );
	}
	if ( function_exists( 'wp_enqueue_global_styles' ) ) {
		wp_enqueue_global_styles();
	}

	// Pre-render template parts before wp_head() so any styles they enqueue are
	// available in the document head.
	$header_html = function_exists( 'do_blocks' )
		? do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' )
		: '';
	$footer_html = function_exists( 'do_blocks' )
		? do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' )
		: '';

	?><!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php wp_head(); ?>
	</head>
	<body <?php body_class( [ 'woocommerce' ] ); ?>>
		<?php wp_body_open(); ?>
		<div class="wp-site-blocks">
			<?php echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php
		// Prevent nested <main> output.
		// WooCommerce's default wrappers output <main id="main" class="site-main">...
		// In block themes we render our own container instead.
		$wrapper_priority = has_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper' );
		if ( false !== $wrapper_priority ) {
			remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', (int) $wrapper_priority );
		}
		$wrapper_end_priority = has_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end' );
		if ( false !== $wrapper_end_priority ) {
			remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', (int) $wrapper_end_priority );
		}
		?>

		<main id="main" class="site-main wp-block-group has-global-padding is-layout-constrained">
			<div class="alignwide">

			<?php
			do_action( 'woocommerce_before_main_content' );
			?>

		<header class="woocommerce-products-header">
			<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
				<h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
			<?php endif; ?>
			<?php do_action( 'woocommerce_archive_description' ); ?>
		</header>

		<?php

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
		?>
			</div>
		</main>
		<?php
		// Block themes typically do not ship sidebar.php.
		// Calling the Woo sidebar hook can trigger get_sidebar() and cause a deprecation notice.
		// If a site wants a sidebar on archives in a block theme, it should be handled by the theme template.
		?>
			<?php echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php wp_footer(); ?>
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

?><header class="woocommerce-products-header">
	<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
		<h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
	<?php endif; ?>
	<?php
	/**
	 * Hook: woocommerce_archive_description.
	 *
	 * @hooked woocommerce_taxonomy_archive_description - 10
	 * @hooked woocommerce_product_archive_description - 10
	 */
	do_action( 'woocommerce_archive_description' );
	?>
</header>

<?php

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
