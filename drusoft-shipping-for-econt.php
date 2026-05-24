<?php
/**
 * Plugin Name: Drusoft Shipping for Econt
 * Plugin URI:  https://github.com/ventzie555/drusoft-shipping-for-econt
 * Description: A clean, conflict-free Econt integration for Bulgaria.
 * Version:     1.0.0
 * Author:      DRUSOFT LTD
 * Author URI:  https://drusoft.dev/
 * Text Domain: drusoft-shipping-for-econt
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @copyright 2026 DRUSOFT LTD.
 * @license GPL-2.0-or-later
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare HPOS Compatibility for WooCommerce 8.0+
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__
		);
	}
} );

/**
 * Guard Clause: Exit if WooCommerce is not active.
 * This keeps the rest of the code clean and un-indented.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter.
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Define Constants
 * Helpful for paths and URLs throughout the plugin.
 */
define( 'DRUSHFE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DRUSHFE_URL',  plugin_dir_url( __FILE__ ) );
define( 'DRUSHFE_VER',  '1.0.0' );

/**
 * Load Dependencies
 */
add_action( 'plugins_loaded', 'drushfe_load_dependencies' );
function drushfe_load_dependencies(): void {
	require_once DRUSHFE_PATH . 'class-drushfe-shipping-method.php';
	require_once DRUSHFE_PATH . 'includes/class-drushfe-syncer.php';
	require_once DRUSHFE_PATH . 'includes/class-drushfe-waybill-generator.php';
	require_once DRUSHFE_PATH . 'includes/admin/class-drushfe-admin-menu.php';
	require_once DRUSHFE_PATH . 'includes/admin/class-drushfe-actions.php';
	require_once DRUSHFE_PATH . 'includes/admin/class-drushfe-order-metabox.php';
}

/**
 * Activation & Deactivation Hooks
 */
register_activation_hook( __FILE__, 'drushfe_activate' );
register_deactivation_hook( __FILE__, 'drushfe_deactivate' );

/**
 * Run on plugin activation.
 *
 * Creates tables and schedules sync.
 *
 * @return void
 */
function drushfe_activate(): void {
	// Create Database Tables
	require_once DRUSHFE_PATH . 'includes/class-drushfe-activator.php';
	Drushfe_Activator::activate();

	// Schedule recurring background sync (every 24 hours via Action Scheduler).
	// This fires regardless of whether individual runs succeed or fail.
	// Check both global settings and per-instance settings for credentials.
	$has_credentials = false;
	$settings = get_option( 'woocommerce_drushfe_econt_settings' );
	if ( ! empty( $settings['econt_private_key'] ) && ! empty( $settings['econt_private_key'] ) ) {
		$has_credentials = true;
	} else {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
				'woocommerce_drushfe_econt_%_settings'
			)
		);
		if ( $rows ) {
			$inst = maybe_unserialize( $rows[0]->option_value );
			if ( is_array( $inst ) && ! empty( $inst['econt_private_key'] ) && ! empty( $inst['econt_private_key'] ) ) {
				$has_credentials = true;
			}
		}
	}
	if ( $has_credentials ) {
		// Run the sync NOW so tables are populated before any page load.
		require_once DRUSHFE_PATH . 'includes/class-drushfe-syncer.php';
		Drushfe_Syncer::sync();

		// Schedule daily recurring refresh starting 24 h from now.
		if ( function_exists( 'as_schedule_recurring_action' ) && ! as_next_scheduled_action( 'drushfe_sync_locations_event' ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'drushfe_sync_locations_event' );
		}
	}
}

/**
 * Run on plugin deactivation.
 *
 * Drops tables and clears scheduled actions.
 *
 * @return void
 */
function drushfe_deactivate(): void {
	// Unschedule all recurring sync events
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'drushfe_sync_locations_event' );
	}

	// Drop Database Tables
	require_once DRUSHFE_PATH . 'includes/class-drushfe-activator.php';
	Drushfe_Activator::deactivate();
}

/**
 * Plugin initialization.
 *
 * @return void
 */
add_action( 'plugins_loaded', 'drushfe_init' );
function drushfe_init(): void {
	// Translations are loaded automatically by WordPress.org for directory-hosted plugins.
}

/**
 * Add Drusoft Shipping for Econt to WooCommerce shipping methods.
 *
 * @param array $methods Existing shipping methods.
 * @return array Updated shipping methods.
 */
add_filter( 'woocommerce_shipping_methods', 'drushfe_register_method' );
function drushfe_register_method( $methods ) {
	$methods['drushfe_econt'] = 'Drushfe_Shipping_Method';
	return $methods;
}

/**
 * Hide the plugin's internal shipping-item meta keys from WC's admin
 * order-items view. WC ignores the leading-underscore convention there
 * and renders every meta key by default, which leaks `_drushfe_*` rows
 * into the customer-facing admin UI.
 *
 * @param string[] $hidden_keys
 * @return string[]
 */
add_filter( 'woocommerce_hidden_order_itemmeta', 'drushfe_hide_internal_item_meta' );
function drushfe_hide_internal_item_meta( array $hidden_keys ): array {
	return array_merge( $hidden_keys, [
		'_drushfe_delivery_type',
		'_drushfe_office_id',
		'missing_address',
	] );
}

/**
 * Check office/automat availability for a given city.
 *
 * @param int $city_id Econt city (site) ID.
 * @return array { @type bool $has_office, @type bool $has_automat }
 */
function drushfe_check_city_availability( int $city_id ): array {
	$has_office  = false;
	$has_automat = false;

	if ( $city_id <= 0 ) {
		return compact( 'has_office', 'has_automat' );
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT name, office_type FROM {$wpdb->prefix}drushfe_offices WHERE city_id = %d",
			$city_id
		)
	);

	foreach ( $rows as $row ) {
		if ( drushfe_is_automat( $row->office_type, $row->name ) ) {
			$has_automat = true;
		} else {
			$has_office = true;
		}
		if ( $has_office && $has_automat ) {
			break;
		}
	}

	return compact( 'has_office', 'has_automat' );
}

/**
 * Determine whether an office row represents an automat (APT/APS).
 *
 * @param string $office_type The office_type column value.
 * @param string $name        The office name.
 * @return bool
 */
function drushfe_is_automat( string $office_type, string $name ): bool {
	return ( stripos( $office_type, 'APT' ) !== false
		|| stripos( $office_type, 'APS' ) !== false
		|| mb_stripos( $name, 'АВТОМАТ' ) !== false
		|| stripos( $name, 'APS' ) !== false
		|| stripos( $name, 'APT' ) !== false );
}

/**
 * Get the display name for a Econt city by ID (e.g. "гр. София").
 *
 * @param int $city_id Econt city (site) ID.
 * @return string City name or empty string if not found.
 */
function drushfe_get_city_name( int $city_id ): string {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$name = $wpdb->get_var( $wpdb->prepare(
		"SELECT CONCAT(type, ' ', name) FROM {$wpdb->prefix}drushfe_cities WHERE id = %d",
		$city_id
	) );
	return $name ?: '';
}

/**
 * Append the selected Econt service ID to the shipping package so the
 * package hash changes whenever the user picks a different service.
 * This forces WooCommerce to re-call calculate_shipping() instead of
 * returning a cached rate.
 */
