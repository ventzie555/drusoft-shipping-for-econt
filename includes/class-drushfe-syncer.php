<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles background synchronization of Econt locations (Cities and Offices).
 *
 * Calls Econt's public Nomenclatures API on ee.econt.com (or demo.econt.com/ee/
 * when demo mode is enabled). No credentials are required for these endpoints —
 * the call is gated by the private_key only to mirror how the Speedy syncer
 * behaved (don't sync until the store has activated the integration).
 */
class Drushfe_Syncer {

	/**
	 * Main entry point for the background job.
	 */
	public static function sync() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$settings = self::get_first_active_settings();
		if ( empty( $settings['econt_private_key'] ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( __( 'Econt Sync Failed: Missing private key.', 'drusoft-shipping-for-econt' ), [ 'source' => 'drusoft-shipping-for-econt' ] );
			}
			return;
		}

		$base_url = self::get_nomenclatures_base_url( $settings );

		self::update_cities( $base_url );
		self::update_offices( $base_url );
	}

	/**
	 * Locate the first WC shipping method instance with credentials, falling
	 * back to the global settings option.
	 */
	private static function get_first_active_settings(): array {
		$settings = get_option( 'woocommerce_drushfe_econt_settings' );
		if ( is_array( $settings ) && ! empty( $settings['econt_private_key'] ) ) {
			return $settings;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				'woocommerce_drushfe_econt_%_settings'
			)
		);

		foreach ( (array) $rows as $row ) {
			$inst = maybe_unserialize( $row->option_value );
			if ( is_array( $inst ) && ! empty( $inst['econt_private_key'] ) ) {
				return $inst;
			}
		}

		return [];
	}

	/**
	 * Pick the demo or production base URL for Econt's nomenclature service.
	 */
	private static function get_nomenclatures_base_url( array $settings ): string {
		$is_demo = isset( $settings['econt_test_mode'] ) && 'yes' === $settings['econt_test_mode'];
		return $is_demo ? 'https://demo.econt.com/ee/' : 'https://ee.econt.com/';
	}

	private static function nomenclatures_request( string $base_url, string $endpoint, array $payload ) {
		$url = rtrim( $base_url, '/' ) . '/' . ltrim( $endpoint, '/' );
		$response = wp_remote_post( $url, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( '[Econt Nomenclatures] ' . $endpoint . ': ' . $response->get_error_message(), [ 'source' => 'drusoft-shipping-for-econt' ] );
			}
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Fetch and store cities for Bulgaria.
	 */
	private static function update_cities( string $base_url ) {
		global $wpdb;

		$data = self::nomenclatures_request(
			$base_url,
			'services/Nomenclatures/NomenclaturesService.getCities.json',
			[ 'countryCode' => 'BGR' ]
		);

		if ( empty( $data['cities'] ) || ! is_array( $data['cities'] ) ) {
			return;
		}

		$table_name = $wpdb->prefix . 'drushfe_cities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}drushfe_cities" );

		$count = 0;
		foreach ( $data['cities'] as $city ) {
			if ( empty( $city['id'] ) ) {
				continue;
			}

			// Econt marks `expressCityDeliveries` to indicate town-class cities (гр.) vs. villages (с.).
			$type = ! empty( $city['expressCityDeliveries'] ) ? 'гр.' : 'с.';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table_name, [
				'id'        => (int) $city['id'],
				'name'      => self::mb_ucfirst( $city['name'] ?? '' ),
				'post_code' => $city['postCode'] ?? '',
				'region'    => self::mb_ucfirst( $city['regionName'] ?? '' ),
				'type'      => $type,
			] );
			$count++;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			wc_get_logger()->info( '[Econt Cities] synced ' . $count . ' rows', [ 'source' => 'drusoft-shipping-for-econt' ] );
		}
	}

	/**
	 * Fetch and store offices for Bulgaria.
	 *
	 * Econt returns offices and APS (automated parcel stations) in the same list
	 * and distinguishes them via `isAPS`. We tag the row in `office_type` so the
	 * existing drushfe_is_automat() helper continues to work without changes.
	 */
	private static function update_offices( string $base_url ) {
		global $wpdb;

		$data = self::nomenclatures_request(
			$base_url,
			'services/Nomenclatures/NomenclaturesService.getOffices.json',
			[ 'countryCode' => 'BGR' ]
		);

		if ( empty( $data['offices'] ) || ! is_array( $data['offices'] ) ) {
			return;
		}

		$table_name = $wpdb->prefix . 'drushfe_offices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}drushfe_offices" );

		$count = 0;
		foreach ( $data['offices'] as $office ) {
			$code = $office['code'] ?? '';
			if ( '' === $code ) {
				continue;
			}

			$address = $office['address'] ?? [];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table_name, [
				'id'              => $code,
				'name'            => $office['name'] ?? '',
				'city_id'         => isset( $address['city']['id'] ) ? (int) $address['city']['id'] : 0,
				'office_type'     => ! empty( $office['isAPS'] ) ? 'APS' : 'OFFICE',
				'city'            => $address['city']['name'] ?? '',
				'address'         => $address['fullAddress'] ?? '',
				'latitude'        => isset( $address['location']['latitude'] ) ? (string) $address['location']['latitude'] : '',
				'longitude'       => isset( $address['location']['longitude'] ) ? (string) $address['location']['longitude'] : '',
				'post_code'       => $address['city']['postCode'] ?? '',
				'address_details' => maybe_serialize( $address ),
				'office_details'  => maybe_serialize( $office ),
				'phone'           => isset( $office['phones'] ) && is_array( $office['phones'] ) ? implode( ', ', $office['phones'] ) : '',
				'email'           => isset( $office['emails'] ) && is_array( $office['emails'] ) ? implode( ', ', $office['emails'] ) : '',
			] );
			$count++;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			wc_get_logger()->info( '[Econt Offices] synced ' . $count . ' rows', [ 'source' => 'drusoft-shipping-for-econt' ] );
		}
	}

	private static function mb_ucfirst( $string, $encoding = 'UTF-8' ) {
		$string = mb_strtolower( $string, $encoding );
		$strlen = mb_strlen( $string, $encoding );
		if ( $strlen <= 0 ) {
			return $string;
		}
		$firstChar = mb_substr( $string, 0, 1, $encoding );
		$rest      = mb_substr( $string, 1, $strlen - 1, $encoding );
		return mb_strtoupper( $firstChar, $encoding ) . $rest;
	}
}
