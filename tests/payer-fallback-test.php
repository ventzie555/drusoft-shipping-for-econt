<?php
// 1.0.13: who pays the courier decides the checkout price, and the fallback
// table prices the order whenever Econt cannot. Real cart, real session, only
// OrdersService.getPrice intercepted. Usage: wp eval-file wp-content/plugins/drusoft-shipping-for-econt/tests/payer-fallback-test.php
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfe_econt_(\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfe_econt instance configured on this site\n"; return; }
if ( ! WC()->session ) { WC()->initialize_session(); }
global $wpdb;
$pid   = wc_get_product_id_by_sku( 'COD-TEST-2999' );
$sofia = $wpdb->get_row( "SELECT id, name, post_code FROM {$wpdb->prefix}drushfe_cities WHERE name = 'София' LIMIT 1" );
if ( ! $pid || ! $sofia ) { echo "need product COD-TEST-2999 and the synced cities\n"; return; }

$all   = true;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$all ) {
	$all &= $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  [$detail]" : '' );
};
$opt   = 'woocommerce_drushfe_econt_' . $INSTANCE . '_settings';
$saved = get_option( $opt );
$set   = function ( array $changes ) use ( $opt, $saved ) { update_option( $opt, array_merge( $saved, $changes ) ); wp_cache_flush(); };
$pause_key = drushfe_api_down_key( $saved );
delete_transient( $pause_key );

$captured = [];
$reply    = null; // array => JSON body; WP_Error => transport failure
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured, &$reply ) {
	if ( ! str_contains( $url, 'OrdersService.getPrice' ) ) { return $pre; }
	$captured[] = json_decode( $args['body'], true );
	if ( $reply instanceof WP_Error ) { return $reply; }
	return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $reply ), 'headers' => [] ];
}, 10, 3 );
$logged = [];
add_filter( 'woocommerce_logger_log_message', function ( $message, $level, $context ) use ( &$logged ) {
	if ( 'drusoft-shipping-for-econt' === ( $context['source'] ?? '' ) ) { $logged[] = $level . ': ' . $message; }
	return $message;
}, 10, 3 );
$shop_pays      = [ 'receiverDueAmount' => 0,    'senderDueAmount' => 5.90, 'totalPrice' => 5.90, 'currency' => 'EUR' ];
$recipient_pays = [ 'receiverDueAmount' => 5.28, 'senderDueAmount' => 0,    'totalPrice' => 5.28, 'currency' => 'EUR' ];

WC()->cart->empty_cart();
WC()->cart->add_to_cart( $pid, 1 );
WC()->session->set( 'chosen_shipping_methods', [ 'drushfe_econt:' . $INSTANCE ] );
WC()->session->set( 'chosen_payment_method', 'cod' );
$sel = [ 'delivery_type' => 'address', 'city_id' => (int) $sofia->id, 'city_name' => $sofia->name, 'postcode' => '2230', 'office_code' => '', 'address' => 'ул. Тестова 1', 'state' => 'BG-22', 'payment_method' => 'cod' ];

// ── Fix 1: who pays ──────────────────────────────────────────────────────
$reply = $shop_pays; $q = drushfe_quote( $sel );
$check( 'shop pays (recipient 0, total 5.90) → customer charged 5.90', ! is_wp_error( $q ) && 5.90 === (float) $q['price'] && 0.0 === (float) $q['receiver_due'], is_wp_error( $q ) ? $q->get_error_message() : 'price=' . $q['price'] . ' receiver_due=' . $q['receiver_due'] );
$reply = $recipient_pays; $q = drushfe_quote( $sel );
$check( 'recipient pays (recipient 5.28) → customer sees 5.28, receiver_due 5.28 (COD stays clean)', ! is_wp_error( $q ) && 5.28 === (float) $q['price'] && 5.28 === (float) $q['receiver_due'], is_wp_error( $q ) ? $q->get_error_message() : 'price=' . $q['price'] . ' receiver_due=' . $q['receiver_due'] );
$set( [ 'shop_pays_free' => 'yes' ] );
$reply = $shop_pays; $q = drushfe_quote( $sel );
$check( 'shop pays + "free delivery" ticked → 0.00 by choice', ! is_wp_error( $q ) && 0.0 === (float) $q['price'], is_wp_error( $q ) ? $q->get_error_message() : 'price=' . $q['price'] );
$reply = $recipient_pays; $q = drushfe_quote( $sel );
$check( 'the tick changes nothing for a recipient-pays store', ! is_wp_error( $q ) && 5.28 === (float) $q['price'] );
$set( [] );

