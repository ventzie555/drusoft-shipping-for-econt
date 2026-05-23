<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Drushfe_Waybill_Generator' ) ) {

	/**
	 * Generates Econt waybills via the OrdersService.updateOrder endpoint on
	 * delivery.econt.com (or delivery-demo.econt.com when demo mode is enabled).
	 *
	 * Modelled after the Speedy generator's lifecycle (hooked to order-status
	 * transitions, idempotent via _drushfe_waybill_id meta) but with Econt's
	 * payload shape — see drusoft-econt-shipping-bridge lines 766–844 for the
	 * reference payload.
	 */
	class Drushfe_Waybill_Generator {

		protected static $_instance = null;

		public static function instance(): ?Drushfe_Waybill_Generator {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		public function __construct() {
			add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		}

		public function on_order_status_changed( int $order_id, string $status_from, string $status_to, WC_Order $order ): void {
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method  = reset( $shipping_methods );

			if ( ! $shipping_method || 'drushfe_econt' !== $shipping_method->get_method_id() ) {
				return;
			}

			$instance_id = $shipping_method->get_instance_id();
			$settings    = get_option( 'woocommerce_drushfe_econt_' . $instance_id . '_settings' );

			$should_generate  = ( 'yes' === ( $settings['generate_waybill'] ?? 'no' ) );
			$is_target_status = in_array( $status_to, [ 'processing', 'on-hold' ], true );

			if ( $should_generate && $is_target_status ) {
				$this->generate_waybill( $order_id );
			}
		}

		/**
		 * Generate the Econt waybill for an order.
		 *
		 * @return string|WP_Error Econt order id on success.
		 */
		public function generate_waybill( int $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'drusoft-shipping-for-econt' ) );
			}

			if ( $order->get_meta( '_drushfe_waybill_id' ) ) {
				return $order->get_meta( '_drushfe_waybill_id' );
			}

			$shipping_methods = $order->get_shipping_methods();
			$shipping_method  = reset( $shipping_methods );
			$instance_id      = $shipping_method->get_instance_id();
			$settings         = get_option( 'woocommerce_drushfe_econt_' . $instance_id . '_settings' );

			$private_key = $settings['econt_private_key'] ?? '';
			if ( ! $private_key ) {
				return new WP_Error( 'no_credentials', __( 'Econt private key is not configured.', 'drusoft-shipping-for-econt' ) );
			}

			$is_demo  = 'yes' === ( $settings['econt_test_mode'] ?? 'no' );
			$base_url = $is_demo ? 'https://delivery-demo.econt.com/' : 'https://delivery.econt.com/';

			// Recipient selection — read from order meta saved during checkout.
			$delivery_type = (string) $order->get_meta( '_drushfe_delivery_type' );
			$office_code   = (string) $order->get_meta( '_drushfe_office_id' );
			$cod           = in_array( $order->get_payment_method(), [ 'cod' ], true );

			$payload = [
				'id'                  => '',
				'orderNumber'         => (string) $order_id,
				'status'              => $order->get_status(),
				'orderTime'           => '',
				'cod'                 => $cod,
				'partialDelivery'     => $cod ? true : '',
				'currency'            => get_woocommerce_currency(),
				'shipmentDescription' => '',
				'shipmentNumber'      => '',
				'clientSoftware'      => 'drusoft-shipping-for-econt',
				'customerInfo'        => array_merge(
					[
						'id'           => '',
						'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'face'         => '',
						'phone'        => $order->get_billing_phone(),
						'email'        => $order->get_billing_email(),
						'countryCode'  => 'BGR',
						'cityName'     => $order->get_shipping_city() ?: $order->get_billing_city(),
						'postCode'     => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
						'officeCode'   => ( 'office' === $delivery_type || 'automat' === $delivery_type ) ? $office_code : '',
						'zipCode'      => '',
						'priorityFrom' => '',
						'priorityTo'   => '',
					],
					( 'address' === $delivery_type )
						? self::build_address_fields(
							trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() )
						)
						: [ 'address' => '' ]
				),
				'items'               => [],
				'paymentToken'        => '',
			];

			$items_desc = [];
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}

				$qty    = (int) $item->get_quantity();
				$price  = (float) ( $item->get_total() + $item->get_total_tax() );
				$weight = (float) $product->get_weight();
				if ( $weight <= 0 ) {
					$weight = (float) ( $settings['teglo'] ?? 0.5 );
				}

				$name = $product->get_name();
				$payload['items'][] = [
					'name'        => $name,
					'SKU'         => $product->get_sku(),
					'URL'         => '',
					'count'       => $qty,
					'hideCount'   => '',
					'totalPrice'  => $price,
					'totalWeight' => $weight * $qty,
				];
				$items_desc[] = $name;
			}
			$payload['shipmentDescription'] = mb_substr( implode( ', ', $items_desc ), 0, 100 );

			$response = wp_remote_post(
				$base_url . 'services/OrdersService.updateOrder.json',
				[
					'headers' => [
						'Content-Type'  => 'application/json',
						'Authorization' => $private_key,
					],
					'body'    => wp_json_encode( $payload ),
					'timeout' => 20,
				]
			);

			if ( is_wp_error( $response ) ) {
				$order->add_order_note( __( 'Econt Waybill Error: ', 'drusoft-shipping-for-econt' ) . $response->get_error_message() );
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			// Econt signals errors via a non-empty `type` field rather than `error`.
			if ( ! empty( $body['type'] ) ) {
				$msg = $body['message'] ?? __( 'Unknown API error', 'drusoft-shipping-for-econt' );
				$order->add_order_note( __( 'Econt Waybill Error: ', 'drusoft-shipping-for-econt' ) . $msg );
				return new WP_Error( 'api_error', $msg );
			}

			if ( empty( $body['id'] ) ) {
				return new WP_Error( 'unexpected_response', __( 'Unexpected response from Econt API.', 'drusoft-shipping-for-econt' ) );
			}

			$waybill_id = (string) $body['id'];

			// Econt's flow is two-step:
			//   1. OrdersService.updateOrder  — saves the order draft, returns id.
			//   2. OrdersService.createAWB    — promotes to an Air Waybill,
			//                                   returns the full ShipmentStatus
			//                                   incl. shipmentNumber + pdfURL.
			// Without step 2 we have no PDF URL to print and no shipmentNumber
			// to cancel against, so do it now and merge the result back into
			// `_drushfe_waybill_response`.
			$awb_response = wp_remote_post(
				$base_url . 'services/OrdersService.createAWB.json',
				[
					'headers' => [
						'Content-Type'  => 'application/json',
						'Authorization' => $private_key,
					],
					'body'    => wp_json_encode( [ 'id' => (int) $waybill_id ] ),
					'timeout' => 20,
				]
			);

			$awb_body = is_wp_error( $awb_response )
				? null
				: json_decode( wp_remote_retrieve_body( $awb_response ), true );

			if ( is_array( $awb_body ) && empty( $awb_body['type'] ) ) {
				// Merge so we keep both the order-side fields (customerInfo, items, …)
				// and the AWB-side fields (shipmentNumber, pdfURL, receiverDueAmount, …).
				$body = array_merge( $body, $awb_body );
			} else {
				$awb_err = is_wp_error( $awb_response )
					? $awb_response->get_error_message()
					: ( $awb_body['message'] ?? __( 'Unknown error', 'drusoft-shipping-for-econt' ) );
				$order->add_order_note( __( 'Econt createAWB warning: ', 'drusoft-shipping-for-econt' ) . $awb_err );
			}

			$order->update_meta_data( '_drushfe_waybill_id', $waybill_id );
			$order->update_meta_data( '_drushfe_waybill_response', $body );
			$order->add_order_note( __( 'Econt Waybill Created: ', 'drusoft-shipping-for-econt' ) . $waybill_id );
			$order->save();
			return $waybill_id;
		}

		/**
		 * Split a Bulgarian shipping address into the structured fields
		 * Econt's API actually requires.
		 *
		 * Econt rejects a free-form `address` value alone with "Нужно е да
		 * добавите улица и номер или да попълните полетата Квартал и Друго".
		 * Valid pairs are (`street` + `num`) OR (`quarter` + `other`).
		 *
		 * We try to extract a trailing house number from the address string
		 * (e.g. "ул. Кирил и Методий 3" → street "ул. Кирил и Методий",
		 * num "3"). House numbers may include a slash or trailing letter
		 * (`12А`, `7/3`). When no trailing number is found we put the
		 * whole string into `other` so Econt accepts the second valid pair.
		 *
		 * @param string $raw Full shipping line (address_1 + address_2).
		 * @return array Subset of customerInfo: street/num or other/address.
		 */
		private static function build_address_fields( string $raw ): array {
			$raw = trim( $raw );
			if ( $raw === '' ) {
				return [ 'address' => '' ];
			}

			if ( preg_match( '/^(.+?)\s+(\d[\d\/А-Яа-я\-]*)\s*$/u', $raw, $m ) ) {
				return [
					'street'  => trim( $m[1] ),
					'num'     => trim( $m[2] ),
					'address' => $raw,
				];
			}

			// Couldn't isolate a house number — satisfy the alternative
			// "quarter + other" requirement by stuffing the raw address into
			// `other`. `quarter` is left blank; Econt accepts a populated
			// `other` on its own in practice.
			return [
				'other'   => $raw,
				'address' => $raw,
			];
		}
	}
}

Drushfe_Waybill_Generator::instance();
