<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drushfe_Admin_Menu {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	public static function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Econt Orders', 'drusoft-shipping-for-econt' ),
			__( 'Econt Orders', 'drusoft-shipping-for-econt' ),
			'manage_woocommerce',
			'drushfe-orders',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_scripts( $hook ): void {
		if ( 'woocommerce_page_drushfe-orders' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'drushfe-admin-orders',
			DRUSHFE_URL . 'assets/js/admin-orders.js',
			[ 'jquery' ],
			DRUSHFE_VER,
			true
		);

		wp_localize_script( 'drushfe-admin-orders', 'drushfe_admin_params', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'drushfe_actions' ),
			'i18n'     => [
				'confirm_cancel'  => __( 'Are you sure you want to cancel this shipment?', 'drusoft-shipping-for-econt' ),
				'requesting'      => __( 'Requesting...', 'drusoft-shipping-for-econt' ),
				'requested'       => __( 'Requested', 'drusoft-shipping-for-econt' ),
				'request_courier' => __( 'Request Courier', 'drusoft-shipping-for-econt' ),
				'generating'      => __( 'Generating...', 'drusoft-shipping-for-econt' ),
				'generate'        => __( 'Generate', 'drusoft-shipping-for-econt' ),
			],
		] );
	}

	public static function render_page(): void {
		require_once __DIR__ . '/class-drushfe-orders-list-table.php';

		$table = new Drushfe_Orders_List_Table();

		// Process bulk action BEFORE preparing/rendering rows so any DB writes
		// (waybill creation, cancellation) are reflected in the table.
		$bulk_result = $table->handle_bulk_action();

		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Econt Orders', 'drusoft-shipping-for-econt' ) . '</h1>';

		if ( $bulk_result ) {
			self::render_bulk_notice( $bulk_result );
		}

		echo '<form method="post">';
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render a notice summarising a bulk action's result.
	 *
	 * For the "print" action we additionally list each waybill's pdfURL so
	 * the user can click each one to print — pdfURLs are signed by Econt
	 * and remain valid, no server-side zip needed.
	 *
	 * @param array{action:string,processed:int,errors:array,urls:array} $result
	 */
	private static function render_bulk_notice( array $result ): void {
		$labels = [
			'generate' => __( 'waybills generated', 'drusoft-shipping-for-econt' ),
			'print'    => __( 'waybills ready to print', 'drusoft-shipping-for-econt' ),
			'cancel'   => __( 'shipments cancelled', 'drusoft-shipping-for-econt' ),
		];
		$label = $labels[ $result['action'] ] ?? '';

		$type = ( $result['processed'] > 0 && empty( $result['errors'] ) ) ? 'success' : ( $result['processed'] > 0 ? 'warning' : 'error' );

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>';
		printf(
			/* translators: 1: count, 2: action label */
			esc_html__( '%1$d %2$s.', 'drusoft-shipping-for-econt' ),
			(int) $result['processed'],
			esc_html( $label )
		);
		echo '</p>';

		if ( ! empty( $result['errors'] ) ) {
			echo '<ul>';
			foreach ( $result['errors'] as $err ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			}
			echo '</ul>';
		}

		if ( 'print' === $result['action'] && ! empty( $result['urls'] ) ) {
			echo '<p><strong>' . esc_html__( 'Click each link to open the PDF in a new tab:', 'drusoft-shipping-for-econt' ) . '</strong></p>';
			echo '<ul>';
			foreach ( $result['urls'] as $u ) {
				echo '<li><a href="' . esc_url( $u['pdf_url'] ) . '" target="_blank" rel="noopener">';
				/* translators: 1: order ID, 2: waybill ID */
				printf( esc_html__( 'Order #%1$d — waybill %2$s', 'drusoft-shipping-for-econt' ), (int) $u['order_id'], esc_html( $u['waybill_id'] ) );
				echo '</a></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}
}

Drushfe_Admin_Menu::init();
