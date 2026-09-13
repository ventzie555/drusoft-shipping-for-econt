<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Drushfe_Dimensions' ) ) {

	/**
	 * Product dimensions: where they matter to Econt, and where they do not.
	 *
	 * The plugin ships through "Достави с Еконт" (OrdersService on
	 * delivery.econt.com). That API's Order object carries a weight and a free-
	 * text description and has NO dimension fields at all — so WooCommerce's
	 * length/width/height never reached Econt, and a merchant asked why the
	 * office could not see them (12.09.2026).
	 *
	 * Measured against Econt's live pricing (LabelService.createLabel in
	 * `calculate` mode, ~30 calls, 12–13.09.2026): a standard parcel is priced
	 * by WEIGHT ALONE — 20×15×5 and 90×90×90 cm at the same weight cost the same
	 * — and size only starts to matter once a side reaches ~100 cm, when the
	 * parcel flips to a cargo tariff (0.7 kg: 4.79 € in a 90×90×90 box, 30.94 €
	 * in a 100×100×100 one). The `sizeUnder60cm` flag and per-pack `packs[]`
	 * dimensions changed nothing; only the top-level shipmentDimensionsL/W/H did.
	 *
	 * Hence two small, opt-in things, both default OFF:
	 *   1. dims_in_description — print "Д×Ш×В см" into the shipment description
	 *      so the office and the waybill show the size (cosmetic, never priced).
	 *   2. oversize_quote — when any side is ≥ OVERSIZE_CM, ask the label API
	 *      (same connect key — it is accepted there, verified) for the price WITH
	 *      dimensions and raise the customer's share proportionally. Anything
	 *      under the threshold is untouched, and any failure means "no change".
	 */
	class Drushfe_Dimensions {

		/** Side length (cm) from which Econt prices by size instead of weight. */
		const OVERSIZE_CM = 100.0;

		/**
		 * Convert a WooCommerce dimension value to centimetres.
		 *
		 * WooCommerce stores raw numbers in the unit chosen under
		 * WooCommerce → Settings → Products (m, cm, mm, in, yd).
		 */
		public static function to_cm( $value ): float {
			$v = (float) $value;
			if ( $v <= 0 ) {
				return 0.0;
			}
			switch ( get_option( 'woocommerce_dimension_unit', 'cm' ) ) {
				case 'm':
					return $v * 100;
				case 'mm':
					return $v / 10;
				case 'in':
					return $v * 2.54;
				case 'yd':
					return $v * 91.44;
				default:
					return $v;
			}
		}

		/**
		 * [L, W, H] in cm for a product, or null when any side is missing.
		 * Variations without their own dimensions inherit the parent's — that
		 * is WooCommerce's own behaviour in get_length() & co.
		 */
		public static function product_dims_cm( $product ): ?array {
			if ( ! $product instanceof WC_Product ) {
				return null;
			}
			$dims = [
				self::to_cm( $product->get_length() ),
				self::to_cm( $product->get_width() ),
				self::to_cm( $product->get_height() ),
			];
			foreach ( $dims as $d ) {
				if ( $d <= 0 ) {
					return null;
				}
			}
			return $dims;
		}

		/**
		 * Longest side (cm) across a set of products; 0 when none has dimensions.
		 *
		 * @param WC_Product[] $products
		 */
		public static function max_side_cm( array $products ): float {
			$max = 0.0;
			foreach ( $products as $p ) {
				$d = self::product_dims_cm( $p );
				if ( $d ) {
					$max = max( $max, max( $d ) );
				}
			}
			return $max;
		}

		/**
		 * Bounding box [L, W, H] in cm for a group of products — the largest
		 * value on each axis. For the single-item parcel this is exact; for a
		 * mixed basket it is the box that would hold the biggest item, which is
		 * the conservative choice for a price check.
		 *
		 * @param WC_Product[] $products
		 */
		public static function bounding_box_cm( array $products ): ?array {
			$box = [ 0.0, 0.0, 0.0 ];
			$any = false;
			foreach ( $products as $p ) {
				$d = self::product_dims_cm( $p );
				if ( ! $d ) {
					continue;
				}
				$any = true;
				rsort( $d );
				for ( $i = 0; $i < 3; $i++ ) {
					$box[ $i ] = max( $box[ $i ], $d[ $i ] );
				}
			}
			return $any ? $box : null;
		}

		/**
		 * "25x17x2 см" per distinct product, comma-joined — the text the
		 * office reads. Whole centimetres are enough for a label.
		 *
		 * Plain ASCII "x" on purpose: the first live test (13.09.2026) used the
		 * typographic "×" (U+00D7) and Econt's waybill PDF printed the contents
		 * line BLANK — the character has no Windows-1251 code point, and the
		 * whole line went with it. Cyrillic and ASCII print fine.
		 *
		 * @param WC_Product[] $products
		 */
		public static function dims_text( array $products ): string {
			$parts = [];
			foreach ( $products as $p ) {
				$d = self::product_dims_cm( $p );
				if ( ! $d ) {
					continue;
				}
				$parts[] = sprintf( '%dx%dx%d см', round( $d[0] ), round( $d[1] ), round( $d[2] ) );
			}
			return implode( ', ', array_unique( $parts ) );
		}

		/**
		 * Append the dimensions to a shipment description without letting the
		 * total exceed Econt's 100-character limit — the size is the part the
		 * office needs, so it is the product names that get truncated, not it.
		 *
		 * @param string       $description Item names, comma-joined.
		 * @param WC_Product[] $products
		 */
		public static function describe( string $description, array $products, int $limit = 100 ): string {
			$dims = self::dims_text( $products );
			if ( '' === $dims ) {
				return mb_substr( $description, 0, $limit );
			}
			$suffix = ' - ' . $dims; // ASCII hyphen, see dims_text()
			$room   = $limit - mb_strlen( $suffix );
			if ( $room < 10 ) {
				// A basket so varied that even the sizes do not fit — keep the
				// names, the label is no place for a list of boxes.
				return mb_substr( $description, 0, $limit );
			}
			return mb_substr( $description, 0, $room ) . $suffix;
		}

		/**
		 * How much MORE the customer should pay for an oversize parcel, in the
		 * shop currency. 0.0 in every case where nothing is certain:
		 * setting off, no side ≥ OVERSIZE_CM, sender not configured, Econt did
		 * not answer, or the size-aware price is not higher.
		 *
		 * Who pays is preserved, not decided here: the Достави с Еконт quote
		 * already split the total between sender and receiver, and that split is
		 * scaled — a shop where the merchant pays delivery keeps charging the
		 * customer nothing, a shop where the customer pays sees the full cargo
		 * price.
		 *
		 * @param array        $settings   Instance settings.
		 * @param string       $auth       Connect key used for the getPrice call.
		 * @param array        $quote      Decoded OrdersService.getPrice response.
		 * @param WC_Product[] $products   Products in this parcel.
		 * @param array        $ctx        weight, cod (bool), cod_amount, currency,
		 *                                 customer (the getPrice customerInfo array),
		 *                                 delivery_type, description.
		 */
		public static function oversize_adjustment( array $settings, string $auth, array $quote, array $products, array $ctx ): float {
			try {
				if ( 'yes' !== ( $settings['oversize_quote'] ?? 'no' ) ) {
					return 0.0;
				}
				if ( self::max_side_cm( $products ) < self::OVERSIZE_CM ) {
					return 0.0;
				}
				$total    = (float) ( $quote['totalPrice'] ?? 0 );
				$receiver = (float) ( $quote['receiverDueAmount'] ?? 0 );
				if ( $total <= 0 || $receiver <= 0 ) {
					// Merchant pays, or Econt gave no usable split: the customer's
					// price cannot be scaled from nothing, so leave it alone.
					return 0.0;
				}
				$sized = self::quote_total( $settings, $auth, $products, $ctx );
				if ( null === $sized || $sized <= $total ) {
					return 0.0;
				}
				return round( $receiver * ( $sized / $total - 1 ), 2 );
			} catch ( \Throwable $e ) {
				return 0.0;
			}
		}

		/**
		 * Econt's total price for this parcel WITH its dimensions, via
		 * LabelService.createLabel in `calculate` mode — nothing is created.
		 * The Достави с Еконт connect key is accepted by that service (verified
		 * 12.09.2026); it only insists on a sender address or office, which the
		 * plugin settings already hold.
		 *
		 * @return float|null totalPrice, or null when it cannot be determined.
		 */
		public static function quote_total( array $settings, string $auth, array $products, array $ctx ): ?float {
			$box = self::bounding_box_cm( $products );
			if ( ! $box || '' === $auth ) {
				return null;
			}

			$label = [
				'senderClient'        => [
					'name'   => (string) ( $settings['sender_name'] ?? '' ) ?: get_bloginfo( 'name' ),
					'phones' => ! empty( $settings['sender_phone'] ) ? [ (string) $settings['sender_phone'] ] : [],
				],
				'receiverClient'      => [
					'name'   => 'Customer',
					'phones' => [ '0888888888' ],
				],
				'packCount'           => 1,
				'shipmentType'        => 'pack',
				'weight'              => max( 0.1, (float) ( $ctx['weight'] ?? 0 ) ),
				'shipmentDimensionsL' => $box[0],
				'shipmentDimensionsW' => $box[1],
				'shipmentDimensionsH' => $box[2],
				'shipmentDescription' => mb_substr( (string) ( $ctx['description'] ?? '' ), 0, 100 ),
			];

			// Sender: the same origin the waybill will ship from.
			if ( 'YES' === ( $settings['sender_officeyesno'] ?? 'NO' ) && ! empty( $settings['sender_office'] ) ) {
				$label['senderOfficeCode'] = (string) $settings['sender_office'];
			} else {
				$city = function_exists( 'drushfe_get_city_name_by_id' )
					? (string) drushfe_get_city_name_by_id( (int) ( $settings['sender_city'] ?? 0 ) )
					: '';
				if ( '' === $city || empty( $settings['sender_street'] ) ) {
					return null;
				}
				$label['senderAddress'] = [
					'city'   => [ 'name' => $city, 'country' => [ 'code3' => 'BGR' ] ],
					'street' => (string) $settings['sender_street'],
					'num'    => (string) ( $settings['sender_num'] ?? '' ),
				];
			}

			// Receiver: office code, or the same (joker) street the getPrice call
			// was priced against — the address must validate, and that one does.
			$customer = (array) ( $ctx['customer'] ?? [] );
			$type     = (string) ( $ctx['delivery_type'] ?? 'address' );
			if ( ( 'office' === $type || 'automat' === $type ) && ! empty( $customer['officeCode'] ) ) {
				$label['receiverOfficeCode'] = (string) $customer['officeCode'];
			} elseif ( ! empty( $customer['cityName'] ) && ! empty( $customer['street'] ) ) {
				$label['receiverAddress'] = [
					'city'   => [
						'name'     => (string) $customer['cityName'],
						'postCode' => (string) ( $customer['postCode'] ?? '' ),
						'country'  => [ 'code3' => 'BGR' ],
					],
					'street' => (string) $customer['street'],
					'num'    => (string) ( $customer['num'] ?? '1' ),
				];
			} else {
				return null;
			}

			if ( ! empty( $ctx['cod'] ) && (float) ( $ctx['cod_amount'] ?? 0 ) > 0 ) {
				$label['services'] = [
					'cdAmount'   => round( (float) $ctx['cod_amount'], 2 ),
					'cdCurrency' => (string) ( $ctx['currency'] ?? get_woocommerce_currency() ),
					'cdType'     => 'get',
				];
			}

			$is_demo = 'yes' === ( $settings['econt_test_mode'] ?? 'no' );
			$base    = $is_demo ? 'https://demo.econt.com/ee/' : 'https://ee.econt.com/';

			$response = wp_remote_post(
				$base . 'services/Shipments/LabelService.createLabel.json',
				[
					'headers' => [
						'Content-Type'  => 'application/json',
						'Authorization' => $auth,
					],
					'body'    => wp_json_encode( [ 'label' => $label, 'mode' => 'calculate' ] ),
					'timeout' => 15,
				]
			);
			if ( is_wp_error( $response ) ) {
				return null;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || ! empty( $body['type'] ) || ! isset( $body['label']['totalPrice'] ) ) {
				return null;
			}
			$total = (float) $body['label']['totalPrice'];
			return $total > 0 ? $total : null;
		}
	}
}
