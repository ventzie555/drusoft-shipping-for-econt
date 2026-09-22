<?php
// Does the checkout-time payer signal travel session -> order meta?
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfe_econt_(\\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfe_econt instance configured on this site\n"; return; }
if ( ! WC()->session ) { WC()->initialize_session(); }
$pid = wc_get_product_id_by_sku( 'COD-TEST-2999' );
$m = new Drushfe_Shipping_Method( $INSTANCE );
function mk( int $pid, int $instance ): WC_Order {
	$o = wc_create_order(); $o->add_product( wc_get_product( $pid ), 1 );
	$i = new WC_Order_Item_Shipping(); $i->set_method_id( 'drushfe_econt' ); $i->set_instance_id( $instance ); $i->set_total( '5.11' ); $o->add_item( $i );
	$o->calculate_totals( false ); return $o;
}
$all = true;
foreach ( [ 'recipient pays' => 5.11, 'shop pays' => 0.0, 'nothing quoted' => null ] as $label => $val ) {
	WC()->session->set( 'chosen_shipping_methods', [ 'drushfe_econt:' . $INSTANCE ] ); WC()->session->set( 'drushfe_receiver_due', $val );
	$o = mk( $pid, $INSTANCE );
	$m->save_shipping_data_to_order( $o );
	$o->save(); $o = wc_get_order( $o->get_id() );
	$got = $o->get_meta( '_drushfe_receiver_due', true );
	$exp = null === $val ? '' : (string) round( $val, 2 );
	$ok  = (string) $got === $exp;
	$all &= $ok;
	printf( "%s  %-16s session=%-5s -> _drushfe_receiver_due=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $val, true ), var_export( $got, true ) );
	$o->delete( true );
}
echo $all ? "ALL PASS\n" : "SOME FAILED\n";
