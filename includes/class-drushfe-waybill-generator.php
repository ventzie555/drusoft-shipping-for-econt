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

			// Inspection before payment. Resolved at waybill time so the merchant's
			// current setting applies even to orders checked out before the option
			// existed. Only meaningful on COD shipments; impossible at Econtomats.
			$inspection = (string) ( $settings['inspection_option'] ?? 'none' );
			$can_inspect = $cod && 'automat' !== $delivery_type;

			$payload = [
				'id'                  => '',
				'orderNumber'         => (string) $order_id,
				'status'              => $order->get_status(),
				'orderTime'           => '',
				'cod'                 => $cod,
				'partialDelivery'     => $cod ? true : '',
				'payAfterAccept'      => $can_inspect && in_array( $inspection, [ 'accept', 'test' ], true ),
				'payAfterTest'        => $can_inspect && 'test' === $inspection,
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
				$g_products = [];
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
					$g_products[] = $product;
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
				//
				// EXCEPT when the recipient pays the courier. Who pays is set in
				// the merchant's Достави с Еконт store profile, not anywhere in
				// this payload, and Econt's own quote tells which it is:
				//   receiverDueAmount = 0  — the shop pays; Econt collects only
				//                            what items[] says, so our shipping
				//                            line MUST be folded in (above);
				//   receiverDueAmount > 0  — the recipient pays; Econt adds its
				//                            courier fee to the collection by
				//                            itself, so folding our shipping in
				//                            as well collects it TWICE.
				// The 14.08 fix was written against a shop-pays store and did
				// the second thing to every recipient-pays store: si-brand.eu,
				// 22.09.2026 — 29.99 goods + 5.11 shipping sent as a 35.10 COD,
				// Econt added 5.28 on top, the customer was asked for 40.38.
				// So the payer is asked from Econt at waybill time with the
				// goods-only payload, before the "Доставка" line is decided.

				// This parcel's store key — needed by the payer lookup below and
				// by updateOrder/createAWB after it.
				$g_auth = ( null !== $g_ids && $method )
					? $method->pickup_private_key( (string) $g_key )
					: $private_key;

				$cod_note = '';
				if ( ! $is_split || 1 === $parcel_no ) {
					$items_sum = 0.0;
					foreach ( $g_payload['items'] as $g_item ) {
						$items_sum += (float) $g_item['totalPrice'];
					}
					$shipping_paid = round(
						(float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2 );
					$delta = round( (float) $order->get_total() - $items_sum, 2 );
					if ( $is_split ) {
						// under split, compare against the WHOLE order total is
						// wrong — only shipping+rounding belongs here.
						$delta = $shipping_paid;
					}

					$receiver_pays = $cod
						? self::receiver_pays( $base_url, $g_auth, $g_payload, $order )
						: null;
					if ( true === $receiver_pays && $shipping_paid > 0.009 ) {
						$delta = round( $delta - $shipping_paid, 2 );
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

					// Say on the order what the COD contains and why, so a
					// merchant can see the payer decision before the parcel
					// leaves — this bug ran for five weeks unseen.
					if ( $cod ) {
						$money      = static fn( float $v ): string => html_entity_decode( wp_strip_all_tags( wc_price( $v, [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
						$cod_amount = $money( $items_sum + max( 0.0, $delta ) );
						$ship_fmt   = $money( $shipping_paid );
						if ( true === $receiver_pays && $shipping_paid > 0.009 ) {
							/* translators: 1: COD amount, 2: shipping charged at checkout */
							$cod_note = sprintf( __( 'Cash on delivery %1$s: goods only. Your Econt store bills the courier fee to the recipient, so the %2$s shipping charged at checkout is not collected again.', 'drusoft-shipping-for-econt' ), $cod_amount, $ship_fmt );
						} elseif ( true === $receiver_pays ) {
							/* translators: 1: COD amount */
							$cod_note = sprintf( __( 'Cash on delivery %1$s: goods only. Your Econt store bills the courier fee to the recipient.', 'drusoft-shipping-for-econt' ), $cod_amount );
						} elseif ( false === $receiver_pays ) {
							/* translators: 1: COD amount, 2: shipping charged at checkout */
							$cod_note = sprintf( __( 'Cash on delivery %1$s, including the %2$s shipping charged at checkout. Your Econt store bills the courier fee to the shop.', 'drusoft-shipping-for-econt' ), $cod_amount, $ship_fmt );
						} else {
							/* translators: 1: COD amount */
							$cod_note = sprintf( __( 'Cash on delivery %1$s. Econt did not say who pays the courier fee, so it is treated as paid by the shop — if your Econt store bills it to the recipient, check the amount on the waybill.', 'drusoft-shipping-for-econt' ), $cod_amount );
						}
					}
				}

				// Optionally print the product sizes into the description — the
				// only place Достави с Еконт lets them reach the waybill and the
				// office (its Order object has no dimension fields). Cosmetic:
				// Econt never prices this text. See Drushfe_Dimensions.
				$g_payload['shipmentDescription'] = ( 'yes' === ( $settings['dims_in_description'] ?? 'no' ) && class_exists( 'Drushfe_Dimensions' ) )
					? Drushfe_Dimensions::describe( implode( ', ', $items_desc ), $g_products )
					: mb_substr( implode( ', ', $items_desc ), 0, 100 );
				if ( $is_split ) {
					// each store needs its own unique order number
					$g_payload['orderNumber'] = $order_id . '-' . $parcel_no;
				}

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
					$order->add_order_note( __( 'Econt Waybill Created: ', 'drusoft-shipping-for-econt' ) . $waybill_id . ( '' !== $cod_note ? "\n" . $cod_note : '' ) );
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
		 * Does the recipient pay the courier fee on this shipment?
		 *
		 * Asks Достави с Еконт to price the goods-only payload: a non-zero
		 * receiverDueAmount means the store profile bills the recipient, in
		 * which case Econt adds the fee to the collection itself. Falls back
		 * to the answer the same call gave at checkout (saved on the order),
		 * and to null when neither is available — the caller then keeps the
		 * shop-pays behaviour and says so on the order.
		 *
		 * @param string   $base_url API base (live or demo).
		 * @param string   $auth     Store private key for this parcel.
		 * @param array    $payload  Order payload WITHOUT the "Доставка" line.
		 * @param WC_Order $order    The order, for the checkout-time fallback.
		 * @return bool|null
		 */
		public static function receiver_pays( string $base_url, string $auth, array $payload, WC_Order $order ): ?bool {
			$response = wp_remote_post(
				$base_url . 'services/OrdersService.getPrice.json',
				[
					'headers' => [
						'Content-Type'  => 'application/json',
						'Authorization' => $auth,
					],
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				]
			);
			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $body ) && empty( $body['type'] ) && isset( $body['receiverDueAmount'] ) ) {
					return (float) $body['receiverDueAmount'] > 0.009;
				}
			}

			$quoted = $order->get_meta( '_drushfe_receiver_due' );
			if ( '' !== $quoted && null !== $quoted ) {
				return (float) $quoted > 0.009;
			}
			return null;
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
