<?php

require_once ABSPATH . 'wp-admin/includes/image.php';

wp_set_current_user( 1 );

$dir = rtrim( $args[0], '/' );

if ( wc_get_product_id_by_sku( 'MOCHILA-001' ) || wc_get_product_id_by_sku( 'POLO-VAR' ) ) {
	WP_CLI::error( 'The catalog already exists.' );
}

function ecomiq_fixture_image( $path ) {
	$upload = wp_upload_bits( basename( $path ), null, file_get_contents( $path ) );
	if ( ! empty( $upload['error'] ) ) {
		WP_CLI::error( $upload['error'] );
	}
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => pathinfo( $path, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return $id;
}

function ecomiq_fixture_term( $name, $taxonomy, $parent = 0 ) {
	$term = term_exists( $name, $taxonomy, $parent );
	if ( ! $term ) {
		$term = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
	}
	return (int) $term['term_id'];
}

function ecomiq_fixture_attribute( $name, $slug, array $values ) {
	$taxonomy = 'pa_' . $slug;
	if ( ! wc_attribute_taxonomy_id_by_name( $taxonomy ) ) {
		wc_create_attribute(
			array(
				'name' => $name,
				'slug' => $slug,
			)
		);
	}
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false ) );
	}
	$ids = array();
	foreach ( $values as $value ) {
		$ids[ sanitize_title( $value ) ] = ecomiq_fixture_term( $value, $taxonomy );
	}
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
	$attribute->set_name( $taxonomy );
	$attribute->set_options( array_values( $ids ) );
	$attribute->set_visible( true );
	return $attribute;
}

$images = array();
foreach ( array( 'mochila-1', 'mochila-2', 'polo', 'polo-rojo', 'polo-azul' ) as $name ) {
	$images[ $name ] = ecomiq_fixture_image( "$dir/$name.png" );
}

$ropa        = ecomiq_fixture_term( 'Ropa', 'product_cat' );
$polos       = ecomiq_fixture_term( 'Polos', 'product_cat', $ropa );
$accesorios  = ecomiq_fixture_term( 'Accesorios', 'product_cat' );
$verano      = ecomiq_fixture_term( 'verano', 'product_tag' );
$nuevo       = ecomiq_fixture_term( 'nuevo', 'product_tag' );
$color       = ecomiq_fixture_attribute( 'Color', 'color', array( 'Rojo', 'Azul' ) );
$talla       = ecomiq_fixture_attribute( 'Talla', 'talla', array( 'S', 'M', 'L' ) );
$color_terms = array_combine( array( 'rojo', 'azul' ), $color->get_options() );

$material = new WC_Product_Attribute();
$material->set_name( 'Material' );
$material->set_options( array( 'Lona' ) );
$material->set_visible( true );

$mochila_color = clone $color;
$mochila_color->set_options( array( $color_terms['azul'] ) );

$mochila = new WC_Product_Simple();
$mochila->set_name( 'Mochila urbana' );
$mochila->set_sku( 'MOCHILA-001' );
$mochila->set_status( 'publish' );
$mochila->set_regular_price( '149.90' );
$mochila->set_sale_price( '129.90' );
$mochila->set_description( '<p>Mochila de lona con compartimento para laptop de 15".</p><ul><li>Bolsillo frontal</li><li>Correas acolchadas</li></ul>' );
$mochila->set_short_description( 'Mochila de lona para el día a día.' );
$mochila->set_category_ids( array( $accesorios ) );
$mochila->set_tag_ids( array( $verano, $nuevo ) );
$mochila->set_manage_stock( true );
$mochila->set_stock_quantity( 15 );
$mochila->set_weight( '0.8' );
$mochila->set_length( '30' );
$mochila->set_width( '15' );
$mochila->set_height( '45' );
$mochila->set_image_id( $images['mochila-1'] );
$mochila->set_gallery_image_ids( array( $images['mochila-2'] ) );
$mochila->set_attributes( array( $material, $mochila_color ) );
$mochila->save();

$color->set_variation( true );
$talla->set_variation( true );
$talla->set_options( array_slice( $talla->get_options(), 0, 2 ) );

$polo = new WC_Product_Variable();
$polo->set_name( 'Polo básico' );
$polo->set_sku( 'POLO-VAR' );
$polo->set_status( 'publish' );
$polo->set_description( '<p>Polo de algodón peinado, corte regular.</p>' );
$polo->set_short_description( 'Polo de algodón en dos colores.' );
$polo->set_category_ids( array( $ropa, $polos ) );
$polo->set_tag_ids( array( $nuevo ) );
$polo->set_image_id( $images['polo'] );
$polo->set_gallery_image_ids( array( $images['polo-rojo'], $images['polo-azul'] ) );
$polo->set_attributes( array( $color, $talla ) );
$polo->set_weight( '0.25' );
$polo->save();

$stock = array(
	'rojo-s' => 5,
	'rojo-m' => 6,
	'azul-s' => 7,
	'azul-m' => 8,
);
foreach ( $stock as $key => $quantity ) {
	list( $color_slug, $size_slug ) = explode( '-', $key );
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $polo->get_id() );
	$variation->set_sku( 'POLO-VAR-' . strtoupper( $color_slug ) . '-' . strtoupper( $size_slug ) );
	$variation->set_attributes(
		array(
			'pa_color' => $color_slug,
			'pa_talla' => $size_slug,
		)
	);
	$variation->set_status( 'publish' );
	$variation->set_regular_price( 'azul' === $color_slug ? '64.90' : '59.90' );
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( $quantity );
	$variation->set_image_id( $images[ 'polo-' . $color_slug ] );
	$variation->save();
}
WC_Product_Variable::sync( $polo->get_id() );

WP_CLI::success( sprintf( 'Mochila #%d, Polo #%d with %d variations.', $mochila->get_id(), $polo->get_id(), count( $stock ) ) );