add_filter( 'woocommerce_cart_shipping_packages', 'drushfe_vary_package_hash' );
function drushfe_vary_package_hash( $packages ) {
	// Extract delivery type and office ID from the current checkout POST data
	// so the package hash changes whenever the user switches delivery type or
	// picks a different office/automat — forcing WC to re-call calculate_shipping.
	$post_data = [];
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called inside WC filter; nonce verified by WooCommerce.
	if ( ! empty( $_POST['post_data'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL-encoded string; individual values sanitized below.
		parse_str( wp_unslash( $_POST['post_data'] ), $post_data );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$merged = array_merge( $post_data, $_POST );

	$delivery_type = sanitize_text_field( $merged['econt_delivery_type'] ?? 'address' );
	$office_id     = absint( $merged['econt_office_id'] ?? 0 );
	$selected      = WC()->session ? WC()->session->get( 'drushfe_selected_service', 0 ) : 0;

	// Determine which address context is active
	$ship_to_different = ! empty( $merged['ship_to_different_address'] );
	$context           = $ship_to_different ? 'shipping' : 'billing';
	$city_id           = absint( $merged[ $context . '_city' ] ?? 0 );

	// Also try the cart calculator city field and our dedicated hidden field
	if ( ! $city_id ) {
		$city_id = absint( $merged['calc_shipping_city'] ?? 0 );
	}
	if ( ! $city_id ) {
		$city_id = absint( $merged['econt_city_id'] ?? 0 );
	}

	// On the cart page, set session data directly from the form submission
	// so calculate_shipping() can read them without a prior AJAX call.
	if ( WC()->session && $city_id > 0 ) {
		$session_city = absint( WC()->session->get( 'drushfe_city_id', 0 ) );
		$session_type = WC()->session->get( 'drushfe_delivery_type', 'address' );

		// Update session if something changed
		if ( $city_id !== $session_city || $delivery_type !== $session_type ) {
			WC()->session->set( 'drushfe_city_id', $city_id );
			WC()->session->set( 'drushfe_delivery_type', $delivery_type );

			// Update customer city
			if ( WC()->customer ) {
				$city_name = drushfe_get_city_name( $city_id );
				WC()->customer->set_shipping_city( $city_name ?: $city_id );
				WC()->customer->set_billing_city( $city_name ?: $city_id );
				WC()->customer->save();
			}

			// Save office_id from POST data (if submitted), otherwise clear it for address delivery.
			if ( $office_id > 0 ) {
				WC()->session->set( 'drushfe_office_id', $office_id );
			} elseif ( $delivery_type === 'address' ) {
				WC()->session->set( 'drushfe_office_id', 0 );
			}
		}

		// Update state from form if present
		$state = sanitize_text_field( $merged['calc_shipping_state'] ?? '' );
		if ( $state ) {
			WC()->session->set( 'drushfe_state', $state );
			if ( WC()->customer ) {
				WC()->customer->set_shipping_state( $state );
				WC()->customer->set_billing_state( $state );
				WC()->customer->save();
			}
		}
	}

	// Include the payment method in the hash so switching COD ↔ card
	// forces shipping recalculation (COD changes courierServicePayer).
	// Read directly from POST data (available during checkout AJAX);
	// on the cart page this is empty, which is fine (defaults to COD).
	$payment_method = sanitize_text_field( $merged['payment_method'] ?? '' );

	foreach ( $packages as &$package ) {
		$package['econt_selected_service'] = $selected;
		$package['econt_delivery_type']    = $delivery_type;
		$package['econt_office_id']        = $office_id;
		$package['econt_city_id']          = $city_id;
		$package['econt_payment_method']   = $payment_method;
	}

	return $packages;
}

/**
 * Clear cached Econt price/session data when checkout switches away from Econt.
 *
 * WooCommerce can reuse cached package rates when only the selected shipping
 * method changes. If the previous Econt calculation stays in the session, the
 * stale Econt price can remain visible after another method is selected.
 */
add_action( 'woocommerce_checkout_update_order_review', 'drushfe_clear_econt_when_unselected', 1 );
function drushfe_clear_econt_when_unselected( $post_data = '' ): void {
	parse_str( wp_unslash( (string) $post_data ), $data );

	$shipping_methods = $data['shipping_method'] ?? [];
	if ( ! is_array( $shipping_methods ) ) {
		$shipping_methods = [ $shipping_methods ];
	}

	$shipping_methods = array_filter( array_map( 'sanitize_text_field', $shipping_methods ) );
	if ( empty( $shipping_methods ) ) {
		return;
	}

	foreach ( $shipping_methods as $method_id ) {
		if ( str_starts_with( $method_id, 'drushfe_econt' ) ) {
			return;
		}
	}

	drushfe_clear_econt_checkout_session();
	if ( ! WC()->session ) {
		return;
	}

	// Force WooCommerce to rebuild package rates without the stale Econt cost.
	$packages = WC()->cart ? WC()->cart->get_shipping_packages() : [];
	foreach ( $packages as $key => $package ) {
		WC()->session->set( 'shipping_for_package_' . $key, false );
	}
}

/**
 * Reset only Econt's calculated price/waybill data, preserving customer address.
 */
function drushfe_clear_econt_checkout_session(): void {
	if ( ! WC()->session ) {
		return;
	}

	$service_options = WC()->session->get( 'drushfe_service_options', [] );
	if ( is_array( $service_options ) ) {
		foreach ( array_keys( $service_options ) as $service_id ) {
			WC()->session->set( 'drushfe_shipping_data_' . absint( $service_id ), null );
		}
	}

	WC()->session->set( 'drushfe_service_options', [] );
	WC()->session->set( 'drushfe_selected_service', 0 );
	WC()->session->set( 'drushfe_shipping_cost', 0 );
	WC()->session->set( 'drushfe_shipping_data', null );
	WC()->session->set( 'drushfe_delivery_type', 'address' );
	WC()->session->set( 'drushfe_office_id', 0 );
}

/**
 * Hide the price in the order review when Econt data is incomplete
 * (e.g. user switched to office but hasn't selected one yet).
 */
add_filter( 'woocommerce_cart_shipping_method_full_label', 'drushfe_hide_incomplete_price', 10, 2 );
function drushfe_hide_incomplete_price( $label, $method ) {
	if (str_starts_with($method->id, 'drushfe_econt')) {
		$meta = $method->get_meta_data();
		if ( ! empty( $meta['missing_address'] ) ) {
			// Return just the method label without the price
			return $method->get_label();
		}
	}
	return $label;
}

/**
 * Enqueue scripts for the checkout page.
 *
 * @return void
 */
add_action( 'wp_enqueue_scripts', 'drushfe_enqueue_scripts' );
function drushfe_enqueue_scripts(): void {
	if ( is_admin() ) {
		return;
	}

	$current_type    = WC()->session ? WC()->session->get( 'drushfe_delivery_type', 'address' ) : 'address';
	$current_city_id = WC()->session ? WC()->session->get( 'drushfe_city_id', 0 ) : 0;
	$current_state   = WC()->session ? WC()->session->get( 'drushfe_state', '' ) : '';
	$current_office  = WC()->session ? WC()->session->get( 'drushfe_office_id', 0 ) : 0;

	if ( WC()->customer ) {
		if ( ! $current_state ) {
			$current_state = WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state();
		}
		if ( ! $current_city_id ) {
			$cust_city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
			if ( is_numeric( $cust_city ) ) {
				$current_city_id = absint( $cust_city );
			}
		}
	}

	$params = array(
		'ajax_url'           => admin_url( 'admin-ajax.php' ),
		'nonce'              => wp_create_nonce( 'drushfe_public' ),
		'method_id'          => 'drushfe_econt',
		'current_type'       => $current_type,
		'current_city_id'    => $current_city_id,
		'current_state'      => $current_state,
		'current_office_id'  => $current_office,
		'currency_symbol'    => get_woocommerce_currency_symbol(),
		'i18n'            => array(
			'to_address'       => __( 'To Address', 'drusoft-shipping-for-econt' ),
			'to_office'        => __( 'To Office', 'drusoft-shipping-for-econt' ),
			'to_automat'       => __( 'To Automat', 'drusoft-shipping-for-econt' ),
			'select_office'    => __( 'Select Office', 'drusoft-shipping-for-econt' ),
			'select_automat'   => __( 'Select Automat', 'drusoft-shipping-for-econt' ),
			'select_from_map'  => __( 'Select from Map', 'drusoft-shipping-for-econt' ),
			'select_city'      => __( 'Select a city...', 'drusoft-shipping-for-econt' ),
			'alert_select_city' => __( 'Please select a city first.', 'drusoft-shipping-for-econt' ),
			'no_results'       => __( 'No results', 'drusoft-shipping-for-econt' ),
			'select_service'   => __( 'Select Service', 'drusoft-shipping-for-econt' ),
			'map_title'         => __( 'Pick an Econt office or automat', 'drusoft-shipping-for-econt' ),
			'map_title_office'  => __( 'Pick an Econt office', 'drusoft-shipping-for-econt' ),
			'map_title_automat' => __( 'Pick an Econt automat', 'drusoft-shipping-for-econt' ),
			'map_hint'          => __( 'Filter by type or search by city/name, click a marker, then choose "Select" in the popup.', 'drusoft-shipping-for-econt' ),
			'map_pick'          => __( 'Select this point', 'drusoft-shipping-for-econt' ),
			'map_error'         => __( 'Map could not be loaded:', 'drusoft-shipping-for-econt' ),
			'map_filter_office'  => __( 'Offices', 'drusoft-shipping-for-econt' ),
			'map_filter_automat' => __( 'Automats', 'drusoft-shipping-for-econt' ),
			'map_filter_both'    => __( 'Both', 'drusoft-shipping-for-econt' ),
			'map_search_placeholder' => __( 'Search by city, office name, address…', 'drusoft-shipping-for-econt' ),
			'map_results_count'      => __( '{n} results', 'drusoft-shipping-for-econt' ),
			'map_search_no_results'  => __( 'No matches', 'drusoft-shipping-for-econt' ),
		)
	);

	// Shared utilities (transliteration, select2 matcher, state sorting).
	// Only registered here — loaded automatically on cart/checkout via dependency.
	wp_register_script(
		'drushfe-common',
		DRUSHFE_URL . 'assets/js/econt-common.js',
		array( 'jquery', 'select2' ),
		DRUSHFE_VER,
		true
	);

	if ( is_checkout() ) {
		// Map module — Leaflet itself is lazy-loaded from CDN on first
		// "Open Map" click, so this file is tiny (~6KB) and safe to ship
		// on every checkout render.
		wp_enqueue_script(
			'drushfe-map',
			DRUSHFE_URL . 'assets/js/map.js',
			// Depends on drushfe-common for EcontModern.transliterate() — used
			// by the in-modal search so Latin input matches Cyrillic content.
			array( 'jquery', 'drushfe-common' ),
			DRUSHFE_VER,
			true
		);

		wp_enqueue_script(
			'drushfe-checkout',
			DRUSHFE_URL . 'assets/js/checkout.js',
			array( 'jquery', 'select2', 'drushfe-common', 'drushfe-map' ),
			DRUSHFE_VER,
			true
		);

		wp_enqueue_style(
			'drushfe-checkout',
			DRUSHFE_URL . 'assets/css/checkout.css',
			array(),
			DRUSHFE_VER
		);

		wp_localize_script( 'drushfe-checkout', 'drushfe_params', $params );
	}

	if ( is_cart() ) {
		// Pre-compute availability so the JS doesn't need an AJAX call on load
		$availability = drushfe_check_city_availability( $current_city_id );
		$params['has_office']  = $availability['has_office'];
		$params['has_automat'] = $availability['has_automat'];

		wp_enqueue_script(
			'drushfe-cart',
			DRUSHFE_URL . 'assets/js/cart.js',
			array( 'jquery', 'select2', 'drushfe-common', 'wc-cart' ),
			DRUSHFE_VER,
			true
		);

		wp_enqueue_style(
			'drushfe-cart',
			DRUSHFE_URL . 'assets/css/cart.css',
			array(),
			DRUSHFE_VER
		);

		wp_localize_script( 'drushfe-cart', 'drushfe_params', $params );
	}
}

/**
 * Add hidden fields to the cart form so delivery_type and city_id get
 * submitted with the standard WC cart update — no separate AJAX needed.
 */
add_action( 'woocommerce_cart_contents', 'drushfe_cart_hidden_fields' );
function drushfe_cart_hidden_fields(): void {
	$delivery_type = WC()->session ? WC()->session->get( 'drushfe_delivery_type', 'address' ) : 'address';
	$city_id       = WC()->session ? absint( WC()->session->get( 'drushfe_city_id', 0 ) ) : 0;
	echo '<input type="hidden" name="econt_delivery_type" id="econt_cart_delivery_type" value="' . esc_attr( $delivery_type ) . '">';
	echo '<input type="hidden" name="econt_city_id" id="econt_cart_city_id" value="' . esc_attr( $city_id ) . '">';
}

/**
 * After each Econt shipping rate is rendered, output a hidden element with
 * availability data so the JS can read it from the DOM after cart updates.
 */
add_action( 'woocommerce_after_shipping_rate', 'drushfe_output_availability_data', 10, 2 );
function drushfe_output_availability_data( $method ): void {
	if (!str_starts_with($method->id, 'drushfe_econt')) {
		return;
	}

	$city_id = WC()->session ? absint( WC()->session->get( 'drushfe_city_id', 0 ) ) : 0;
	if ( $city_id <= 0 ) {
		return;
	}

	$availability = drushfe_check_city_availability( $city_id );

	printf(
		'<span id="econt-availability-data" data-has-office="%s" data-has-automat="%s" style="display:none;"></span>',
		esc_attr( $availability['has_office'] ? '1' : '0' ),
		esc_attr( $availability['has_automat'] ? '1' : '0' )
	);
}

/**
 * Enqueue admin scripts for the WooCommerce shipping zones page.
 *
 * Loads a script that auto-reopens the settings modal after saving
 * credentials for the first time, so the user sees the unlocked fields.
 *
 * @return void
 */
add_action( 'admin_enqueue_scripts', 'drushfe_enqueue_admin_scripts' );
function drushfe_enqueue_admin_scripts( $hook ): void {
	// Only load on the WooCommerce shipping settings page
	if ( 'woocommerce_page_wc-settings' !== $hook ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	if ( 'shipping' !== $tab ) {
		return;
	}

	// Determine if credentials are already saved (for any instance)
	// We check the global option key that WooCommerce uses for instance settings
	$credentials = drushfe_get_first_credentials();
	$has_credentials = ! empty( $credentials );

	wp_enqueue_script(
		'drushfe-admin-shipping',
		DRUSHFE_URL . 'assets/js/admin-shipping-zone.js',
		array( 'jquery' ),
		DRUSHFE_VER,
		true
	);

	wp_localize_script( 'drushfe-admin-shipping', 'drushfe_admin', array(
		'has_credentials'          => $has_credentials ? '1' : '0',
		'nonce'                    => wp_create_nonce( 'drushfe_admin' ),
		'i18n_correct_credentials' => __( 'Please correct your credentials and save again.', 'drusoft-shipping-for-econt' ),
	) );

	// Enqueue the settings script for dynamic field visibility
	wp_enqueue_style( 'drushfe-admin-settings', DRUSHFE_URL . 'assets/css/admin-settings.css', array(), DRUSHFE_VER );
	wp_enqueue_script(
		'drushfe-admin-settings',
		DRUSHFE_URL . 'assets/js/admin-settings.js',
		array( 'jquery', 'select2' ),
		DRUSHFE_VER,
		true
	);
}

/**
 * Background Job Listeners
 * This connects the scheduled event to the actual logic.
 */
add_action( 'drushfe_sync_locations_event', array( 'Drushfe_Syncer', 'sync' ) );

/**
 * Get city name by its ID from our local database.
 *
 * @param int $city_id The Econt city ID.
 *
 * @return int|string The city name or an empty string if not found.
 */
function drushfe_get_city_name_by_id( int $city_id ): int|string {
	if ( ! $city_id ) {
		return '';
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$city_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}drushfe_cities WHERE id = %d",
			$city_id
		)
	);

	// Fallback: If name is not found (e.g. sync hasn't run), return the ID so the field isn't blank.
	return $city_name ?: $city_id;
}

/**
 * Get office label by its ID from our local database.
 *
 * @param int $office_id The Econt office ID.
 *
 * @return int|string The office label (Name - Address) or ID if not found.
 */
function drushfe_get_office_label_by_id( int $office_id ): int|string {
	if ( ! $office_id ) {
		return '';
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$office = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT name, address FROM {$wpdb->prefix}drushfe_offices WHERE id = %d",
			$office_id
		)
	);

	if ( $office ) {
		return sprintf( '%s %s - %s', $office_id, $office->name, $office->address );
	}

	return $office_id;
}

/**
 * AJAX Handler for searching cities via the local sync table.
 * Used by Select2 in admin settings.
 *
 * Previously hit api.econt.bg/v1/location/site (Speedy-shaped, userName+
 * password) which doesn't exist on Econt's API. The wp_drushfe_cities table
 * is populated by Drushfe_Syncer and is authoritative — no live API needed.
 */
add_action( 'wp_ajax_drushfe_search_cities', 'drushfe_search_cities' );
function drushfe_search_cities(): void {
	check_ajax_referer( 'drushfe_admin', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
	}

	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	if ( empty( $term ) ) {
		wp_send_json_success( [] );
	}

	global $wpdb;
	$like = '%' . $wpdb->esc_like( $term ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, region, post_code FROM {$wpdb->prefix}drushfe_cities WHERE name LIKE %s ORDER BY name ASC LIMIT 50",
			$like
		)
	);

	$results = [];
	if ( $rows ) {
		foreach ( $rows as $row ) {
			$label = $row->name;
			if ( ! empty( $row->region ) ) {
				$label .= ', ' . $row->region;
			}
			if ( ! empty( $row->post_code ) ) {
				$label .= ' (' . $row->post_code . ')';
			}
			$results[] = [ 'id' => (int) $row->id, 'text' => $label ];
		}
	}

	wp_send_json( [ 'results' => $results ] );
}

