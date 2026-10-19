<?php

wp_set_current_user( 1 );

$count  = (int) ( $args[0] ?? 1000 );
$offset = (int) ( $args[1] ?? 1 );
$round  = (int) ( $args[2] ?? 1 );

$started = microtime( true );
$updated = 0;
for ( $i = $offset; $i < $offset + $count; $i++ ) {
	$number = str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
	$id     = wc_get_product_id_by_sku( 'BULK-WOO-' . $number );
	if ( ! $id ) {
		continue;
	}
	$product = wc_get_product( $id );
	$product->set_regular_price( (string) ( 10 + ( $i % 190 ) + $round ) . '.90' );
	$product->set_stock_quantity( ( $i * 7 + $round ) % 100 );
	$product->save();
	++$updated;
}
WP_CLI::success( sprintf( '%d products updated in %.1fs', $updated, microtime( true ) - $started ) );
