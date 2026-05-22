<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Econt shipment meta box to the WooCommerce order edit page.
 *
 * Displays waybill status and provides Generate, Print, Cancel,
 * and Request Courier buttons directly on the order screen.
 */
class Drushfe_Order_Metabox {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * Register the meta box for WooCommerce orders.
	 * Supports both HPOS (woocommerce_page_wc-orders) and legacy (shop_order).
	 */
	public static function add_meta_box(): void {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

		// Only show the meta box if the order uses Econt shipping
		$order = self::get_current_order();
		if ( ! $order ) {
			return;
		}

		$has_econt = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'drushfe_econt' === $method->get_method_id() ) {
				$has_econt = true;
				break;
			}
		}

		if ( ! $has_econt ) {
			return;
		}

		add_meta_box(
			'drushfe-shipment',
			__( 'Econt Shipment', 'drusoft-shipping-for-econt' ),
			[ __CLASS__, 'render' ],
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Determine the correct screen ID for the order edit page.
	 *
	 * @return string|null
	 */
	private static function get_order_screen(): ?string {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return null;
		}

		// HPOS
		if ( 'woocommerce_page_wc-orders' === $screen->id ) {
			return $screen->id;
		}

		// Legacy
		if ( 'shop_order' === $screen->id ) {
			return 'shop_order';
		}

		return null;
	}

	/**
	 * Get the order object from the current screen.
	 *
	 * @return WC_Order|null
	 */
	private static function get_current_order(): ?WC_Order {
		// HPOS: order ID is in the GET parameter
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; used to display a meta box on the WC order screen.
		if ( isset( $_GET['id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( absint( $_GET['id'] ) );
			return $order ?: null;
		}

		// Legacy: order ID is the post ID
		global $post;
		if ( $post && 'shop_order' === $post->post_type ) {
			$order = wc_get_order( $post->ID );
			return $order ?: null;
		}

		return null;
	}

	/**
	 * Render the meta box content.
	 */
	public static function render(): void {
		$order = self::get_current_order();
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'drusoft-shipping-for-econt' ) . '</p>';
			return;
		}

		$order_id   = $order->get_id();
		$waybill_id = $order->get_meta( '_drushfe_waybill_id' );
		$courier_requested = ( 'yes' === $order->get_meta( '_drushfe_courier_requested' ) );

		echo '<div id="econt-metabox-content">';

		if ( $waybill_id ) {
			// Waybill exists — show info and actions
			$track_url = 'https://www.econt.com/services/track-shipment/' . urlencode( $waybill_id );
			$print_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=drushfe_print_waybill&order_id=' . $order_id ),
				'drushfe_print_waybill'
			);

			echo '<p><strong>' . esc_html__( 'Waybill:', 'drusoft-shipping-for-econt' ) . '</strong> ';
			echo '<a href="' . esc_url( $track_url ) . '" target="_blank">' . esc_html( $waybill_id ) . '</a></p>';

			echo '<div class="econt-metabox-actions" style="display: flex; flex-direction: column; gap: 6px;">';

			// Print
			echo '<a href="' . esc_url( $print_url ) . '" target="_blank" class="button" style="text-align:center;">'
			     . esc_html__( 'Print Waybill', 'drusoft-shipping-for-econt' ) . '</a>';

			// Request Courier
			if ( $courier_requested ) {
				echo '<span class="button disabled" style="text-align:center; color: green;">'
				     . esc_html__( 'Courier Requested', 'drusoft-shipping-for-econt' ) . '</span>';
			} else {
				echo '<button type="button" class="button econt-order-request-courier" data-order-id="' . esc_attr( $order_id ) . '">'
				     . esc_html__( 'Request Courier', 'drusoft-shipping-for-econt' ) . '</button>';
			}

			// Cancel
			echo '<button type="button" class="button econt-order-cancel" data-order-id="' . esc_attr( $order_id ) . '" style="color: #a00;">'
			     . esc_html__( 'Cancel Shipment', 'drusoft-shipping-for-econt' ) . '</button>';

			echo '</div>';
		} else {
			// No waybill — show generate button
			echo '<p>' . esc_html__( 'No waybill generated yet.', 'drusoft-shipping-for-econt' ) . '</p>';
			echo '<button type="button" class="button button-primary econt-order-generate" data-order-id="' . esc_attr( $order_id ) . '">'
			     . esc_html__( 'Generate Waybill', 'drusoft-shipping-for-econt' ) . '</button>';
		}

		echo '</div>';
		echo '<div id="econt-metabox-notice" style="margin-top: 8px;"></div>';
	}

	/**
	 * Enqueue JS on the order edit screen.
	 */
	public static function enqueue_scripts( $hook ): void {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

		wp_enqueue_script(
			'drushfe-order-metabox',
			DRUSHFE_URL . 'assets/js/order-metabox.js',
			[ 'jquery' ],
			DRUSHFE_VER,
			true
		);

		wp_localize_script( 'drushfe-order-metabox', 'drushfe_metabox_params', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'drushfe_actions' ),
			'i18n'     => [
				'confirm_cancel'    => __( 'Are you sure you want to cancel this shipment?', 'drusoft-shipping-for-econt' ),
				'generating'        => __( 'Generating...', 'drusoft-shipping-for-econt' ),
				'requesting'        => __( 'Requesting...', 'drusoft-shipping-for-econt' ),
				'courier_requested' => __( 'Courier Requested', 'drusoft-shipping-for-econt' ),
				'cancelling'        => __( 'Cancelling...', 'drusoft-shipping-for-econt' ),
			],
		] );
	}
}

Drushfe_Order_Metabox::init();

