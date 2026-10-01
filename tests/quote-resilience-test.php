<?php
// Can one bad input or one bad Econt reply still ship an order for free?
// trisestri.bg order 816 (30.09.2026): city София + typed postcode 2230 →
// Econt refused the quote, nothing was logged, nothing re-asked, shipping 0.00.
// Real cart, real session, only pre_http_request intercepted.
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfe_econt_(\\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfe_econt instance configured on this site\n"; return; }
if ( ! WC()->session ) { WC()->initialize_session(); }
global $wpdb;
$pid = wc_get_product_id_by_sku( 'COD-TEST-2999' );
if ( ! $pid ) { echo "product COD-TEST-2999 missing\n"; return; }
$sofia = $wpdb->get_row( "SELECT id, name, post_code FROM {$wpdb->prefix}drushfe_cities WHERE name LIKE '%София%' AND post_code = '1000' LIMIT 1" );
if ( ! $sofia ) { echo "drushfe_cities has no София/1000 — run the location sync first\n"; return; }

$all   = true;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$all ) {
	$all &= $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  [$detail]" : '' );
};

// ── Econt stand-in ────────────────────────────────────────────────────────
$captured = [];
$reply    = null; // array => JSON body; WP_Error => transport failure
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured, &$reply ) {
	if ( ! str_contains( $url, 'OrdersService.getPrice' ) ) { return $pre; }
	$captured[] = [ 'headers' => $args['headers'], 'body' => json_decode( $args['body'], true ) ];
	if ( $reply instanceof WP_Error ) { return $reply; }
	return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $reply ), 'headers' => [] ];
}, 10, 3 );
$ok_reply = [ 'receiverDueAmount' => 5.42, 'totalPrice' => 5.42, 'currency' => 'EUR' ];
$last_log = '';
add_filter( 'woocommerce_logger_log_message', function ( $message, $level, $context ) use ( &$last_log ) {
	if ( 'drusoft-shipping-for-econt' === ( $context['source'] ?? '' ) ) { $last_log = (string) $message; }
	return $message;
}, 10, 3 );

WC()->cart->empty_cart();
WC()->cart->add_to_cart( $pid, 1 );
WC()->session->set( 'chosen_shipping_methods', [ 'drushfe_econt:' . $INSTANCE ] );

$sel = [
	'delivery_type' => 'office', 'city_id' => (int) $sofia->id, 'city_name' => $sofia->name,
	'postcode' => '2230', // Костинброд — what the customer actually typed
	'office_code' => '1003', 'address' => '', 'state' => 'BG-22', 'payment_method' => 'cod',
];

// 1. The typed postcode never reaches Econt; the nomenclature's does, with cityID.
$reply = $ok_reply; $captured = [];
$q = drushfe_quote( $sel );
$ci = $captured[0]['body']['customerInfo'] ?? [];
$check( 'typed 2230 → payload carries the city\'s 1000', ( $ci['postCode'] ?? '' ) === '1000' && ! is_wp_error( $q ), 'postCode=' . ( $ci['postCode'] ?? '?' ) );
$check( 'payload carries cityID', (int) ( $ci['cityID'] ?? 0 ) === (int) $sofia->id );
$check( 'Accept-Language sent', in_array( $captured[0]['headers']['Accept-Language'] ?? '', [ 'bg', 'en' ], true ), 'lang=' . ( $captured[0]['headers']['Accept-Language'] ?? '-' ) );
$check( 'quote price 5.42', ! is_wp_error( $q ) && 5.42 === (float) $q['price'] );

// 2. The session remembers the typed postcode for diagnosis, but quotes with the city's.
drushfe_remember_selection( $sel );
$check( 'session keeps typed postcode 2230', '2230' === (string) WC()->session->get( 'drushfe_postcode' ) );

// 3. An Econt refusal is a WP_Error, logged with the inputs.
$reply = [ 'type' => 'ExInvalidCity', 'message' => 'Несъответствие между населено място и пощенски код.' ];
$last_log = '';
$q = drushfe_quote( $sel );
$check( 'Econt refusal → WP_Error with Econt\'s words', is_wp_error( $q ) && str_contains( $q->get_error_message(), 'Несъответствие' ) );
$check( 'refusal logged with the selection', str_contains( $last_log, 'Несъответствие' ) && str_contains( $last_log, '"office_code":"1003"' ), mb_substr( $last_log, 0, 80 ) );

// 4. A transport failure is a WP_Error, logged, customer-worded.
$reply = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' );
$last_log = '';
$q = drushfe_quote( $sel );
$check( 'timeout → WP_Error, log has the cURL message', is_wp_error( $q ) && str_contains( $last_log, 'cURL error 28' ) );