/**
 * AJAX Handler for searching offices via local DB with API fallback.
 * Used by Select2 in admin settings.
 */
add_action( 'wp_ajax_drushfe_search_offices', 'drushfe_search_offices' );
function drushfe_search_offices(): void {
	check_ajax_referer( 'drushfe_admin', 'nonce' );

	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
	}

	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	if ( empty( $term ) ) {
		wp_send_json_success( [] );
	}

	// Use the static method from the shipping class which handles DB check + API fallback
	if ( class_exists( 'Drushfe_Shipping_Method' ) ) {
		$exclude_automats = isset( $_GET['exclude_automats'] ) && '1' === $_GET['exclude_automats'];
		$offices = Drushfe_Shipping_Method::get_econt_offices( null, null, $term, $exclude_automats );
		
		$results = [];
		if ( ! empty( $offices ) ) {
			foreach ( $offices as $id => $label ) {
				// Skip the default placeholder if present
				if ( $id == 0 ) continue;
				
				$results[] = [
					'id'   => $id,
					'text' => $label
				];
			}
		}
		
		wp_send_json( [ 'results' => $results ] );
	} else {
		wp_send_json_error( __( 'Shipping method class not found.', 'drusoft-shipping-for-econt' ) );
	}
}

/**
 * Helper: Retrieve the first available Econt API credentials from settings.
 *
 * @return array|null Full instance settings array (incl. econt_private_key, econt_test_mode,
 *                    sender_*, teglo, etc.), or null if no instance has a saved key.
 */