// ── Fix 2: fallback table ───────────────────────────────────────────────
$reply = [ 'type' => 'ExInvalidCity', 'message' => 'Несъответствие между населено място и пощенски код.' ];
$logged = []; $captured = [];
$q = drushfe_quote_or_fallback( $sel );
$check( 'Econt refuses → table price for address (5.50 default), priced_by fallback', ! is_wp_error( $q ) && 'fallback' === $q['priced_by'] && 5.50 === (float) $q['price'], is_wp_error( $q ) ? $q->get_error_message() : 'price=' . $q['price'] . ' by=' . $q['priced_by'] );
$check( 'the refusal is kept as the reason', str_contains( (string) $q['fallback_reason'], 'Несъответствие' ) );
$check( 'the refusal is logged', (bool) preg_grep( '/getPrice failed/', $logged ) );
$check( 'a refusal does not pause quoting', ! get_transient( $pause_key ) );
$reply = $shop_pays; $captured = [];
$q = drushfe_quote_or_fallback( $sel );
$check( 'next quote asks Econt again and gets the live price', 1 === count( $captured ) && 'api' === $q['priced_by'] && 5.90 === (float) $q['price'] );

$reply = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' );
$logged = []; $captured = [];
$q = drushfe_quote_or_fallback( $sel );
$check( 'timeout → table price, priced_by fallback', 'fallback' === $q['priced_by'] && 5.50 === (float) $q['price'] );
$check( 'timeout pauses quoting for ~2 minutes', get_transient( $pause_key ) && abs( (int) get_option( '_transient_timeout_' . $pause_key ) - time() - 120 ) <= 5, 'left=' . ( (int) get_option( '_transient_timeout_' . $pause_key ) - time() ) );
$check( 'the pause is logged', (bool) preg_grep( '/not answering/', $logged ) );
$reply = $shop_pays; $captured = [];
$q = drushfe_quote_or_fallback( $sel );
$check( 'while paused: no call to Econt, table price', 0 === count( $captured ) && 'fallback' === $q['priced_by'] );
delete_transient( $pause_key ); $captured = [];
$q = drushfe_quote_or_fallback( $sel );
$check( 'after the pause: Econt asked, live price back', 1 === count( $captured ) && 'api' === $q['priced_by'] && 5.90 === (float) $q['price'] );

$reply = [ 'type' => 'ExInvalidCity', 'message' => 'refused' ];
$set( [ 'fallback_address' => '', 'fallback_office' => '' ] );
$q = drushfe_quote_or_fallback( $sel );
$check( 'cleared fallback field → the default (5.50), never 0.00', 5.50 === (float) $q['price'], 'price=' . $q['price'] );
$set( [ 'fallback_address' => '0' ] );
$q = drushfe_quote_or_fallback( $sel );
$check( 'a deliberate 0 stays 0', 0.0 === (float) $q['price'], 'price=' . $q['price'] );
$set( [ 'teglo' => '4', 'fallback_address' => '5.50', 'fallback_per_kg' => '0.50' ] );
$q = drushfe_quote_or_fallback( $sel );
$check( '4 kg → 5.50 + 1 × 0.50 per started kg above 3', 6.00 === (float) $q['price'], 'price=' . $q['price'] );
$set( [ 'teglo' => '1', 'fallback_office' => '3.60' ] );
$q = drushfe_quote_or_fallback( array_merge( $sel, [ 'delivery_type' => 'office', 'office_code' => '1003' ] ) );
$check( 'office fallback 3.60', 3.60 === (float) $q['price'], 'price=' . $q['price'] );
$set( [] );

// ── submit: a table price is re-asked, a failure never leaves 0.00 ───────
$_POST = [ 'billing_city' => (string) $sofia->id, 'econt_delivery_type' => 'address', 'billing_address_1' => 'ул. Тестова 1', 'billing_state' => 'BG-22', 'payment_method' => 'cod' ];
drushfe_remember_selection( $sel );
$reply = [ 'type' => 'ExInvalidCity', 'message' => 'refused' ];
$q = drushfe_quote_or_fallback( $sel ); drushfe_store_quote( $q, 0 );
$reply = $shop_pays; $captured = [];
drushfe_requote_on_submit();
$check( 'submit with a table price in the session → Econt re-asked, live 5.90 charged', 1 === count( $captured ) && 5.90 === (float) WC()->session->get( 'drushfe_shipping_cost' ) && 'api' === WC()->session->get( 'drushfe_priced_by' ), 'calls=' . count( $captured ) . ' cost=' . WC()->session->get( 'drushfe_shipping_cost' ) );
drushfe_forget_quote();
$reply = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ); $captured = [];
drushfe_requote_on_submit();
$check( 'submit while Econt is down → table price in the session, not 0.00', 5.50 === (float) WC()->session->get( 'drushfe_shipping_cost' ) && 'fallback' === WC()->session->get( 'drushfe_priced_by' ), 'cost=' . WC()->session->get( 'drushfe_shipping_cost' ) );
delete_transient( $pause_key );

