<?php
/**
 * Integration test for the Econt COD payer fix. Runs the real generator on a real
 * WC order; only the HTTP layer is intercepted (no waybill reaches Econt).
 * Usage: wp eval-file tests/econt-cod-payer-test.php
 */
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfe_econt_(\\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfe_econt instance configured on this site\n"; return; }

$fake = [ 'receiver_due' => 0.0, 'fail_getprice' => false ];
$captured = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$fake, &$captured ) {
	if ( false === strpos( $url, 'econt.com/services/OrdersService.' ) ) {
		return $pre;
	}
	$body = json_decode( $args['body'] ?? '', true );
	$captured[] = [ 'url' => basename( parse_url( $url, PHP_URL_PATH ) ), 'body' => $body ];
	if ( str_contains( $url, 'getPrice' ) ) {
		if ( $fake['fail_getprice'] ) {
			return new WP_Error( 'http_request_failed', 'simulated outage' );
		}
		$due = (float) $fake['receiver_due'];
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [
			'senderDueAmount' => $due > 0 ? 0 : 4.39, 'receiverDueAmount' => $due, 'totalPrice' => $due > 0 ? $due : 4.39, 'currency' => 'EUR' ] ) ];
	}
	if ( str_contains( $url, 'updateOrder' ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'id' => 990001 ] ) ];
	}
	if ( str_contains( $url, 'createAWB' ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'shipmentNumber' => 'TEST0001', 'pdfURL' => '' ] ) ];
	}
	return $pre;
}, 10, 3 );

// one product, reused
$pid = wc_get_product_id_by_sku( 'COD-TEST-2999' );
if ( ! $pid ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'COD test item' ); $p->set_sku( 'COD-TEST-2999' ); $p->set_regular_price( '29.99' ); $p->set_weight( '0.5' );
	$pid = $p->save();
}

function mk_order( int $pid, int $instance, string $payment, float $shipping, ?float $receiver_due_meta, int $qty = 1 ): WC_Order {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $pid ), $qty );
	$item = new WC_Order_Item_Shipping();
	$item->set_method_title( 'Доставка с Еконт' );
	$item->set_method_id( 'drushfe_econt' );
	$item->set_instance_id( $instance );
	$item->set_total( (string) $shipping );
	$o->add_item( $item );
	$o->set_payment_method( $payment );
	$o->set_address( [ 'first_name' => 'Тест', 'last_name' => 'Клиент', 'phone' => '0888123456', 'email' => 'test@example.com', 'city' => 'София', 'postcode' => '1000', 'country' => 'BG', 'address_1' => 'ул. Тестова 1' ], 'billing' );
	$o->set_address( [ 'first_name' => 'Тест', 'last_name' => 'Клиент', 'city' => 'София', 'postcode' => '1000', 'country' => 'BG', 'address_1' => 'ул. Тестова 1' ], 'shipping' );
	$o->update_meta_data( '_drushfe_econt_order', 1 );
	$o->update_meta_data( '_drushfe_delivery_type', 'office' );
	$o->update_meta_data( '_drushfe_office_id', '1180' );
	if ( null !== $receiver_due_meta ) {
		$o->update_meta_data( '_drushfe_receiver_due', $receiver_due_meta );
	}
	$o->calculate_totals( false );
	$o->set_status( 'processing' );
	$o->save();
	return $o;
}