function drushfe_get_first_credentials(): ?array {
	global $wpdb;
	$option_like = 'woocommerce_drushfe_econt_%_settings';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_value FROM $wpdb->options WHERE option_name LIKE %s LIMIT 10",
			$option_like
		)
	);

	foreach ( (array) $rows as $row ) {
		$settings = maybe_unserialize( $row->option_value );
		if ( is_array( $settings ) && ! empty( $settings['econt_private_key'] ) ) {
			return $settings;
		}
	}

	return null;
}

/**
 * Helper: Transliterate Latin to Cyrillic (Bulgarian standard)
 */
/*
 function drushfe_transliterate_latin_to_cyrillic( $text ): string {
	$map = [
		'A' => 'А', 'B' => 'Б', 'V' => 'В', 'G' => 'Г', 'D' => 'Д', 'E' => 'Е', 'Z' => 'З', 'I' => 'И', 'J' => 'Й', 'K' => 'К', 'L' => 'Л', 'M' => 'М', 'N' => 'Н', 'O' => 'О', 'P' => 'П', 'R' => 'Р', 'S' => 'С', 'T' => 'Т', 'U' => 'У', 'F' => 'Ф', 'H' => 'Х', 'C' => 'Ц',
		'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д', 'e' => 'е', 'z' => 'з', 'i' => 'и', 'j' => 'й', 'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у', 'f' => 'ф', 'h' => 'х', 'c' => 'ц',
		// Multi-character mappings (order matters!)
		'Sht' => 'Щ', 'sht' => 'щ', 'Sh' => 'Ш', 'sh' => 'ш', 'Ch' => 'Ч', 'ch' => 'ч', 'Yu' => 'Ю', 'yu' => 'ю', 'Ya' => 'Я', 'ya' => 'я', 'Zh' => 'Ж', 'zh' => 'ж', 'Ts' => 'Ц', 'ts' => 'ц',
		'Y' => 'Й', 'y' => 'й', 'X' => 'Х', 'x' => 'х', 'W' => 'В', 'w' => 'в', 'Q' => 'Я', 'q'=> 'я'
	];

	return strtr( $text, $map );
}
*/

