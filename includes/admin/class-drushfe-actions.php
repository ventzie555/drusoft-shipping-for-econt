<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drushfe_Actions {

	public static function init(): void {
		// AJAX Actions
		add_action( 'wp_ajax_drushfe_cancel_shipment', [ __CLASS__, 'cancel_shipment' ] );
		add_action( 'wp_ajax_drushfe_request_courier', [ __CLASS__, 'request_courier' ] );
		add_action( 'wp_ajax_drushfe_generate_waybill', [ __CLASS__, 'generate_waybill_ajax' ] );
		
		// Admin Post Action for PDF Printing (File Stream)
		add_action( 'admin_post_drushfe_print_waybill', [ __CLASS__, 'print_waybill' ] );
	}

	/**
	 * AJAX handler for manual waybill generation.
	 */
	public static function generate_waybill_ajax(): void {
		check_ajax_referer( 'drushfe_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-econt' ) );
		}

		$result = Drushfe_Waybill_Generator::instance()->generate_waybill( $order_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Waybill generated successfully.', 'drusoft-shipping-for-econt' ) );
	}

	/**
	 * Cancel a shipment via Econt's LabelService.deleteLabels.json.
	 *
	 * Request:  {shipmentNumbers: ["<waybill>"]}
	 * Response: {results: [{shipmentNum, error: null|{type,message}}]}
	 *           null/missing error per row = success.
	 */
	public static function cancel_shipment(): void {
		check_ajax_referer( 'drushfe_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-econt' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'drusoft-shipping-for-econt' ) );
		}

		$waybill_id = (string) $order->get_meta( '_drushfe_waybill_id' );
		if ( '' === $waybill_id ) {
			wp_send_json_error( __( 'No waybill found for this order.', 'drusoft-shipping-for-econt' ) );
		}

		$ctx = self::get_api_context_for_order( $order );
		if ( ! $ctx ) {
			wp_send_json_error( __( 'Econt API credentials not configured.', 'drusoft-shipping-for-econt' ) );
		}

		$response = wp_remote_post(
			$ctx['base'] . 'services/Shipments/LabelService.deleteLabels.json',
			[
				'headers' => $ctx['headers'],
				'body'    => wp_json_encode( [ 'shipmentNumbers' => [ $waybill_id ] ] ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Top-level error (auth, etc.)
		if ( ! empty( $body['type'] ) ) {
			wp_send_json_error( $body['message'] ?? __( 'Econt API error', 'drusoft-shipping-for-econt' ) );
		}

		// Per-shipment error
		$row_error = $body['results'][0]['error'] ?? null;
		if ( is_array( $row_error ) && ! empty( $row_error['message'] ) ) {
			wp_send_json_error( $row_error['message'] );
		}

		// Success: clear waybill meta + log on the order.
		$order->delete_meta_data( '_drushfe_waybill_id' );
		$order->delete_meta_data( '_drushfe_waybill_response' );
		$order->delete_meta_data( '_drushfe_courier_requested' );
		/* translators: %s: Econt waybill ID */
		$order->add_order_note( sprintf( __( 'Econt shipment %s cancelled.', 'drusoft-shipping-for-econt' ), $waybill_id ) );
		$order->save();

		wp_send_json_success( __( 'Shipment cancelled successfully.', 'drusoft-shipping-for-econt' ) );
	}

	/**
	 * Request an Econt courier pickup via ShipmentService.requestCourier.json.
	 *
	 * Request payload (per Econt OpenAPI):
	 *   requestTimeFrom: ISO 8601 datetime  (when courier should arrive earliest)
	 *   requestTimeTo:   ISO 8601 datetime  (latest)
	 *   shipmentType:    'pack' | 'document' | 'pallet' | 'cargo'
	 *   shipmentPackCount: int
	 *   shipmentWeight:    float (kg)
	 *   attachShipments:   array of waybill IDs to attach
	 *   senderClient:      ClientProfile (name, phone, email, …)
	 *   senderAddress:     Address (city, street, num, …)
	 *
	 * v0.2 NOTE: Econt may auto-request a courier when the waybill is created.
	 * If so, this endpoint can return an "already ordered" type which we treat
	 * as success.
	 */
	public static function request_courier(): void {
		check_ajax_referer( 'drushfe_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-econt' ) );
		}

		$order      = wc_get_order( $order_id );
		$waybill_id = $order ? (string) $order->get_meta( '_drushfe_waybill_id' ) : '';
		if ( '' === $waybill_id ) {
			wp_send_json_error( __( 'No waybill found for this order.', 'drusoft-shipping-for-econt' ) );
		}

		$ctx = self::get_api_context_for_order( $order );
		if ( ! $ctx ) {
			wp_send_json_error( __( 'Econt API credentials not configured.', 'drusoft-shipping-for-econt' ) );
		}
		$settings = $ctx['settings'];

		// Pickup time window: today (or tomorrow after 16:00) up to the configured
		// end-of-day (sender_time, default 17:30) in Europe/Sofia.
		$tz  = new DateTimeZone( 'Europe/Sofia' );
		$now = new DateTime( 'now', $tz );
		if ( (int) $now->format( 'H' ) >= 16 ) {
			$now->modify( '+1 day' );
		}
		$end_hhmm  = ! empty( $settings['sender_time'] ) ? $settings['sender_time'] : '17:30';
		$end_parts = explode( ':', $end_hhmm );
		$end       = clone $now;
		$end->setTime( (int) ( $end_parts[0] ?? 17 ), (int) ( $end_parts[1] ?? 30 ) );

		// "From" is now (or 09:00 if we bumped to tomorrow); "to" is the configured EOD.
		$from = clone $now;
		if ( $from > $end ) {
			$from->setTime( 9, 0 );
		}

		$payload = [
			'requestTimeFrom'   => $from->format( DateTime::ATOM ),
			'requestTimeTo'     => $end->format( DateTime::ATOM ),
			'shipmentType'      => 'pack',
			'shipmentPackCount' => 1,
			'shipmentWeight'    => (float) ( $settings['teglo'] ?? 1 ),
			'attachShipments'   => [ $waybill_id ],
			'senderClient'      => [
				'name'  => $settings['sender_name'] ?? get_bloginfo( 'name' ),
				'phone' => $settings['sender_phone'] ?? '',
				'email' => $settings['sender_email'] ?? get_option( 'admin_email' ),
			],
			'senderAddress'     => [
				'city'   => [ 'name' => '', 'country' => [ 'code3' => 'BGR' ] ],
				'street' => '',
				'num'    => '',
			],
		];

		$response = wp_remote_post(
			$ctx['base'] . 'services/Shipments/ShipmentService.requestCourier.json',
			[
				'headers' => $ctx['headers'],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Treat "already ordered" as success.
		$already_ordered = isset( $body['type'] ) && false !== stripos( (string) $body['type'], 'AlreadyOrdered' );

		if ( ! empty( $body['type'] ) && ! $already_ordered ) {
			wp_send_json_error( $body['message'] ?? __( 'Econt courier request failed.', 'drusoft-shipping-for-econt' ) );
		}

		$courier_request_id = $body['courierRequestID'] ?? '';

		$order->update_meta_data( '_drushfe_courier_requested', 'yes' );
		if ( $courier_request_id ) {
			$order->update_meta_data( '_drushfe_courier_request_id', $courier_request_id );
		}
		$order->add_order_note(
			$courier_request_id
				/* translators: %s: Econt courier request ID */
				? sprintf( __( 'Courier requested (Econt ID %s).', 'drusoft-shipping-for-econt' ), $courier_request_id )
				: __( 'Courier requested.', 'drusoft-shipping-for-econt' )
		);
		$order->save();

		wp_send_json_success( __( 'Courier requested successfully.', 'drusoft-shipping-for-econt' ) );
	}

	/**
	 * Print Waybill — redirect to the PDF URL that Econt returns alongside
	 * OrdersService.updateOrder. We saved the full response as
	 * `_drushfe_waybill_response` order meta when the waybill was generated.
	 *
	 * No live API call needed: the URL is signed/persistent on Econt's side.
	 */
	public static function print_waybill(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
		}

		check_admin_referer( 'drushfe_print_waybill' );

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid order ID.', 'drusoft-shipping-for-econt' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'drusoft-shipping-for-econt' ) );
		}

		$response_meta = $order->get_meta( '_drushfe_waybill_response' );
		$pdf_url = is_array( $response_meta ) ? ( $response_meta['pdfURL'] ?? '' ) : '';

		if ( empty( $pdf_url ) ) {
			wp_die( esc_html__( 'PDF URL is not available for this order. Regenerate the waybill to refresh it.', 'drusoft-shipping-for-econt' ) );
		}

		wp_redirect( esc_url_raw( $pdf_url ) );
		exit;
	}

	/**
	 * Helper: Get the full instance settings for a specific order.
	 * Falls back to the first instance with a saved private key.
	 */
	private static function get_settings_for_order( $order ): ?array {
		foreach ( $order->get_shipping_methods() as $shipping_method ) {
			if ( 'drushfe_econt' === $shipping_method->get_method_id() ) {
				$instance_id = $shipping_method->get_instance_id();
				$settings = get_option( 'woocommerce_drushfe_econt_' . $instance_id . '_settings' );
				if ( is_array( $settings ) && ! empty( $settings['econt_private_key'] ) ) {
					return $settings;
				}
			}
		}

		// Fallback: pick the first instance with a saved key.
		return drushfe_get_first_credentials();
	}

	/**
	 * Helper: Build the base URL for OrdersService / Shipments endpoints
	 * (production vs demo) and the Authorization header for an order.
	 *
	 * @return array{base:string,headers:array}|null
	 */
	private static function get_api_context_for_order( $order ): ?array {
		$settings = self::get_settings_for_order( $order );
		if ( ! $settings || empty( $settings['econt_private_key'] ) ) {
			return null;
		}

		$is_demo = ! empty( $settings['econt_test_mode'] ) && 'yes' === $settings['econt_test_mode'];
		return [
			'base'     => $is_demo ? 'https://delivery-demo.econt.com/' : 'https://delivery.econt.com/',
			'headers'  => [
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['econt_private_key'],
			],
			'settings' => $settings,
		];
	}
}

Drushfe_Actions::init();