function run_case( string $name, array $cfg, array &$fake, array &$captured, int $pid, int $instance, array $expect ): bool {
	$fake     = array_merge( [ 'receiver_due' => 0.0, 'fail_getprice' => false ], $cfg['fake'] ?? [] );
	$captured = [];
	$o = mk_order( $pid, $instance, $cfg['payment'] ?? 'cod', $cfg['shipping'] ?? 5.11, $cfg['meta'] ?? null, $cfg['qty'] ?? 1 );
	$res = Drushfe_Waybill_Generator::instance()->generate_waybill( $o->get_id() );
	$o = wc_get_order( $o->get_id() );
	$calls = array_column( $captured, 'url' );
	$upd = null;
	foreach ( $captured as $c ) { if ( 'OrdersService.updateOrder.json' === $c['url'] ) { $upd = $c['body']; } }
	$items = $upd['items'] ?? [];
	$sum = 0.0; $delivery_line = null;
	foreach ( $items as $it ) { $sum += (float) $it['totalPrice']; if ( 'Доставка' === $it['name'] ) { $delivery_line = (float) $it['totalPrice']; } }
	$notes = wc_get_order_notes( [ 'order_id' => $o->get_id() ] );
	$note_text = implode( ' | ', array_map( fn( $n ) => $n->content, $notes ) );

	$ok = true; $why = [];
	if ( is_wp_error( $res ) ) { $ok = false; $why[] = 'generator error: ' . $res->get_error_message(); }
	if ( abs( $sum - $expect['cod'] ) > 0.005 ) { $ok = false; $why[] = sprintf( 'items sum %.2f, expected %.2f', $sum, $expect['cod'] ); }
	if ( array_key_exists( 'delivery_line', $expect ) ) {
		if ( $expect['delivery_line'] === null && null !== $delivery_line ) { $ok = false; $why[] = "unexpected Доставка line $delivery_line"; }
		if ( $expect['delivery_line'] !== null && ( null === $delivery_line || abs( $delivery_line - $expect['delivery_line'] ) > 0.005 ) ) { $ok = false; $why[] = 'Доставка line ' . var_export( $delivery_line, true ) . ', expected ' . $expect['delivery_line']; }
	}
	if ( isset( $expect['getprice_calls'] ) ) {
		$n = count( array_filter( $calls, fn( $u ) => str_contains( $u, 'getPrice' ) ) );
		if ( $n !== $expect['getprice_calls'] ) { $ok = false; $why[] = "getPrice called $n×, expected {$expect['getprice_calls']}"; }
	}
	// notes are translated on a bg_BG admin, so accept either language
	$has_any = static fn( array $alts ) => (bool) array_filter( $alts, fn( $a ) => false !== mb_strpos( $note_text, $a ) );
	if ( isset( $expect['note_has'] ) && ! $has_any( (array) $expect['note_has'] ) ) { $ok = false; $why[] = 'note missing ' . json_encode( $expect['note_has'], JSON_UNESCAPED_UNICODE ); }
	if ( isset( $expect['note_lacks'] ) && $has_any( (array) $expect['note_lacks'] ) ) { $ok = false; $why[] = 'note wrongly contains ' . json_encode( $expect['note_lacks'], JSON_UNESCAPED_UNICODE ); }
	if ( ! empty( $upd['cod'] ) !== ( 'cod' === ( $cfg['payment'] ?? 'cod' ) ) ) { $ok = false; $why[] = 'cod flag mismatch'; }

	printf( "%s  %-52s order %d  total %.2f  items→%.2f  calls=[%s]\n", $ok ? 'PASS' : 'FAIL', $name, $o->get_id(), $o->get_total(), $sum, implode( ',', array_map( fn( $u ) => str_replace( [ 'OrdersService.', '.json' ], '', $u ), $calls ) ) );
	foreach ( $why as $w ) { echo "        ! $w\n"; }
	echo "        note: " . mb_substr( $note_text, 0, 190 ) . "\n";
	$o->delete( true );
	return $ok;
}

$all = true;
$all &= run_case( 'A recipient pays (getPrice 5.11): COD = goods only', [ 'fake' => [ 'receiver_due' => 5.11 ] ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 29.99, 'delivery_line' => null, 'getprice_calls' => 1, 'note_has' => [ 'goods only', 'само стоката' ] ] );
$all &= run_case( 'B shop pays (getPrice 0): COD = order total', [ 'fake' => [ 'receiver_due' => 0 ] ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 35.10, 'delivery_line' => 5.11, 'getprice_calls' => 1, 'note_has' => [ 'including', 'включително' ] ] );
$all &= run_case( 'C getPrice down, checkout meta 5.11: COD = goods only', [ 'fake' => [ 'fail_getprice' => true ], 'meta' => 5.11 ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 29.99, 'delivery_line' => null, 'note_has' => [ 'goods only', 'само стоката' ] ] );
$all &= run_case( 'D getPrice down, no meta: old behaviour + warning', [ 'fake' => [ 'fail_getprice' => true ] ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 35.10, 'delivery_line' => 5.11, 'note_has' => [ 'did not say', 'не отговори' ] ] );
$all &= run_case( 'E card payment: untouched, no payer lookup', [ 'payment' => 'revolut_cc', 'fake' => [ 'receiver_due' => 5.11 ] ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 35.10, 'delivery_line' => 5.11, 'getprice_calls' => 0, 'note_lacks' => [ 'Cash on delivery', 'Наложен платеж' ] ] );
$all &= run_case( 'F recipient pays, free shipping line: nothing to remove', [ 'shipping' => 0, 'fake' => [ 'receiver_due' => 5.11 ] ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 29.99, 'delivery_line' => null, 'note_has' => [ 'goods only', 'само стоката' ] ] );
$all &= run_case( 'G recipient pays, 3 units: rounding stays, shipping out', [ 'qty' => 3, 'fake' => [ 'receiver_due' => 5.11 ] ], $fake, $captured, $pid, $INSTANCE,
	[ 'cod' => 89.97, 'delivery_line' => null ] );
echo $all ? "\nALL PASS\n" : "\nSOME FAILED\n";