/**
 * Helper: Get Region Map (WC Code => Econt Name)
 */
/**
 * Map WooCommerce BG state codes (ISO 3166-2:BG) → Econt regionName values
 * as they appear in our `drushfe_cities` table (populated from Econt's
 * Nomenclatures.getCities response). Names must match Econt's casing/format
 * exactly because get_cities does an `=` lookup.
 */
function drushfe_get_region_map(): array {
	return [
		'BG-01' => 'Благоевград',
		'BG-02' => 'Бургас',
		'BG-03' => 'Варна',
		'BG-04' => 'Велико търново',
		'BG-05' => 'Видин',
		'BG-06' => 'Враца',
		'BG-07' => 'Габрово',
		'BG-08' => 'Добрич',
		'BG-09' => 'Кърджали',
		'BG-10' => 'Кюстендил',
		'BG-11' => 'Ловеч',
		'BG-12' => 'Монтана',
		'BG-13' => 'Пазарджик',
		'BG-14' => 'Перник',
		'BG-15' => 'Плевен',
		'BG-16' => 'Пловдив',
		'BG-17' => 'Разград',
		'BG-18' => 'Русе',
		'BG-19' => 'Силистра',
		'BG-20' => 'Сливен',
		'BG-21' => 'Смолян',
		'BG-22' => 'София',         // Sofia City
		'BG-23' => 'София област',   // Sofia Province
		'BG-24' => 'Стара загора',
		'BG-25' => 'Търговище',
		'BG-26' => 'Хасково',
		'BG-27' => 'Шумен',
		'BG-28' => 'Ямбол',
	];
}

/**
 * AJAX Handler: Save cart Econt selections to WC session.
 * Called from cart.js whenever the user changes state, city, delivery type, or office.
 * This ensures the data persists to the checkout page even without clicking "Update Cart".
 */
add_action( 'wp_ajax_drushfe_save_cart_selection', 'drushfe_save_cart_selection_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_save_cart_selection', 'drushfe_save_cart_selection_ajax' );

function drushfe_save_cart_selection_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	if ( ! WC()->session ) {
		wp_send_json_error( 'No session' );
	}

	$state         = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
	$city_id       = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
	$delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_type'] ) ) : 'address';
	$office_id     = isset( $_POST['office_id'] ) ? absint( $_POST['office_id'] ) : 0;

	if ( $state ) {
		WC()->session->set( 'drushfe_state', $state );
	}
	if ( $city_id ) {
		WC()->session->set( 'drushfe_city_id', $city_id );
	}
	if ( $delivery_type ) {
		WC()->session->set( 'drushfe_delivery_type', $delivery_type );
	}
	WC()->session->set( 'drushfe_office_id', $office_id );

	// Also update the WC customer so checkout form fields are pre-filled
	if ( WC()->customer ) {
		if ( $state ) {
			WC()->customer->set_shipping_state( $state );
			WC()->customer->set_billing_state( $state );
		}
		if ( $city_id ) {
			$city_name = drushfe_get_city_name( $city_id );
			WC()->customer->set_shipping_city( $city_name ?: $city_id );
			WC()->customer->set_billing_city( $city_name ?: $city_id );
		}
		WC()->customer->save();
	}

	wp_send_json_success();
}

/**
 * AJAX Handler: Get cities for a specific region.
 * Used by checkout.js
 */
add_action( 'wp_ajax_drushfe_get_cities', 'drushfe_get_cities_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_get_cities', 'drushfe_get_cities_ajax' );

function drushfe_get_cities_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$region_code = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';
	
	if ( empty( $region_code ) ) {
		wp_send_json_error( __( 'Missing region code', 'drusoft-shipping-for-econt' ) );
	}

	global $wpdb;

	// Use helper function for mapping
	$region_map = drushfe_get_region_map();
	$region_name = $region_map[ $region_code ] ?? '';

	if ( empty( $region_name ) ) {
		wp_send_json_error( __( 'Unknown region code', 'drusoft-shipping-for-econt' ) );
	}

	// Exact match — region_map values mirror Econt's canonical regionName format,
	// so a single `=` lookup is precise for every region (incl. Sofia City vs Province).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$cities = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, post_code, type FROM {$wpdb->prefix}drushfe_cities WHERE region = %s ORDER BY CASE WHEN type = 'гр.' THEN 1 ELSE 2 END, name ASC",
			$region_name
		)
	);

	$data = [];
	foreach ( $cities as $city ) {
		$data[] = [
			'id'       => $city->id,
			'name'     => $city->type . ' ' . $city->name, // Prepend type
			'postcode' => $city->post_code
		];
	}

	wp_send_json_success( $data );
}

/**
 * AJAX Handler: Check availability of offices/automats in a city.
 * Used by checkout.js
 */
add_action( 'wp_ajax_drushfe_check_availability', 'drushfe_check_availability_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_check_availability', 'drushfe_check_availability_ajax' );

function drushfe_check_availability_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

	if ( ! $city_id ) {
		wp_send_json_error( __( 'Missing city ID', 'drusoft-shipping-for-econt' ) );
	}

	global $wpdb;

	// Fetch all offices/automats for this city. We also pull lat/lng + address so
	// the front-end can plot them on a Leaflet map without an extra round-trip.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, address, office_type, latitude, longitude FROM {$wpdb->prefix}drushfe_offices WHERE city_id = %d ORDER BY name ASC",
			$city_id
		)
	);

	$offices = [];
	$automats = [];

	foreach ( $results as $row ) {
		$lat = (float) $row->latitude;
		$lng = (float) $row->longitude;
		$item = [
			'id'      => $row->id,
			'label'   => sprintf( '%s %s - %s', $row->id, $row->name, $row->address ),
			'name'    => $row->name,
			'address' => $row->address,
			// 0/0 lat/lng means missing — let the JS decide whether to show on map.
			'lat'     => $lat,
			'lng'     => $lng,
		];

		if ( drushfe_is_automat( $row->office_type, $row->name ) ) {
			$automats[] = $item;
		} else {
			$offices[] = $item;
		}
	}

	wp_send_json_success( [
		'has_office'  => ! empty( $offices ),
		'has_automat' => ! empty( $automats ),
		'offices'     => $offices,
		'automats'    => $automats
	] );
}

/**
 * AJAX Handler: Return ALL Econt offices + automats with lat/lng + region code.
 *
 * Used by the office-map modal when the user opens the map without having
 * selected a city yet (or wants to pick an office in another city). The
 * response is identical for every visitor, so we cache it in a 24h transient.
 *
 * Each row includes the city's `region_code` (BG-XX) so JS can switch the WC
 * state select to the right value when the user picks a marker in another city.
 */
add_action( 'wp_ajax_drushfe_get_all_offices', 'drushfe_get_all_offices_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_get_all_offices', 'drushfe_get_all_offices_ajax' );

