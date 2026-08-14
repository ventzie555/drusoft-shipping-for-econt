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

			// Apply the order's pickup profile (may have been changed by the
			// admin after checkout): each profile is its own Достави с Еконт
			// store, so switching origin = switching the connect key.
			$pickup_profile = (string) $order->get_meta( '_drushfe_pickup_profile' );
			if ( '' !== $pickup_profile && 'default' !== $pickup_profile && class_exists( 'Drushfe_Shipping_Method' ) ) {
				$method      = new Drushfe_Shipping_Method( $instance_id );
				$private_key = $method->pickup_private_key( $pickup_profile );
			}

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

			// Split shipments: one Достави с Еконт order per pickup group,
			// each authorized with that group's store key. The normal path is
			// a single group covering all items with the default key.
			$split_groups = $order->get_meta( '_drushfe_split_groups' );
			$is_split     = is_array( $split_groups ) && count( $split_groups ) > 1;
			$group_defs   = $is_split ? $split_groups : [ '' => null ];

			$method = ( $is_split && class_exists( 'Drushfe_Shipping_Method' ) )
				? new Drushfe_Shipping_Method( $instance_id )
				: null;

			$first_waybill_id = null;
			$waybill_ids      = [];
			$parcel_no        = 0;

			foreach ( $group_defs as $g_key => $g_ids ) {
				$parcel_no++;
				$g_payload  = $payload;
				$items_desc = [];
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$product = $item->get_product();
					if ( ! $product ) {
						continue;
					}
					$pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
					if ( null !== $g_ids && ! in_array( $pid, (array) $g_ids, true ) ) {
						continue;
					}

					$qty    = (int) $item->get_quantity();
					$price  = (float) ( $item->get_total() + $item->get_total_tax() );
					$weight = (float) $product->get_weight();
					if ( $weight <= 0 ) {
						$weight = (float) ( $settings['teglo'] ?? 0.5 );
					}

					$name = $product->get_name();
					$g_payload['items'][] = [
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

				// COD must equal what the customer owes, and Достави с Еконт
				// derives it from the sum of items[].totalPrice — which so far
				// carried only the PRODUCT lines. The shipping the customer
				// paid (and any per-line rounding cents) was missing, so every
				// COD parcel would have collected less than the order total —
				// the same disease the Speedy plugin shipped with the amount
				// axis (fixed there 14.08.2026, order 15961: 141.66 collected
				// for a 172.81 order). One line closes both gaps: a "Доставка"
				// item carrying the exact difference to the order total.
				// Split shipments keep their own item subsets, so the delta is
				// added once, on the first parcel only.
				if ( ! $is_split || 1 === $parcel_no ) {
					$items_sum = 0.0;
					foreach ( $g_payload['items'] as $g_item ) {
						$items_sum += (float) $g_item['totalPrice'];
					}
					$delta = round( (float) $order->get_total() - $items_sum, 2 );
					if ( $is_split ) {
						// under split, compare against the WHOLE order total is
						// wrong — only shipping+rounding belongs here.
						$delta = round(
							(float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2 );
					}
					if ( $delta > 0.009 ) {
						$g_payload['items'][] = [
							'name'        => 'Доставка',
							'SKU'         => '',
							'URL'         => '',
							'count'       => 1,
							'hideCount'   => '',
							'totalPrice'  => $delta,
							'totalWeight' => 0,
						];
					}
				}

				$g_payload['shipmentDescription'] = mb_substr( implode( ', ', $items_desc ), 0, 100 );
				if ( $is_split ) {
					// each store needs its own unique order number
					$g_payload['orderNumber'] = $order_id . '-' . $parcel_no;
				}

				$g_auth = ( null !== $g_ids && $method )
					? $method->pickup_private_key( (string) $g_key )
					: $private_key;

				$response = wp_remote_post(
					$base_url . 'services/OrdersService.updateOrder.json',
					[
						'headers' => [
							'Content-Type'  => 'application/json',
							'Authorization' => $g_auth,
						],
						'body'    => wp_json_encode( $g_payload ),
						'timeout' => 20,
					]
				);

				$body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_wp_error( $response ) || ! empty( $body['type'] ) || empty( $body['id'] ) ) {
					$msg = is_wp_error( $response )
						? $response->get_error_message()
						: ( $body['message'] ?? __( 'Unknown API error', 'drusoft-shipping-for-econt' ) );
					if ( 1 === $parcel_no ) {
						$order->add_order_note( __( 'Econt Waybill Error: ', 'drusoft-shipping-for-econt' ) . $msg );
						return is_wp_error( $response ) ? $response : new WP_Error( 'api_error', $msg );
					}
					/* translators: 1: parcel number, 2: error message */
					$order->add_order_note( sprintf( __( 'Econt Waybill Error (parcel %1$d): %2$s — create it manually.', 'drusoft-shipping-for-econt' ), $parcel_no, $msg ) );
					continue;
				}

				$waybill_id = (string) $body['id'];

				// Econt's flow is two-step:
				//   1. OrdersService.updateOrder  — saves the order draft, returns id.
				//   2. OrdersService.createAWB    — promotes to an Air Waybill,
				//                                   returns shipmentNumber + pdfURL.
				$awb_response = wp_remote_post(
					$base_url . 'services/OrdersService.createAWB.json',
					[
						'headers' => [
							'Content-Type'  => 'application/json',
							'Authorization' => $g_auth,
						],
						'body'    => wp_json_encode( [ 'id' => (int) $waybill_id ] ),
						'timeout' => 20,
					]
				);

				$awb_body = is_wp_error( $awb_response )
					? null
					: json_decode( wp_remote_retrieve_body( $awb_response ), true );

				if ( is_array( $awb_body ) && empty( $awb_body['type'] ) ) {
					$body = array_merge( $body, $awb_body );
				} else {
					$awb_err = is_wp_error( $awb_response )
						? $awb_response->get_error_message()
						: ( $awb_body['message'] ?? __( 'Unknown error', 'drusoft-shipping-for-econt' ) );
					$order->add_order_note( __( 'Econt createAWB warning: ', 'drusoft-shipping-for-econt' ) . $awb_err );
				}

				$waybill_ids[] = $waybill_id;
				if ( 1 === $parcel_no ) {
					$first_waybill_id = $waybill_id;
					$order->update_meta_data( '_drushfe_waybill_id', $waybill_id );
					$order->update_meta_data( '_drushfe_waybill_response', $body );
					// The public parcel number lives in shipmentNumber — the id above is
					// Econt's INTERNAL order id, which track-shipment does not resolve.
					// Customer-facing tracking must use this one.
					if ( ! empty( $body['shipmentNumber'] ) ) {
						$order->update_meta_data( '_drushfe_shipment_number', (string) $body['shipmentNumber'] );
					}
					$order->add_order_note( __( 'Econt Waybill Created: ', 'drusoft-shipping-for-econt' ) . $waybill_id );
				} else {
					/* translators: 1: parcel number, 2: waybill id */
					$order->add_order_note( sprintf( __( 'Econt Waybill Created (parcel %1$d): %2$s', 'drusoft-shipping-for-econt' ), $parcel_no, $waybill_id ) );
				}
			}

			if ( null === $first_waybill_id ) {
				return new WP_Error( 'unexpected_response', __( 'Unexpected response from Econt API.', 'drusoft-shipping-for-econt' ) );
			}
			if ( count( $waybill_ids ) > 1 ) {
				$order->update_meta_data( '_drushfe_waybill_ids', $waybill_ids );
			}
			$order->save();
			return $first_waybill_id;
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
