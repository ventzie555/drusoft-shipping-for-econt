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
	 * Cancel a shipment via Econt's OrdersService.deleteLabel.json.
	 *
	 * Earlier this called `Shipments/LabelService.deleteLabels.json` on
	 * delivery.econt.com — but that service lives on ee.econt.com and is
	 * keyed by `shipmentNumber` (the 13-digit AWB number), not the
	 * internal `id` we store as `_drushfe_waybill_id`. delivery.econt.com
	 * exposes `OrdersService.deleteLabel` (singular) which takes the id
	 * directly and is the right call for a single-order cancel.
	 *
	 * Request:  {id: <int waybill_id>}
	 * Response: top-level `type` non-empty = error; otherwise success.
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
			$ctx['base'] . 'services/OrdersService.deleteLabel.json',
			[
				'headers' => $ctx['headers'],
				'body'    => wp_json_encode( [ 'id' => (int) $waybill_id ] ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['type'] ) ) {
			wp_send_json_error( $body['message'] ?? __( 'Econt API error', 'drusoft-shipping-for-econt' ) );
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

		// Pickup time window in Europe/Sofia.
		// Rules:
		//   - If it's after 16:00, the dispatch cutoff has passed → schedule
		//     for the next working day at 09:00.
		//   - Weekends (Sat/Sun) get skipped to Monday.
		//   - Bulgarian bank holidays are not detected here (we have no holiday
		//     calendar locally); Econt will reject with "X е почивен ден" and
		//     the merchant can retry tomorrow. Surfacing that error verbatim is
		//     handled below.
		$tz  = new DateTimeZone( 'Europe/Sofia' );
		$now = new DateTime( 'now', $tz );

		$is_weekend   = in_array( (int) $now->format( 'N' ), [ 6, 7 ], true ); // 6=Sat, 7=Sun
		$after_cutoff = (int) $now->format( 'H' ) >= 16;

		if ( $is_weekend || $after_cutoff ) {
			$now->modify( '+1 day' );
			while ( in_array( (int) $now->format( 'N' ), [ 6, 7 ], true ) ) {
				$now->modify( '+1 day' );
			}
			$now->setTime( 9, 0 );
		}

		$end_hhmm  = ! empty( $settings['sender_time'] ) ? $settings['sender_time'] : '17:30';
		$end_parts = explode( ':', $end_hhmm );
		$end       = clone $now;
		$end->setTime( (int) ( $end_parts[0] ?? 17 ), (int) ( $end_parts[1] ?? 30 ) );

		$from = clone $now;
		if ( $from > $end ) {
			$from->setTime( 9, 0 );
		}

		// Econt's requestCourier wants `Y-m-d H:i:s` strings — NOT ISO 8601
		// with the `T` separator (the API rejects ATOM with "Невалиден час").
		// `senderClient.phones` is a flat string array, NOT a `phone` field
		// nor a `[{number: ...}]` object array.
		$sender_phone = (string) ( $settings['sender_phone'] ?? '' );
		$payload = [
			'requestTimeFrom'   => $from->format( 'Y-m-d H:i:s' ),
			'requestTimeTo'     => $end->format( 'Y-m-d H:i:s' ),
			'shipmentType'      => 'pack',
			'shipmentPackCount' => 1,
			'shipmentWeight'    => (float) ( $settings['teglo'] ?? 1 ),
			'attachShipments'   => [ $waybill_id ],
			'senderClient'      => [
				'name'   => $settings['sender_name'] ?? get_bloginfo( 'name' ),
				'phones' => $sender_phone !== '' ? [ $sender_phone ] : [],
				'email'  => $settings['sender_email'] ?? get_option( 'admin_email' ),
			],
			'senderAddress'     => [
				'city'   => [
					'name'    => drushfe_get_city_name_by_id( (int) ( $settings['sender_city'] ?? 0 ) ) ?: '',
					'country' => [ 'code3' => 'BGR' ],
				],
				'street' => (string) ( $settings['sender_street'] ?? '' ),
				'num'    => (string) ( $settings['sender_num'] ?? '' ),
			],
		];

		// ShipmentService lives on ee.econt.com — not delivery.econt.com,
		// which only hosts OrdersService / PaymentsService / GroupingService.
		// Use the demo-aware ee_base so test_mode safely targets demo.econt.com.
		$response = wp_remote_post(
			$ctx['ee_base'] . 'services/Shipments/ShipmentService.requestCourier.json',
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
			// Econt nests the real cause under innerErrors when the outer
			// message is empty/whitespace (e.g. the "phones required" case).
			$err_msg = trim( (string) ( $body['message'] ?? '' ) );
			if ( '' === $err_msg && ! empty( $body['innerErrors'][0]['message'] ) ) {
				$err_msg = (string) $body['innerErrors'][0]['message'];
			}
			wp_send_json_error( $err_msg !== '' ? $err_msg : __( 'Econt courier request failed.', 'drusoft-shipping-for-econt' ) );
		}

		$courier_request_id = $body['courierRequestID'] ?? '';
		$delayed_warning    = trim( (string) ( $body['delayedRequestWarning'] ?? '' ) );

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
		if ( '' !== $delayed_warning ) {
			/* translators: %s: warning text returned by Econt */
			$order->add_order_note( sprintf( __( 'Econt notice: %s', 'drusoft-shipping-for-econt' ), wp_strip_all_tags( $delayed_warning ) ) );
		}
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

		// wp_safe_redirect() rejects off-site URLs unless the host is in the
		// allowed_redirect_hosts list. The PDF lives on an Econt sub-domain
		// (delivery.econt.com / delivery-demo.econt.com / labels.econt.com),
		// so allow any *.econt.com host derived from the URL itself.
		$pdf_host = wp_parse_url( $pdf_url, PHP_URL_HOST );
		if ( ! is_string( $pdf_host ) || ! preg_match( '/(^|\.)econt\.com$/', $pdf_host ) ) {
			wp_die( esc_html__( 'Unexpected PDF host — refusing to redirect.', 'drusoft-shipping-for-econt' ) );
		}
		add_filter( 'allowed_redirect_hosts', static function ( $hosts ) use ( $pdf_host ) {
			$hosts[] = $pdf_host;
			return $hosts;
		} );

		wp_safe_redirect( esc_url_raw( $pdf_url ) );
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
			// `base` hosts OrdersService / PaymentsService / GroupingService.
			'base'     => $is_demo ? 'https://delivery-demo.econt.com/' : 'https://delivery.econt.com/',
			// `ee_base` hosts Nomenclatures + Shipments (LabelService,
			// ShipmentService, etc.). Different host, different demo URL.
			'ee_base'  => $is_demo ? 'https://demo.econt.com/ee/' : 'https://ee.econt.com/',
			'headers'  => [
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['econt_private_key'],
			],
			'settings' => $settings,
		];
	}
}

Drushfe_Actions::init();
