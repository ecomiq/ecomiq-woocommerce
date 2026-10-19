<?php

wp_set_current_user( 1 );

$count  = (int) ( $args[0] ?? 1000 );
$offset = (int) ( $args[1] ?? 1 );

$brand    = term_exists( 'Andes Basics', 'product_brand' );
$category = term_exists( 'Masivo', 'product_cat' );
if ( ! $category ) {
	$category = wp_insert_term( 'Masivo', 'product_cat' );
}
$image  = (int) get_posts(
	array(
		'post_type'   => 'attachment',
		'name'        => 'mochila-1',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
)[0];
$colors = array( 'Negro', 'Blanco', 'Gris', 'Verde' );

wp_defer_term_counting( true );
$started = microtime( true );
for ( $i = $offset; $i < $offset + $count; $i++ ) {
	$number = str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
	if ( wc_get_product_id_by_sku( 'BULK-WOO-' . $number ) ) {
		continue;
	}
	$color  = new WC_Product_Attribute();
	$color->set_name( 'Color' );
	$color->set_options( array( $colors[ $i % 4 ] ) );
	$color->set_visible( true );

	$product = new WC_Product_Simple();
	$product->set_name( 'Producto masivo ' . $number );
	$product->set_sku( 'BULK-WOO-' . $number );
	$product->set_status( 'publish' );
	$product->set_regular_price( (string) ( 10 + ( $i % 190 ) ) . '.90' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $i % 100 );
	$product->set_category_ids( array( (int) $category['term_id'] ) );
	$product->set_image_id( $image );
	$product->set_attributes( array( $color ) );
	$product->save();
	if ( $brand ) {
		wp_set_object_terms( $product->get_id(), array( (int) $brand['term_id'] ), 'product_brand' );
	}
	if ( 0 === $i % 500 ) {
		WP_CLI::log( sprintf( '%s %.1fs', $number, microtime( true ) - $started ) );
	}
}
wp_defer_term_counting( false );
WP_CLI::success( sprintf( '%d products in %.1fs', $count, microtime( true ) - $started ) );