function drushfe_get_all_offices_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$cached = get_transient( 'drushfe_all_offices' );
	if ( false !== $cached ) {
		wp_send_json_success( $cached );
	}

	global $wpdb;

	// Reverse region map so we can attach BG-XX codes to each row.
	$region_map     = drushfe_get_region_map();
	$reverse_region = array_flip( $region_map );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		"SELECT o.id, o.name, o.address, o.office_type, o.latitude, o.longitude,
		        o.city_id, c.name AS city_name, c.region, c.post_code, c.type AS city_type
		   FROM {$wpdb->prefix}drushfe_offices o
		   JOIN {$wpdb->prefix}drushfe_cities c ON c.id = o.city_id
		  WHERE o.latitude IS NOT NULL AND o.longitude IS NOT NULL
		  ORDER BY c.name ASC, o.name ASC"
	);

	$payload = [];
	foreach ( (array) $rows as $row ) {
		$lat = (float) $row->latitude;
		$lng = (float) $row->longitude;
		if ( $lat === 0.0 && $lng === 0.0 ) {
			continue;
		}
		// Map Econt's regionName → BG-XX ISO code (fuzzy match for partial names).
		$region_code = $reverse_region[ $row->region ] ?? '';
		if ( ! $region_code ) {
			foreach ( $reverse_region as $name => $code ) {
				if ( mb_stripos( $row->region, $name ) !== false ) {
					$region_code = $code;
					break;
				}
			}
		}

		$payload[] = [
			'id'          => $row->id,
			'name'        => $row->name,
			'address'     => $row->address,
			'office_type' => $row->office_type,  // 'APS' or 'OFFICE' — JS picks the delivery-type radio from this
			'lat'         => $lat,
			'lng'         => $lng,
			'city_id'     => (int) $row->city_id,
			'city_name'   => trim( ( $row->city_type ?: '' ) . ' ' . $row->city_name ),
			'postcode'    => $row->post_code,
			'region_code' => $region_code,
		];
	}

	set_transient( 'drushfe_all_offices', $payload, DAY_IN_SECONDS );
	wp_send_json_success( $payload );
}

/**
 * AJAX Handler: Get region code by city ID.
 * Used when selecting an office from the map in a different city.
 */
add_action( 'wp_ajax_drushfe_get_region_by_city', 'drushfe_get_region_by_city_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_get_region_by_city', 'drushfe_get_region_by_city_ajax' );

function drushfe_get_region_by_city_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

	if ( ! $city_id ) {
		wp_send_json_error( __( 'Missing city ID', 'drusoft-shipping-for-econt' ) );
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$region_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT region FROM {$wpdb->prefix}drushfe_cities WHERE id = %d",
			$city_id
		)
	);

	if ( ! $region_name ) {
		wp_send_json_error( __( 'City not found', 'drusoft-shipping-for-econt' ) );
	}

	// Use helper function and flip it for reverse mapping
	$region_map = drushfe_get_region_map();
	$reverse_map = array_flip( $region_map );

	// Handle fuzzy matching if exact match fails (e.g. "Област София" vs "София")
	$region_code = $reverse_map[ $region_name ] ?? '';

	if ( ! $region_code ) {
		// Try to find partial match
		foreach ( $reverse_map as $name => $code ) {
			if ( mb_stripos( $region_name, $name ) !== false ) {
				$region_code = $code;
				break;
			}
		}
	}

	if ( $region_code ) {
		wp_send_json_success( [ 'region' => $region_code ] );
	} else {
		wp_send_json_error( __( 'Region mapping not found for: ', 'drusoft-shipping-for-econt' ) . esc_html( $region_name ) );
	}
}

/**
 * Validate Checkout Fields
 * Ensures an office is selected if the user chose "To Office" or "To Automat".
 */
add_action( 'woocommerce_checkout_process', 'drushfe_validate_checkout' );

/**
 * Fetch (or cache) the streets nomenclature for a given Econt city ID.
 * Returns the raw `streets[]` array from Econt's getStreets endpoint, cached
 * for 24h in a `drushfe_streets_<cityID>` transient. Public — used by both
 * the street-autocomplete handler and the joker-street fallback in
 * drushfe_calculate_price_ajax.
 *
 * @param int    $city_id  Econt city ID.
 * @param string $base_url Econt nomenclatures host (incl. trailing slash) —
 *                         ee.econt.com/ or demo.econt.com/ee/.
 * @return array|null
 */
function drushfe_fetch_streets_for_city( int $city_id, string $base_url ): ?array {
	if ( $city_id <= 0 ) {
		return null;
	}
	$transient_key = 'drushfe_streets_' . $city_id;
	$streets       = get_transient( $transient_key );
	if ( false !== $streets ) {
		return $streets;
	}

	$response = wp_remote_post(
		rtrim( $base_url, '/' ) . '/services/Nomenclatures/NomenclaturesService.getStreets.json',
		[
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'cityID' => $city_id ] ),
			'timeout' => 15,
		]
	);
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$body    = json_decode( wp_remote_retrieve_body( $response ), true );
	$streets = ( ! empty( $body['streets'] ) && is_array( $body['streets'] ) ) ? $body['streets'] : [];
	set_transient( $transient_key, $streets, DAY_IN_SECONDS );
	return $streets;
}

/**
 * Pick a joker street name for price-quoting in address-mode.
 *
 * Econt's getPrice requires a structured street + number — but its pricing is
 * uniform per city, so the actual street value doesn't change the quote. We
 * grab the first entry in the city's getStreets list. Returns null if the
 * city has no registered streets (rare — usually small villages).
 */
function drushfe_get_joker_street_for_city( int $city_id, string $base_url ): ?string {
	$streets = drushfe_fetch_streets_for_city( $city_id, $base_url );
	if ( ! is_array( $streets ) || empty( $streets ) ) {
		return null;
	}
	$first = $streets[0];
	$name  = $first['name'] ?? '';
	return $name !== '' ? (string) $name : null;
}

/**
 * Pick a joker office code for price-quoting in office/automat mode when
 * the caller doesn't specify one. Used on the cart page where the user
 * hasn't picked a specific office yet — Econt's prices are uniform per
 * city for the same office class, so any office of the right type is fine.
 *
 * @param int    $city_id Econt city ID.
 * @param string $type    'office' or 'automat'.
 * @return string|null Office code or null when none exists.
 */
function drushfe_get_joker_office_for_city( int $city_id, string $type ): ?string {
	if ( $city_id <= 0 ) {
		return null;
	}
	global $wpdb;
	$wanted_type = ( 'automat' === $type ) ? 'APS' : 'OFFICE';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$code = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}drushfe_offices WHERE city_id = %d AND office_type = %s ORDER BY id ASC LIMIT 1",
			$city_id,
			$wanted_type
		)
	);
	return $code ? (string) $code : null;
}

/**
 * AJAX Handler: Search streets by name within a city.
 * Filters the cached getStreets list client-side. Strips common Bulgarian
 * street-type prefixes (ул., бул., пл.) so a user typing "ул. Витоша" still
 * matches the canonical "Витоша" entry.
 */
add_action( 'wp_ajax_drushfe_search_streets', 'drushfe_search_streets_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_search_streets', 'drushfe_search_streets_ajax' );

