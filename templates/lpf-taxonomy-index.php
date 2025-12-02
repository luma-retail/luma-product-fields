<?php
/**
 * Taxonomy Index Template (override via yourtheme/product-fields/taxonomy-index.php)
 *
 * @var string $lpf_tax Provided via query var.
 */
use Luma\ProductFields\Frontend\TaxonomyIndexRenderer;

defined('ABSPATH') || exit;

get_header( 'shop' );

do_action( 'woocommerce_before_main_content' );

$taxonomy = (string) get_query_var('lpf_tax');


echo '<main class="lpf-tax-index">';

$renderer = new TaxonomyIndexRenderer();
$renderer->render( $taxonomy );

echo '</main>';

do_action( 'woocommerce_after_main_content' );

get_footer();