// 5. Storing and forgetting a quote move cost and receiver_due together.
$reply = $ok_reply;
$q = drushfe_quote( $sel );
drushfe_store_quote( $q, 0 );
$check( 'store: cost 5.42 / due 5.42', 5.42 === (float) WC()->session->get( 'drushfe_shipping_cost' ) && 5.42 === (float) WC()->session->get( 'drushfe_receiver_due' ) );
drushfe_forget_quote();
$due = WC()->session->get( 'drushfe_receiver_due', null ); // a null set() removes the key
$check( 'forget: cost 0 AND due cleared', 0.0 === (float) WC()->session->get( 'drushfe_shipping_cost' ) && ( null === $due || '' === $due ), 'due=' . var_export( $due, true ) );

// 6. Submit with no price in the session → re-quoted from the posted form.
$_POST = [
	'billing_city' => (string) $sofia->id, 'billing_postcode' => '2230', 'billing_state' => 'BG-22', 'billing_address_1' => '',
	'econt_delivery_type' => 'office', 'econt_office_id' => '1003', 'payment_method' => 'cod',
];
$reply = $ok_reply; $captured = [];
drushfe_requote_on_submit();
$check( 'submit with cost 0 → re-quote runs', 1 === count( $captured ) );
$check( 'submit re-quote stores 5.42', 5.42 === (float) WC()->session->get( 'drushfe_shipping_cost' ) );
$check( 'submit re-quote used the city\'s postcode', ( $captured[0]['body']['customerInfo']['postCode'] ?? '' ) === '1000' );

// 7. Submit with a valid price for the SAME basket → no extra call.
$captured = [];
drushfe_requote_on_submit();
$check( 'submit with a live quote → no re-quote', 0 === count( $captured ) );

// 8. Basket changed after the quote → re-quoted.
WC()->cart->add_to_cart( $pid, 1 );
$captured = [];
drushfe_requote_on_submit();
$check( 'basket changed → re-quote', 1 === count( $captured ) );

// 9. Submit re-quote fails → cost stays 0, reason kept for the order note.
drushfe_forget_quote();
$reply = [ 'type' => 'ExInvalidCity', 'message' => 'Несъответствие между населено място и пощенски код.' ];
drushfe_requote_on_submit();
$check( 'failed submit re-quote → cost 0, reason kept', 0.0 === (float) WC()->session->get( 'drushfe_shipping_cost' ) && str_contains( (string) WC()->session->get( 'drushfe_last_quote_error' ), 'Несъответствие' ) );

// 10. Office mode without an office → no quote attempted (validation refuses the order).
$_POST['econt_office_id'] = ''; $captured = [];
drushfe_requote_on_submit();
$check( 'no office → no re-quote', 0 === count( $captured ) );

// 11. Zero product weight with an empty default-weight setting → 0.5 kg, not 0.
$_POST = [];
$opt = 'woocommerce_drushfe_econt_' . $INSTANCE . '_settings';
$settings = get_option( $opt ); $saved_teglo = $settings['teglo'] ?? null; $settings['teglo'] = ''; update_option( $opt, $settings );
$prod = wc_get_product( $pid ); $saved_w = $prod->get_weight(); $prod->set_weight( '' ); $prod->save();
WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid, 1 );
$reply = $ok_reply; $captured = [];
drushfe_quote( $sel );
$w = $captured[0]['body']['items'][0]['totalWeight'] ?? null;
$check( 'weight 0 + empty default → 0.5 kg sent', 0.5 === (float) $w, 'totalWeight=' . var_export( $w, true ) );
$prod->set_weight( $saved_w ); $prod->save();
if ( null !== $saved_teglo ) { $settings['teglo'] = $saved_teglo; } else { unset( $settings['teglo'] ); }
update_option( $opt, $settings );

// 12. Order save keeps the typed postcode in meta and normalises the field.
WC()->session->set( 'chosen_shipping_methods', [ 'drushfe_econt:' . $INSTANCE ] ); // empty_cart() drops it
$o = wc_create_order(); $o->add_product( wc_get_product( $pid ), 1 );
$o->set_billing_city( (string) $sofia->id ); $o->set_billing_postcode( '2230' );
$o->set_shipping_city( (string) $sofia->id ); $o->set_shipping_postcode( '2230' );
$i = new WC_Order_Item_Shipping(); $i->set_method_id( 'drushfe_econt' ); $i->set_instance_id( $INSTANCE ); $o->add_item( $i );
$o->calculate_totals( false );
( new Drushfe_Shipping_Method( $INSTANCE ) )->save_shipping_data_to_order( $o );
$o->save(); $o = wc_get_order( $o->get_id() );
$check( 'order: postcode normalised to 1000', '1000' === $o->get_billing_postcode() );
$check( 'order: typed 2230 kept in meta', '2230' === (string) $o->get_meta( '_drushfe_billing_typed_postcode' ) );
$o->delete( true );

WC()->cart->empty_cart();
echo $all ? "ALL PASS\n" : "SOME FAILED\n";