// ── the order says so ────────────────────────────────────────────────────
$o = wc_create_order(); $o->add_product( wc_get_product( $pid ), 1 );
$i = new WC_Order_Item_Shipping(); $i->set_method_id( 'drushfe_econt' ); $i->set_instance_id( $INSTANCE ); $i->set_total( 5.50 ); $i->add_meta_data( 'priced_by', 'fallback' ); $o->add_item( $i );
$o->update_meta_data( '_drushfe_delivery_type', 'address' );
$o->update_meta_data( '_drushfe_priced_by', 'fallback' );
$o->update_meta_data( '_drushfe_fallback_reason', 'cURL error 7: Failed to connect' );
$o->calculate_totals( false ); $o->save();
( new Drushfe_Shipping_Method( $INSTANCE ) )->flag_unpriced_shipping( $o );
$o = wc_get_order( $o->get_id() );
$notes = array_map( fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => $o->get_id() ] ) );
$check( 'order note names the table price and Econt\'s answer', (bool) preg_grep( '/резервната таблица|fallback table/u', $notes ) && (bool) preg_grep( '/Failed to connect/', $notes ), implode( ' | ', array_map( fn( $n ) => mb_substr( $n, 0, 90 ), $notes ) ) );
$check( 'noted once (idempotent flag)', '1' === (string) $o->get_meta( '_drushfe_unpriced_noted' ) );
$o->delete( true );

// ── shipping tax: the customer sees Econt's amount, not Econt's amount + 20 % ──
$tax_backup = [ 'calc' => get_option( 'woocommerce_calc_taxes' ), 'class' => get_option( 'woocommerce_shipping_tax_class' ) ];
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_shipping_tax_class', 'inherit' );
$rate_id = WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'BG', 'tax_rate' => '20.0000', 'tax_rate_name' => 'ДДС тест', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ] );
WC_Cache_Helper::invalidate_cache_group( 'taxes' ); wp_cache_flush();
WC()->customer->set_billing_country( 'BG' ); WC()->customer->set_shipping_country( 'BG' );
$reply = $shop_pays; drushfe_remember_selection( $sel ); $q = drushfe_quote_or_fallback( $sel ); drushfe_store_quote( $q, 0 );
$m = new Drushfe_Shipping_Method( $INSTANCE ); $m->rates = []; $m->calculate_shipping( WC()->cart->get_shipping_packages()[0] );
$r = reset( $m->rates ); $gross = $r ? round( (float) $r->get_cost() + (float) $r->get_shipping_tax(), 2 ) : -1;
$check( 'taxes on: rate cost + tax = Econt\'s 5.90 (net ' . ( $r ? $r->get_cost() : '?' ) . ' + tax ' . ( $r ? $r->get_shipping_tax() : '?' ) . ')', 5.90 === $gross, 'gross=' . $gross );
WC_Tax::_delete_tax_rate( $rate_id ); update_option( 'woocommerce_calc_taxes', $tax_backup['calc'] ); update_option( 'woocommerce_shipping_tax_class', $tax_backup['class'] ); WC_Cache_Helper::invalidate_cache_group( 'taxes' ); wp_cache_flush();
$m = new Drushfe_Shipping_Method( $INSTANCE ); $m->rates = []; $m->calculate_shipping( WC()->cart->get_shipping_packages()[0] ); $r = reset( $m->rates );
$check( 'taxes off: rate cost = Econt\'s 5.90 unchanged', $r && 5.90 === (float) $r->get_cost(), 'cost=' . ( $r ? $r->get_cost() : '?' ) );

WC()->cart->empty_cart();
unset( $_POST );
update_option( $opt, $saved ); wp_cache_flush();
delete_transient( $pause_key );
echo $all ? "ALL PASS\n" : "SOME FAILED\n";