function drushfe_search_streets_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$city_id = isset( $_POST['cityID'] ) ? absint( $_POST['cityID'] ) : 0;
	$query   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

	if ( ! $city_id || mb_strlen( $query ) < 2 ) {
		wp_send_json( [] );
	}

	// Strip common Bulgarian street type prefixes so matching is on the name.
	$prefixes = [
		'улица', 'ул\.', 'ул ',
		'булевард', 'бул\.', 'бул ',
		'площад', 'пл\.', 'пл ',
		'жк', 'ж\.к\.',
		'ulitsa', 'ulica', 'ul\.', 'ul ',
		'bulevard', 'boulevard', 'bul\.', 'bul ',
		'ploshtad', 'pl\.', 'pl ',
	];
	$pattern     = '/^(' . implode( '|', $prefixes ) . ')\s*/iu';
	$clean_query = preg_replace( $pattern, '', $query );
	if ( empty( trim( (string) $clean_query ) ) ) {
		$clean_query = $query;
	}
	$clean_query = mb_strtolower( trim( $clean_query ) );

	// Resolve nomenclatures base URL from settings (demo vs production) and
	// fetch the city's full street list via the shared helper (24h transient).
	$settings = function_exists( 'drushfe_get_first_credentials' ) ? drushfe_get_first_credentials() : [];
	$is_demo  = ! empty( $settings['econt_test_mode'] ) && 'yes' === $settings['econt_test_mode'];
	$base_url = $is_demo ? 'https://demo.econt.com/ee/' : 'https://ee.econt.com/';

	$streets = drushfe_fetch_streets_for_city( $city_id, $base_url );
	if ( ! is_array( $streets ) ) {
		wp_send_json( [] );
	}

	// Filter by query (substring, case-insensitive, locale-aware).
	$results = [];
	foreach ( $streets as $street ) {
		$name = $street['name'] ?? '';
		if ( '' === $name ) {
			continue;
		}
		if ( false === mb_stripos( $name, $clean_query ) ) {
			continue;
		}
		$results[] = [
			'id'    => (int) ( $street['id'] ?? 0 ),
			'name'  => $name,
			'label' => $name,
		];

		if ( count( $results ) >= 20 ) {
			break;
		}
	}

	wp_send_json( $results );
}
function drushfe_validate_checkout(): void {
	// Check if Drusoft Shipping for Econt is the selected shipping method
	$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
	$chosen_shipping = $chosen_methods[0] ?? '';

	if ( ! str_contains( $chosen_shipping, 'drushfe_econt' ) ) {
		return;
	}

	// Check delivery type
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in woocommerce_checkout_process.
	$delivery_type = isset( $_POST['econt_delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['econt_delivery_type'] ) ) : 'address';

	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		// Econt office codes are alphanumeric strings — keep as text, do not absint.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in woocommerce_checkout_process.
		$office_id = isset( $_POST['econt_office_id'] ) ? sanitize_text_field( wp_unslash( $_POST['econt_office_id'] ) ) : '';

		if ( empty( $office_id ) ) {
			$error_msg = ( 'office' === $delivery_type ) 
				? __( 'Please select a Econt office.', 'drusoft-shipping-for-econt' ) 
				: __( 'Please select a Econt automat.', 'drusoft-shipping-for-econt' );
			
			wc_add_notice( $error_msg, 'error' );
		}
	}
}

// Service-selector handlers (drushfe_get_services / drushfe_select_service) removed:
// Econt's pricing API returns a single rate for the chosen address/office, not a
// menu of service tiers like Speedy. If you need multi-rate UX later, plug it in
// here.

/**
 * AJAX Handler: Live shipping price for the current cart + chosen recipient.
 *
 * Calls Econt's OrdersService.getPrice.json and stores the resulting
 * receiverDueAmount in the WC session under `drushfe_shipping_cost`, where
 * Drushfe_Shipping_Method::calculate_shipping() can pick it up on the next
 * update_checkout.
 */
add_action( 'wp_ajax_drushfe_calculate_price', 'drushfe_calculate_price_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_calculate_price', 'drushfe_calculate_price_ajax' );

/**
 * AJAX Handler: Clear the cached Econt shipping cost.
 *
 * Called from JS when a selection becomes incomplete (city deselected, office
 * cleared in office-mode, etc.) so a stale price doesn't display. Uses
 * flow_version to ignore stale clear-requests that arrive after a newer
 * calculate-price request.
 */
add_action( 'wp_ajax_drushfe_clear_price', 'drushfe_clear_price_ajax' );
add_action( 'wp_ajax_nopriv_drushfe_clear_price', 'drushfe_clear_price_ajax' );

function drushfe_clear_price_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$flow_version = isset( $_POST['flow_version'] ) ? absint( wp_unslash( $_POST['flow_version'] ) ) : 0;

	if ( WC()->session ) {
		$current_version = absint( WC()->session->get( 'drushfe_flow_version', 0 ) );
		if ( ! $current_version || $flow_version >= $current_version ) {
			WC()->session->set( 'drushfe_flow_version', $flow_version );
			WC()->session->set( 'drushfe_shipping_cost', 0 );
		}

		if ( WC()->cart ) {
			$packages = WC()->cart->get_shipping_packages();
			foreach ( $packages as $key => $package ) {
				WC()->session->set( 'shipping_for_package_' . $key, false );
			}
		}
	}

	wp_send_json_success();
}

