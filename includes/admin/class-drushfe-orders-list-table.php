<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Drushfe_Orders_List_Table
 *
 * Extends WP_List_Table to display a list of WooCommerce orders that have an associated Econt waybill.
 * Provides functionality for listing, pagination, and actions like printing waybills, canceling shipments, and requesting couriers.
 */
class Drushfe_Orders_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * Sets up the list table properties.
	 */
	public function __construct() {
		parent::__construct( [
			'singular' => __( 'Econt Order', 'drusoft-shipping-for-econt' ),
			'plural'   => __( 'Econt Orders', 'drusoft-shipping-for-econt' ),
			'ajax'     => false,
		] );
	}

	/**
	 * Get a list of columns.
	 *
	 * @return array The list of columns.
	 */
	public function get_columns(): array {
		return [
			'cb'       => '<input type="checkbox" />',
			'order'    => __( 'Order', 'drusoft-shipping-for-econt' ),
			'waybill'  => __( 'Waybill', 'drusoft-shipping-for-econt' ),
			'customer' => __( 'Customer', 'drusoft-shipping-for-econt' ),
			'status'   => __( 'Status', 'drusoft-shipping-for-econt' ),
			'date'     => __( 'Date', 'drusoft-shipping-for-econt' ),
		];
	}

	/**
	 * Bulk actions exposed via the "Bulk actions" dropdown above/below the list.
	 *
	 * All three reuse the same server-side logic the single-order metabox uses:
	 *   - generate → Drushfe_Waybill_Generator::generate_waybill() per order
	 *   - print    → collect each order's saved pdfURL and render as a click-list
	 *   - cancel   → one batched LabelService.deleteLabels call for all selected waybills
	 *
	 * @return array Action key → label.
	 */
	public function get_bulk_actions(): array {
		return [
			'generate' => __( 'Generate Waybills', 'drusoft-shipping-for-econt' ),
			'print'    => __( 'Print Waybills', 'drusoft-shipping-for-econt' ),
			'cancel'   => __( 'Cancel Shipments', 'drusoft-shipping-for-econt' ),
		];
	}

	/**
	 * Process the submitted bulk action. Called from the admin menu render
	 * before `display()` so any redirects / notices can fire first.
	 *
	 * Returns a structured result array (action, processed, errors, urls)
	 * the menu handler then renders as a notice.
	 *
	 * @return array{action:string,processed:int,errors:array,urls:array}|null
	 *               null when no bulk action was submitted.
	 */
	public function handle_bulk_action(): ?array {
		$action = $this->current_action();
		if ( ! $action || ! in_array( $action, [ 'generate', 'print', 'cancel' ], true ) ) {
			return null;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'drusoft-shipping-for-econt' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$order_ids = isset( $_REQUEST['order'] ) && is_array( $_REQUEST['order'] )
			? array_map( 'absint', wp_unslash( $_REQUEST['order'] ) )
			: [];
		$order_ids = array_values( array_filter( $order_ids ) );

		$result = [
			'action'    => $action,
			'processed' => 0,
			'errors'    => [],
			'urls'      => [],
		];

		if ( empty( $order_ids ) ) {
			$result['errors'][] = __( 'No orders were selected.', 'drusoft-shipping-for-econt' );
			return $result;
		}

		switch ( $action ) {
			case 'generate':
				$result = $this->bulk_generate( $order_ids, $result );
				break;

			case 'print':
				$result = $this->bulk_print( $order_ids, $result );
				break;

			case 'cancel':
				$result = $this->bulk_cancel( $order_ids, $result );
				break;
		}

		return $result;
	}

	/**
	 * Generate waybills for every selected order.
	 */
	private function bulk_generate( array $order_ids, array $result ): array {
		foreach ( $order_ids as $order_id ) {
			$res = Drushfe_Waybill_Generator::instance()->generate_waybill( $order_id );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: order ID, 2: error message */
				$result['errors'][] = sprintf( __( 'Order #%1$d: %2$s', 'drusoft-shipping-for-econt' ), $order_id, $res->get_error_message() );
			} else {
				$result['processed']++;
			}
		}
		return $result;
	}

	/**
	 * Collect the saved pdfURL for each waybill and render as clickable links.
	 *
	 * We don't proxy the PDF or zip server-side — pdfURLs are signed by Econt
	 * and remain valid; the user clicks each link to print in a new tab.
	 */
	private function bulk_print( array $order_ids, array $result ): array {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$waybill_id    = (string) $order->get_meta( '_drushfe_waybill_id' );
			$response_meta = $order->get_meta( '_drushfe_waybill_response' );
			$pdf_url       = is_array( $response_meta ) ? ( $response_meta['pdfURL'] ?? '' ) : '';
			if ( ! $waybill_id || ! $pdf_url ) {
				/* translators: %d: order ID */
				$result['errors'][] = sprintf( __( 'Order #%d has no PDF URL — regenerate the waybill.', 'drusoft-shipping-for-econt' ), $order_id );
				continue;
			}
			$result['urls'][] = [
				'order_id'   => $order_id,
				'waybill_id' => $waybill_id,
				'pdf_url'    => $pdf_url,
			];
			$result['processed']++;
		}
		return $result;
	}

	/**
	 * Cancel multiple shipments in a single LabelService.deleteLabels call.
	 *
	 * LabelService lives on ee.econt.com (NOT delivery.econt.com — that has
	 * OrdersService.deleteLabel which is single-id). It's keyed by the
	 * 13-digit `shipmentNumber` from the createAWB response, not by the
	 * internal `_drushfe_waybill_id`. Orders that have no shipmentNumber
	 * (e.g. their waybill draft never made it through createAWB) are
	 * reported as errors so the user knows to regenerate before cancelling.
	 */
	private function bulk_cancel( array $order_ids, array $result ): array {
		// Build (order_id => shipmentNumber) for orders that have an AWB.
		$id_to_shipment = [];
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$resp = $order->get_meta( '_drushfe_waybill_response' );
			$ship = is_array( $resp ) ? (string) ( $resp['shipmentNumber'] ?? '' ) : '';
			if ( '' === $ship ) {
				/* translators: %d: order ID */
				$result['errors'][] = sprintf( __( 'Order #%d: no AWB shipmentNumber (regenerate the waybill first).', 'drusoft-shipping-for-econt' ), (int) $order_id );
				continue;
			}
			$id_to_shipment[ $order_id ] = $ship;
		}

		if ( empty( $id_to_shipment ) ) {
			return $result;
		}

		$first_order = wc_get_order( array_key_first( $id_to_shipment ) );
		$ctx = $this->resolve_api_context_for_order( $first_order );
		if ( ! $ctx ) {
			$result['errors'][] = __( 'Econt API credentials not configured.', 'drusoft-shipping-for-econt' );
			return $result;
		}

		$response = wp_remote_post(
			$ctx['ee_base'] . 'services/Shipments/LabelService.deleteLabels.json',
			[
				'headers' => $ctx['headers'],
				'body'    => wp_json_encode( [ 'shipmentNumbers' => array_values( $id_to_shipment ) ] ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$result['errors'][] = $response->get_error_message();
			return $result;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['type'] ) ) {
			$result['errors'][] = $body['message'] ?? __( 'Econt API error', 'drusoft-shipping-for-econt' );
			return $result;
		}

		// Map per-row results back to order IDs via shipmentNumber.
		$row_results    = is_array( $body['results'] ?? null ) ? $body['results'] : [];
		$shipment_to_id = array_flip( $id_to_shipment );

		foreach ( $row_results as $row ) {
			$ship = (string) ( $row['shipmentNum'] ?? '' );
			$oid  = $shipment_to_id[ $ship ] ?? 0;
			if ( ! $oid ) {
				continue;
			}
			if ( ! empty( $row['error']['message'] ) ) {
				/* translators: 1: order ID, 2: error message */
				$result['errors'][] = sprintf( __( 'Order #%1$d: %2$s', 'drusoft-shipping-for-econt' ), $oid, $row['error']['message'] );
				continue;
			}
			$order = wc_get_order( $oid );
			if ( $order ) {
				$order->delete_meta_data( '_drushfe_waybill_id' );
				$order->delete_meta_data( '_drushfe_waybill_response' );
				$order->delete_meta_data( '_drushfe_courier_requested' );
				/* translators: %s: Econt shipment number */
				$order->add_order_note( sprintf( __( 'Econt shipment %s cancelled (bulk).', 'drusoft-shipping-for-econt' ), $ship ) );
				$order->save();
			}
			$result['processed']++;
		}

		return $result;
	}

	/**
	 * Helper: resolve API base URL + headers for an order's shipping method
	 * instance settings. Mirrors Drushfe_Actions::get_api_context_for_order.
	 *
	 * @return array{base:string,headers:array}|null
	 */
	private function resolve_api_context_for_order( $order ): ?array {
		$settings = null;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'drushfe_econt' === $method->get_method_id() ) {
				$inst = get_option( 'woocommerce_drushfe_econt_' . $method->get_instance_id() . '_settings' );
				if ( is_array( $inst ) && ! empty( $inst['econt_private_key'] ) ) {
					$settings = $inst;
					break;
				}
			}
		}
		if ( ! $settings ) {
			$settings = drushfe_get_first_credentials();
		}
		if ( ! $settings || empty( $settings['econt_private_key'] ) ) {
			return null;
		}

		$is_demo = ! empty( $settings['econt_test_mode'] ) && 'yes' === $settings['econt_test_mode'];
		return [
			'base'    => $is_demo ? 'https://delivery-demo.econt.com/' : 'https://delivery.econt.com/',
			'ee_base' => $is_demo ? 'https://demo.econt.com/ee/' : 'https://ee.econt.com/',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['econt_private_key'],
			],
		];
	}

	/**
	 * Prepare the items for the table to process.
	 *
	 * Fetches all orders that used Econt shipping, handling pagination and sorting.
	 * Orders without a waybill yet will show a "Generate" button.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$paged    = $this->get_pagenum();
		$per_page = 20;

		$args = [
			'limit'        => $per_page,
			'paged'        => $paged,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'meta_key'     => '_drushfe_econt_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Tag written by Drushfe_Shipping_Method::save_shipping_data_to_order().
			'meta_compare' => 'EXISTS',
			'paginate'     => true, // Required to get total count
		];

		// wc_get_orders with paginate=true returns an object with 'orders' and 'total'
		$results = wc_get_orders( $args );

		$this->items = $results->orders;

		$this->set_pagination_args( [
			'total_items' => $results->total,
			'per_page'    => $per_page,
		] );
	}

	/**
	 * Default column renderer.
	 *
	 * @param WC_Order $item        The order object.
	 * @param string   $column_name The name of the column to render.
	 *
	 * @return string The column content.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'order':
				return sprintf( '<a href="%s">#%s</a>', esc_url( $item->get_edit_order_url() ), esc_html( $item->get_order_number() ) );
			case 'customer':
				return esc_html( $item->get_formatted_billing_full_name() );
			case 'status':
				return esc_html( wc_get_order_status_name( $item->get_status() ) );
			case 'date':
				return esc_html( $item->get_date_created()->date_i18n( 'Y/m/d' ) );
			default:
				return '';
		}
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param WC_Order $item The order object.
	 *
	 * @return string The checkbox HTML.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="order[]" value="%s" />', esc_attr( $item->get_id() ) );
	}

	/**
	 * Render the Waybill column.
	 *
	 * Displays the waybill ID (linked to tracking) and action buttons (Print, Cancel, Request Courier).
	 *
	 * @param WC_Order $item The order object.
	 *
	 * @return string The column content.
	 */
	protected function column_waybill( $item ): string {
		$waybill_id = $item->get_meta( '_drushfe_waybill_id' );

		if ( ! $waybill_id ) {
			return '<button class="button econt-generate-waybill" data-order-id="' . esc_attr( $item->get_id() ) . '">' . esc_html__( 'Generate', 'drusoft-shipping-for-econt' ) . '</button>';
		}

		$print_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=drushfe_print_waybill&order_id=' . $item->get_id() ),
			'drushfe_print_waybill'
		);

		$actions = [
			'print'   => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $print_url ), esc_html__( 'Print', 'drusoft-shipping-for-econt' ) ),
			'cancel'  => sprintf( '<a href="#" class="econt-cancel-shipment" data-order-id="%d">%s</a>', esc_attr( $item->get_id() ), esc_html__( 'Cancel', 'drusoft-shipping-for-econt' ) ),
		];

		$courier_requested = $item->get_meta( '_drushfe_courier_requested' );
		if ( 'yes' === $courier_requested ) {
			$actions['courier'] = '<span style="color: green;">' . esc_html__( 'Requested', 'drusoft-shipping-for-econt' ) . '</span>';
		} else {
			$actions['courier'] = sprintf( '<a href="#" class="econt-request-courier" data-order-id="%d">%s</a>', esc_attr( $item->get_id() ), esc_html__( 'Request Courier', 'drusoft-shipping-for-econt' ) );
		}

		$track_url    = 'https://www.econt.com/services/track-shipment/' . urlencode( $waybill_id );
		$waybill_link = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $track_url ), esc_html( $waybill_id ) );

		return $waybill_link . $this->row_actions( $actions );
	}

	/**
	 * Display the table.
	 *
	 * Overrides the parent display method to include necessary JavaScript for AJAX actions.
	 *
	 * @return void
	 */
	public function display(): void {
		parent::display();
		// JS is now enqueued via admin-menu.php
	}
}