function drushfe_calculate_price_ajax(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	if ( ! WC()->session || ! WC()->cart ) {
		wp_send_json_error( __( 'No cart session', 'drusoft-shipping-for-econt' ) );
	}

	$delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_type'] ) ) : 'address';
	$city_id       = isset( $_POST['city_id'] ) ? absint( wp_unslash( $_POST['city_id'] ) ) : 0;
	$city_name     = isset( $_POST['city_name'] ) ? sanitize_text_field( wp_unslash( $_POST['city_name'] ) ) : '';
	$postcode      = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
	$office_code   = isset( $_POST['office_code'] ) ? sanitize_text_field( wp_unslash( $_POST['office_code'] ) ) : '';
	$address       = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
	$state         = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
	$flow_version  = isset( $_POST['flow_version'] ) ? absint( wp_unslash( $_POST['flow_version'] ) ) : 0;

	$session = WC()->session;
	$session->set( 'drushfe_delivery_type', $delivery_type );
	$session->set( 'drushfe_city_id', $city_id );
	$session->set( 'drushfe_city_name', $city_name );
	$session->set( 'drushfe_postcode', $postcode );
	$session->set( 'drushfe_state', $state );

	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		$session->set( 'drushfe_office_id', $office_code );
		$session->set( 'drushfe_address', '' );
	} else {
		$session->set( 'drushfe_office_id', '' );
		$session->set( 'drushfe_address', $address );
	}

	$settings = function_exists( 'drushfe_get_first_credentials' ) ? drushfe_get_first_credentials() : [];
	$private_key = $settings['econt_private_key'] ?? '';
	if ( empty( $private_key ) ) {
		wp_send_json_error( __( 'Econt private key not configured', 'drusoft-shipping-for-econt' ) );
	}

	$is_demo  = ! empty( $settings['econt_test_mode'] ) && 'yes' === $settings['econt_test_mode'];
	// delivery.econt.com hosts the Orders/Pricing API (auth required).
	// ee.econt.com hosts the public Nomenclatures API (no auth) — used for
	// the joker-street lookup below.
	$base_url       = $is_demo ? 'https://delivery-demo.econt.com/' : 'https://delivery.econt.com/';
	$nomencl_base   = $is_demo ? 'https://demo.econt.com/ee/' : 'https://ee.econt.com/';

	$chosen_payment = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
	$cod = in_array( $chosen_payment, [ 'cod' ], true );

	$payload = [
		'id'                  => '',
		'orderNumber'         => '1000',
		'status'              => 'draft',
		'orderTime'           => '',
		'cod'                 => $cod,
		'partialDelivery'     => $cod ? true : '',
		'currency'            => get_woocommerce_currency(),
		'shipmentDescription' => '',
		'shipmentNumber'      => '',
		'clientSoftware'      => 'drusoft-shipping-for-econt',
		'customerInfo'        => [
			'id'           => '',
			'name'         => 'Customer',
			'face'         => '',
			'phone'        => '0888888888',
			'email'        => 'test@example.com',
			'countryCode'  => 'BGR',
			'cityName'     => $city_name,
			'postCode'     => $postcode,
			// office_code may be empty on cart-page calls (no office picker there).
			// We fall back to a joker office below — leaving this empty for now.
			'officeCode'   => '',
			'zipCode'      => '',
			'priorityFrom' => '',
			'priorityTo'   => '',
		],
		'items'               => [],
		'paymentToken'        => '',
	];

	// Address-mode payload joker: Econt's getPrice rejects a plain `address`
	// string (returns ExInvalidAddress / "ambiguous quarter") so we send a
	// known-good street + num=1 instead. Econt's pricing is uniform per city,
	// which is why the official econt.com calculator also doesn't require a
	// real street to quote a price.
	//
	// The joker is the first street from the city's getStreets nomenclature.
	// We cache it in the same transient the street-autocomplete handler uses,
	// so if the user has typed in the address box already, no extra API hit.
	if ( 'address' === $delivery_type && $city_id ) {
		$joker_street = drushfe_get_joker_street_for_city( $city_id, $nomencl_base );
		if ( $joker_street ) {
			$payload['customerInfo']['street'] = $joker_street;
			$payload['customerInfo']['num']    = '1';
		} else {
			// No streets in the nomenclature for this city — fall back to the
			// user's typed address and let Econt's validator decide.
			$payload['customerInfo']['address'] = $address;
		}
	}

	// Office/automat-mode joker: the cart page has no office picker, so the
	// caller may send an empty office_code. Pick the first office (or first
	// APS for automat) in the city — prices are uniform per office class
	// within a city, so this gives an accurate preview.
	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		$resolved_office = $office_code !== '' ? $office_code : (string) ( drushfe_get_joker_office_for_city( $city_id, $delivery_type ) ?? '' );
		$payload['customerInfo']['officeCode'] = $resolved_office;
	}

	$items_desc = [];
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product = $cart_item['data'];
		$qty     = (int) $cart_item['quantity'];
		$price   = (float) ( $cart_item['line_total'] + $cart_item['line_tax'] );
		$weight  = (float) $product->get_weight();
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
		$base_url . 'services/OrdersService.getPrice.json',
		[
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => $private_key,
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! empty( $body['type'] ) ) {
		wp_send_json_error( $body['message'] ?? __( 'Pricing failed', 'drusoft-shipping-for-econt' ) );
	}

	if ( ! isset( $body['receiverDueAmount'] ) ) {
		wp_send_json_error( __( 'No price returned by Econt', 'drusoft-shipping-for-econt' ) );
	}

	$price = (float) $body['receiverDueAmount'];

	// Race guard: only overwrite the session price if this response is for the
	// latest selection flow_version. Older responses arriving late are ignored.
	// flow_version is a Date.now() millisecond timestamp set by the browser
	// (see assets/js/checkout.js + cart.js) so a fresh page load always
	// produces newer versions than anything persisted in the WC session.
	$current_version = absint( $session->get( 'drushfe_flow_version', 0 ) );
	if ( ! $current_version || $flow_version >= $current_version ) {
		$session->set( 'drushfe_flow_version', $flow_version );
		$session->set( 'drushfe_shipping_cost', $price );
	}

	$packages = WC()->cart->get_shipping_packages();
	foreach ( $packages as $key => $package ) {
		$session->set( 'shipping_for_package_' . $key, false );
	}

	wp_send_json_success( [
		'price'        => $price,
		'currency'     => $body['currency'] ?? get_woocommerce_currency(),
		'flow_version' => $flow_version,
	] );
}

/**
 * Save Order Meta
 * Saves the selected office ID and delivery type to the order.
 */
add_action( 'woocommerce_checkout_update_order_meta', 'drushfe_save_order_meta' );
function drushfe_save_order_meta( $order_id ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
	if ( ! empty( $_POST['econt_delivery_type'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $order_id, '_drushfe_delivery_type', sanitize_text_field( wp_unslash( $_POST['econt_delivery_type'] ) ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST['econt_office_id'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $order_id, '_drushfe_office_id', sanitize_text_field( wp_unslash( $_POST['econt_office_id'] ) ) );
	}
}

/**
 * AJAX Handler: Update cart selection for Econt.
 */
add_action( 'wp_ajax_drushfe_update_cart_selection', 'drushfe_update_cart_selection' );
add_action( 'wp_ajax_nopriv_drushfe_update_cart_selection', 'drushfe_update_cart_selection' );

function drushfe_update_cart_selection(): void {
	check_ajax_referer( 'drushfe_public', 'nonce' );

	$delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_type'] ) ) : 'address';
	$city_id       = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
	$state         = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';

	if ( ! WC()->session ) {
		wp_send_json_error();
	}

	WC()->session->set( 'drushfe_delivery_type', $delivery_type );
	
	if ( ! empty( $state ) ) {
		WC()->session->set( 'drushfe_state', $state );
		if ( WC()->customer ) {
			WC()->customer->set_shipping_state( $state );
			WC()->customer->set_billing_state( $state );
		}
	}

	if ( $city_id > 0 ) {
		WC()->session->set( 'drushfe_city_id', $city_id );

		if ( WC()->customer ) {
			$city_name = drushfe_get_city_name( $city_id );
			WC()->customer->set_shipping_city( $city_name ?: $city_id );
			WC()->customer->set_billing_city( $city_name ?: $city_id );
		}
	} else {
		$city_id = absint( WC()->session->get( 'drushfe_city_id', 0 ) );
	}

	if ( WC()->customer ) {
		WC()->customer->save();
	}
	
	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		$office_id = Drushfe_Shipping_Method::get_first_available_office( $city_id, $delivery_type );
		WC()->session->set( 'drushfe_office_id', $office_id );
	} else {
		WC()->session->set( 'drushfe_office_id', 0 );
	}

	// Check office/automat availability for this city so the JS can
	// update the radio buttons without a separate AJAX call.
	$availability = drushfe_check_city_availability( $city_id );

	// Clear shipping cache
	$packages = WC()->cart ? WC()->cart->get_shipping_packages() : [];
	foreach ( $packages as $key => $package ) {
		WC()->session->set( 'shipping_for_package_' . $key, false );
	}

	wp_send_json_success([
		'current_type' => $delivery_type,
		'has_office'   => $availability['has_office'],
		'has_automat'  => $availability['has_automat'],
	]);
}
