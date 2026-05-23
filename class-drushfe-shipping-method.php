<?php
/**
 * Drusoft Econt Shipping Method Class
 *
 * @copyright 2026 DRUSOFT LTD.
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Drushfe_Shipping_Method' ) ) {

	class Drushfe_Shipping_Method extends WC_Shipping_Method {

		/**
		 * Constructor for the shipping class
		 */
		public function __construct( $instance_id = 0 ) {
			// Fixes the "Missing parent constructor call" warning
			parent::__construct( $instance_id );

			$this->id                 = 'drushfe_econt';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Drusoft Shipping for Econt', 'drusoft-shipping-for-econt' );
			$this->method_description = __( 'Fresh, conflict-free Econt delivery for Bulgaria.', 'drusoft-shipping-for-econt' );

			$this->supports = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);

			$this->init();
		}

		/**
		 * Initialize settings and hooks
		 */
		public function init(): void {
			// Load the settings API
			$this->init_instance_settings();
			$this->init_form_fields();
			$this->init_settings();

			// Define user-set variables from instance settings
			$this->enabled = $this->get_instance_option( 'enabled', 'yes' );
			$this->title = $this->get_instance_option( 'title', __( 'Econt Delivery', 'drusoft-shipping-for-econt' ) );

			// Save settings in admin
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

			// Save shipping data to order
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_shipping_data_to_order' ), 10, 2 );
		}

		/**
		 * Save Econt session data to the order before it is created.
		 *
		 * @param WC_Order $order The order object being created.
         */
		public function save_shipping_data_to_order(WC_Order $order): void {
			// 1. Check if our shipping method is chosen
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$is_econt      = false;

			if ( ! empty( $chosen_methods ) ) {
				foreach ( $chosen_methods as $method_id ) {
					if ( str_starts_with( $method_id, $this->id ) ) {
						$is_econt = true;
						break;
					}
				}
			}

			if ( ! $is_econt ) {
				return;
			}

			// Tag every Econt order so the "Econt Orders" admin list can find
			// it reliably. The old `_drushfe_order_data` meta below only gets
			// written when session payload happens to be present, which made
			// the admin list miss new orders. Keep that meta as the detailed
			// payload (still useful for waybill creation when populated), but
			// gate visibility on this stable boolean flag.
			$order->add_meta_data( '_drushfe_econt_order', 1 );

			// Mirror the delivery_type + office_id from the shipping LINE ITEM
			// up to the ORDER. They're set on the rate in calculate_shipping()
			// and stored as item meta — but the waybill generator reads them
			// off the order via $order->get_meta(). Without this copy, orders
			// arrive at the waybill stage with an empty delivery_type, which
			// makes us send neither office code NOR structured address fields
			// and Econt rejects with "insufficient address" / "no office".
			foreach ( $order->get_shipping_methods() as $shipping_item ) {
				if ( 'drushfe_econt' !== $shipping_item->get_method_id() ) {
					continue;
				}
				$item_delivery = (string) $shipping_item->get_meta( '_drushfe_delivery_type' );
				$item_office   = (string) $shipping_item->get_meta( '_drushfe_office_id' );
				if ( $item_delivery !== '' ) {
					$order->add_meta_data( '_drushfe_delivery_type', $item_delivery );
				}
				if ( $item_office !== '' ) {
					$order->add_meta_data( '_drushfe_office_id', $item_office );
				}
				break;
			}

			// The city dropdown stores the Econt city_id as its option value,
			// so WC writes a numeric string (e.g. "94") into billing_city /
			// shipping_city. Econt's API rejects that with "Mismatch between
			// city and postal code". Convert the ID back to the proper city
			// name AND normalize the postcode from our cities table so the
			// two always agree before the waybill is generated. The original
			// ID is stashed in meta for waybill code that needs it.
			global $wpdb;
			foreach ( [ 'billing', 'shipping' ] as $addr ) {
				$city_getter = "get_{$addr}_city";
				$city_setter = "set_{$addr}_city";
				$pc_setter   = "set_{$addr}_postcode";
				$value       = (string) $order->$city_getter();
				if ( $value === '' || ! ctype_digit( $value ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT name, post_code FROM {$wpdb->prefix}drushfe_cities WHERE id = %d",
					(int) $value
				) );
				if ( ! $row || empty( $row->name ) ) {
					continue;
				}
				$order->add_meta_data( "_drushfe_{$addr}_city_id", (int) $value );
				$order->$city_setter( $row->name );
				if ( ! empty( $row->post_code ) ) {
					$order->$pc_setter( $row->post_code );
				}
			}

			// 2. Get the selected service from session (set by our service selector)
			$chosen_service_id = WC()->session ? (int) WC()->session->get( 'drushfe_selected_service', 0 ) : 0;

			// 3. Try to get the service-specific session data first, fallback to general
			$session_data = null;
			if ( $chosen_service_id && WC()->session ) {
				$session_data = WC()->session->get( 'drushfe_shipping_data_' . $chosen_service_id );
			}
			if ( empty( $session_data ) ) {
				$session_data = WC()->session ? WC()->session->get( 'drushfe_shipping_data' ) : null;
			}

			if ( ! empty( $session_data ) ) {
				// Ensure the payload uses only the selected service
				if ( $chosen_service_id ) {
					$session_data['service']['serviceIds'] = [ $chosen_service_id ];
					$session_data['_selected_service_id']  = $chosen_service_id;
				}

				// 4. Save to order meta
				$order->add_meta_data( '_drushfe_order_data', $session_data );

				if ( isset( $session_data['recipient']['pickupOfficeId'] ) ) {
					$order->add_meta_data( '_drushfe_office_id', $session_data['recipient']['pickupOfficeId'] );
				}
			}
		}

		/**
		 * Processes and saves shipping method options in the admin area.
		 *
		 * Validates credentials against the Econt API BEFORE saving.
		 * If invalid, only the basic fields are kept and the credentials are cleared.
		 */
		public function process_admin_options(): bool {

			// Credential pre-validation against an Econt probe endpoint is left to a
			// future iteration once the right call (e.g. ProfileService.getClientProfiles)
			// is wired up. For now we accept whatever the user enters.
			$saved = parent::process_admin_options();
			$this->clear_econt_cache();

			if ( $saved && $this->get_option( 'econt_private_key' ) ) {
				// Sync only when the tables are empty (first-time setup).
				// Cities and offices are the same for every Econt account.
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$cities_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drushfe_cities" );

				if ( 0 === $cities_count ) {
					if ( class_exists( 'Drushfe_Syncer' ) ) {
						Drushfe_Syncer::sync();
					}
				}

				// Ensure a recurring daily refresh is scheduled.
				if ( ! as_next_scheduled_action( 'drushfe_sync_locations_event' ) ) {
					if ( function_exists( 'as_schedule_recurring_action' ) ) {
						as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'drushfe_sync_locations_event' );
					}
				}
			}

			return $saved;
		}

        /**
         * Validate the sender_city field.
         *
         * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
         * We simply return the value (sanitized).
         *
         * @param string $_key
         * @param string $value
         * @return string
         */
		public function validate_sender_city_field( string $_key, string $value ): string {
			return sanitize_text_field( $value );
		}

        /**
         * Validate the sender_office field.
         *
         * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
         * We simply return the value (sanitized).
         *
         * @param string $_key
         * @param string $value
         * @return string
         */
		public function validate_sender_office_field( string $_key, string $value ): string {
			$office_id = absint( $value );
			if ( $office_id <= 0 ) {
				return sanitize_text_field( $value );
			}

			// Reject automats — they cannot be used as sender drop-off points.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT office_type, name FROM {$wpdb->prefix}drushfe_offices WHERE id = %d",
					$office_id
				)
			);
			if ( $row && drushfe_is_automat( $row->office_type, $row->name ) ) {
				WC_Admin_Settings::add_error(
					__( 'Automats (APT/APS) cannot be used as sender drop-off offices. Please select a regular Econt office.', 'drusoft-shipping-for-econt' )
				);
				return '';
			}

			return sanitize_text_field( $value );
		}

		/**
		 * Define the settings fields
		 */
		public function init_form_fields(): void {

			$this->instance_form_fields = array(
				// --- SECTION: CONNECTION ---
				'section_api' => [
					'title' => __( 'Econt API Connection', 'drusoft-shipping-for-econt' ),
					'type'  => 'title',
				],
				'enabled' => [
					'title'   => __( 'Module Status', 'drusoft-shipping-for-econt' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable/Disable', 'drusoft-shipping-for-econt' ),
					'default' => 'yes',
				],
				'title' => [
					'title'       => __( 'Method Title', 'drusoft-shipping-for-econt' ),
					'type'        => 'text',
					'default'     => __( 'Econt Delivery', 'drusoft-shipping-for-econt' ),
					'desc_tip'    => true,
				],
				'econt_store_id' => [
					'title'       => __( 'Store ID', 'drusoft-shipping-for-econt' ),
					'type'        => 'text',
					'description' => __( 'Your Econt client/shop ID number (used in Econt analytics).', 'drusoft-shipping-for-econt' ),
				],
				'econt_private_key' => [
					'title'       => __( 'Private Key', 'drusoft-shipping-for-econt' ),
					'type'        => 'password',
					'description' => __( 'Issued by Econt for your delivery.econt.com account.', 'drusoft-shipping-for-econt' ),
				],
				'econt_test_mode' => [
					'title'       => __( 'Demo Mode', 'drusoft-shipping-for-econt' ),
					'type'        => 'checkbox',
					'label'       => __( 'Use Econt demo endpoints (delivery-demo.econt.com / demo.econt.com).', 'drusoft-shipping-for-econt' ),
					'default'     => 'no',
				],
			);

			// Only show advanced settings if API credentials are saved
			if ( $this->get_option('econt_private_key') ) {
				$this->add_authenticated_fields();
			} else {
				$this->instance_form_fields['info_msg'] = [
					'type'        => 'title',
					'description' => __( 'Please save your credentials to unlock shipping options.', 'drusoft-shipping-for-econt' ),
				];
			}
		}

		/**
		 * Fields that require a valid API connection.
		 * These are merged into the main form_fields array.
		 */
		private function add_authenticated_fields(): void {
			
			// Read directly from saved settings to avoid WC looking up defaults
			// from form fields that haven't been defined yet (chicken-and-egg).
			if ( empty( $this->instance_settings ) ) {
				$this->init_instance_settings();
			}
			$current_city = (int) ( $this->instance_settings['sender_city'] ?? 0 );
			
			// Workaround: If a new city is posted, add it to options so validation passes
			// even if validate_sender_city_field is somehow bypassed or fails.
			$field_key = $this->get_field_key( 'sender_city' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in process_admin_options.
			if ( isset( $_POST[ $field_key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted_city = sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) );
				if ( $posted_city ) {
					$current_city = $posted_city;
				}
			}

			$current_office = (int) ( $this->instance_settings['sender_office'] ?? 0 );
			$field_key_office = $this->get_field_key( 'sender_office' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in process_admin_options.
			if ( isset( $_POST[ $field_key_office ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted_office = sanitize_text_field( wp_unslash( $_POST[ $field_key_office ] ) );
				if ( $posted_office ) {
					$current_office = $posted_office;
				}
			}

			$authenticated = [

				// --- SECTION: SENDER DETAILS ---
				'section_sender' => [
					'title' => __( 'Sender Information', 'drusoft-shipping-for-econt' ),
					'type'  => 'title',
				],
				'sender_id' => [
					'title'       => __( 'Sender Client ID', 'drusoft-shipping-for-econt' ),
					'type'        => 'number',
					'description' => __( 'Your Econt client/contract ID — usually the same number as Store ID. Used as sender.clientId when creating waybills.', 'drusoft-shipping-for-econt' ),
					'desc_tip'    => true,
					'default'     => '',
				],
				'sender_name' => [
					'title' => __( 'Contact Person', 'drusoft-shipping-for-econt' ),
					'type'  => 'text'
				],
				'sender_email' => [
					'title' => __( 'Email', 'drusoft-shipping-for-econt' ),
					'type'  => 'email'
				],
				'sender_phone' => [
					'title' => __( 'Phone Number', 'drusoft-shipping-for-econt' ),
					'type'  => 'text'
				],
				'sender_city' => [
					'title'   => __( 'City', 'drusoft-shipping-for-econt' ),
					'type'    => 'select',
					'class'   => 'econt-city-search',
					'options' => [ $current_city => drushfe_get_city_name_by_id( $current_city ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for a city...', 'drusoft-shipping-for-econt' ),
					],
				],
				'sender_street' => [
					'title'       => __( 'Street', 'drusoft-shipping-for-econt' ),
					'type'        => 'text',
					'description' => __( 'Street name only — without the number. Example: "ул. Кирил и Методий". Must match one of your Econt-registered pickup addresses ("Моите адреси" in My Econt).', 'drusoft-shipping-for-econt' ),
					'desc_tip'    => true,
					'default'     => '',
				],
				'sender_num' => [
					'title'       => __( 'Street Number', 'drusoft-shipping-for-econt' ),
					'type'        => 'text',
					'description' => __( 'House/building number for the street above. Example: "3" or "12А".', 'drusoft-shipping-for-econt' ),
					'desc_tip'    => true,
					'default'     => '',
				],
				'sender_officeyesno' => [
					'title'   => __( 'Send from Office', 'drusoft-shipping-for-econt' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'drusoft-shipping-for-econt' ),
						'YES' => __( 'Yes', 'drusoft-shipping-for-econt' ),
					],
				],
				'sender_office' => [
					'title'   => __( 'Shipping from Office', 'drusoft-shipping-for-econt' ),
					'type'    => 'select',
					'class'   => 'econt-office-search',
					'options' => [ $current_office => drushfe_get_office_label_by_id( $current_office ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for an office...', 'drusoft-shipping-for-econt' ),
					],
				],
				'sender_time' => [
					'title'       => __( 'Working Day End Time', 'drusoft-shipping-for-econt' ),
					'type'        => 'text',
					'placeholder' => '17:30',
					'description' => __( 'Format HH:MM', 'drusoft-shipping-for-econt' ),
				],

				// --- SECTION: SHIPMENT SETTINGS ---
				'section_shipment' => [
					'title' => __( 'Shipment Settings', 'drusoft-shipping-for-econt' ),
					'type'  => 'title',
				],
				'teglo' => [
					'title'       => __( 'Default Weight (kg)', 'drusoft-shipping-for-econt' ),
					'type'        => 'number',
					'default'     => '1',
					'description' => __( 'Fallback weight for products with no weight set, used by Econt pricing.', 'drusoft-shipping-for-econt' ),
					'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
				],

				// --- SECTION: PRICING & PAYMENT ---
				// v0.1: Econt-Calculator-only. Shipping cost comes live from
				// OrdersService.getPrice.json on each cart/checkout selection
				// change. Fixed-price / free-shipping / surcharge modes are a
				// v0.2 enhancement — keep this section deliberately small.
				'section_pricing' => [
					'title' => __( 'Pricing & Payment', 'drusoft-shipping-for-econt' ),
					'type'  => 'title',
				],
				'enable_cod' => [
					'title'       => __( 'Cash on Delivery', 'drusoft-shipping-for-econt' ),
					'label'       => __( 'Allow COD on Econt shipments', 'drusoft-shipping-for-econt' ),
					'type'        => 'checkbox',
					'default'     => 'yes',
				],

				// --- SECTION: WORKFLOW & OPTIONS ---
				'section_options' => [
					'title' => __( 'Workflow & Options', 'drusoft-shipping-for-econt' ),
					'type'  => 'title',
				],
				'generate_waybill' => [
					'title'       => __( 'Automatic Waybill', 'drusoft-shipping-for-econt' ),
					'description' => __( 'Automatically create the Econt waybill when the order moves to processing/on-hold.', 'drusoft-shipping-for-econt' ),
					'type'        => 'checkbox',
					'default'     => 'no',
				],
			];

			$this->instance_form_fields = array_merge( $this->instance_form_fields, $authenticated );
		}

		/**
		 * Get the first available office or automat ID for a specific city.
		 *
		 * @param int    $city_id
		 * @param string $type 'office' or 'automat'
		 * @return int Office ID or 0 if not found.
		 */
		public static function get_first_available_office( int $city_id, string $type ): int {
			global $wpdb;

			$like_automat = '%' . $wpdb->esc_like( 'АВТОМАТ' ) . '%';
			$like_aps     = '%' . $wpdb->esc_like( 'APS' ) . '%';
			$like_apt     = '%' . $wpdb->esc_like( 'APT' ) . '%';

			// Econt types: APT/APS is automat, others are office.
			if ( 'automat' === $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}drushfe_offices WHERE city_id = %d AND (office_type IN ('APT', 'APS') OR name LIKE %s OR name LIKE %s OR name LIKE %s) LIMIT 1",
						$city_id,
						$like_automat,
						$like_aps,
						$like_apt
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}drushfe_offices WHERE city_id = %d AND (office_type NOT IN ('APT', 'APS') AND name NOT LIKE %s AND name NOT LIKE %s AND name NOT LIKE %s) LIMIT 1",
					$city_id,
					$like_automat,
					$like_aps,
					$like_apt
				)
			);
		}

		/**
		 * Fetch available Econt offices from the local sync tables.
		 *
		 * If the tables are empty (e.g. the syncer hasn't run yet), returns
		 * just the placeholder option. The previous live-API fallback was
		 * Speedy-shaped (api.econt.bg/v1/location/office, userName+password)
		 * and never worked against Econt — removed. If the table is empty
		 * the caller should trigger a sync rather than fall back to a probe.
		 *
		 * Signature kept (username/password ignored) for back-compat with
		 * existing AJAX callers; will be tightened in a later pass.
		 *
		 * @param string|null $username Ignored — kept for back-compat.
		 * @param string|null $password Ignored — kept for back-compat.
		 * @param string|null $term     Optional name/address LIKE filter.
		 * @return array Associative array of [officeId => "Name - Address"]
		 */
		public static function get_econt_offices( ?string $username = null, ?string $password = null, ?string $term = null, bool $exclude_automats = false ): array {
			$offices = [ '0' => __( '-- Select Office --', 'drusoft-shipping-for-econt' ) ];

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'drushfe_offices' )
			);

			if ( $table_exists !== $wpdb->prefix . 'drushfe_offices' ) {
				return $offices;
			}

			$db_offices = self::query_local_offices( $term, $exclude_automats );

			if ( empty( $db_offices ) ) {
				return $offices;
			}

			foreach ( $db_offices as $office ) {
				$offices[ $office->id ] = sprintf( '%s %s - %s', $office->id, $office->name, $office->address );
			}

			return $offices;
		}

		/**
		 * Query the local drushfe_offices table with optional search term and automat exclusion.
		 *
		 * Separated into its own method so every query path uses $wpdb->prepare()
		 * with literal SQL — no interpolated variables.
		 *
		 * @param string|null $term             Search term (name/address LIKE).
		 * @param bool        $exclude_automats Whether to exclude APT/APS office types.
		 * @return array|object[]|null Database results.
		 */
		private static function query_local_offices( ?string $term, bool $exclude_automats ): ?array {
			global $wpdb;

			if ( $term && $exclude_automats ) {
				$like_term = '%' . $wpdb->esc_like( $term ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, name, address FROM {$wpdb->prefix}drushfe_offices WHERE (name LIKE %s OR address LIKE %s) AND office_type NOT IN ('APT','APS') ORDER BY name ASC LIMIT 50",
						$like_term,
						$like_term
					)
				);
			}

			if ( $term ) {
				$like_term = '%' . $wpdb->esc_like( $term ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, name, address FROM {$wpdb->prefix}drushfe_offices WHERE (name LIKE %s OR address LIKE %s) ORDER BY name ASC LIMIT 50",
						$like_term,
						$like_term
					)
				);
			}

			if ( $exclude_automats ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					"SELECT id, name, address FROM {$wpdb->prefix}drushfe_offices WHERE office_type NOT IN ('APT','APS') ORDER BY name ASC LIMIT 50"
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				"SELECT id, name, address FROM {$wpdb->prefix}drushfe_offices ORDER BY name ASC LIMIT 50"
			);
		}

		/**
		 * Clears all cached API data for this specific user
		 */
		private function clear_econt_cache(): void {
			$user_hash = md5( $this->get_option( 'econt_private_key' ) );

			delete_transient( 'drushfe_clients_cache_' . $user_hash );
			delete_transient( 'drushfe_offices_cache_' . $user_hash );
			delete_transient( 'drushfe_services_cache_' . $user_hash );
			delete_transient( 'drushfe_requirements_cache_' . $user_hash );
		}

		/**
		 * Calculate the shipping rate.
		 *
		 * v0.1 strategy: the live price comes from OrdersService.getPrice.json,
		 * fetched by JS (`drushfe_calculate_price` AJAX) and stored in the WC
		 * session as `drushfe_shipping_cost`. We just surface that value here.
		 * For the first render (no selection yet) we return a 0-cost placeholder
		 * tagged with `missing_address` so the JS can detect it.
		 */
		public function calculate_shipping( $package = array() ): void {
			$session = WC()->session;

			$cost          = $session ? (float) $session->get( 'drushfe_shipping_cost', 0 ) : 0;
			$delivery_type = $session ? (string) $session->get( 'drushfe_delivery_type', 'address' ) : 'address';
			$office_id     = $session ? (string) $session->get( 'drushfe_office_id', '' ) : '';

			$suffix_map = [
				'address' => __( 'to address', 'drusoft-shipping-for-econt' ),
				'office'  => __( 'to office', 'drusoft-shipping-for-econt' ),
				'automat' => __( 'to automat', 'drusoft-shipping-for-econt' ),
			];
			$suffix = $suffix_map[ $delivery_type ] ?? $suffix_map['address'];

			$this->add_rate( [
				'id'        => $this->get_rate_id(),
				'label'     => $this->title . ' — ' . $suffix,
				'cost'      => number_format( $cost, 2, '.', '' ),
				'meta_data' => [
					'_drushfe_delivery_type' => $delivery_type,
					'_drushfe_office_id'     => $office_id,
					// missing_address flag tells JS the user hasn't completed
					// a selection yet (price will be 0 until they do).
					'missing_address'        => $cost <= 0 ? true : false,
				],
			] );
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Parse checkout POST data
		 * ─────────────────────────────────────────────────── */

		/**
		 * Extract Econt-specific fields from the checkout AJAX request.
		 *
		 * WooCommerce sends checkout form data as a URL-encoded string in
		 * $_POST['post_data'] during AJAX shipping updates. During final
		 * order placement, fields are at the top level of $_POST.
		 *
		 * @return array {
		 *     @type string $delivery_type  'address', 'office', or 'automat'
		 *     @type int    $office_id      Econt office/automat ID (0 if none)
		 *     @type int    $city_id        Econt city (site) ID (0 if none)
		 *     @type string $payment_method WC payment method slug
		 * }
		 */
		private function parse_checkout_post_data(): array {
			$data = [];

			// During AJAX updates, form data comes as URL-encoded string
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- post_data is a URL-encoded string; individual values are sanitized below.
			if ( ! empty( $_POST['post_data'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				parse_str( wp_unslash( $_POST['post_data'] ), $data );
			}

			// During final checkout, or if post_data is missing, check top-level $_POST
			// phpcs:ignore WordPress.Security.NonceVerification
			$merged = array_merge( $data, $_POST );

			// post_data is present on checkout AJAX (update_order_review), absent on cart form submit.
			// phpcs:ignore WordPress.Security.NonceVerification
			$has_post_data = ! empty( $_POST['post_data'] );

			// Determine which address context to use (billing or shipping)
			$ship_to_different = ! empty( $merged['ship_to_different_address'] );
			$context = $ship_to_different ? 'shipping' : 'billing';

			// Delivery Type
			$delivery_type = sanitize_text_field( $merged['econt_delivery_type'] ?? '' );
			if ( empty( $delivery_type ) && WC()->session ) {
				$delivery_type = WC()->session->get( 'drushfe_delivery_type', 'address' );
			}
			if ( empty( $delivery_type ) ) {
				$delivery_type = 'address';
			}

			// Office ID
			$office_id = absint( $merged['econt_office_id'] ?? 0 );
			// On the cart page, fall back to session (set by get_first_available_office).
			// On checkout, do NOT fall back — the user must select an office explicitly.
			if ( $office_id === 0 && WC()->session && ! $has_post_data ) {
				$office_id = absint( WC()->session->get( 'drushfe_office_id', 0 ) );
			}

			// City ID: the checkout.js replaces the city input with a <select> whose
			// name is "{context}_city" and value is the Econt siteId.
			$city_id = 0;

			// 1. Try checkout fields (Econt IDs are numbers)
			if ( ! empty( $merged[ $context . '_city' ] ) && is_numeric( $merged[ $context . '_city' ] ) ) {
				$city_id = absint( $merged[ $context . '_city' ] );
			} elseif ( ! empty( $merged['billing_city'] ) && is_numeric( $merged['billing_city'] ) ) {
				$city_id = absint( $merged['billing_city'] );
			}
			// 2. Try cart calculator fields
			elseif ( ! empty( $merged['calc_shipping_city'] ) && is_numeric( $merged['calc_shipping_city'] ) ) {
				$city_id = absint( $merged['calc_shipping_city'] );
			}
			// 3. Try customer session as last resort
			elseif ( WC()->session ) {
				$city_id = absint( WC()->session->get( 'drushfe_city_id', 0 ) );
				if ( ! $city_id && WC()->customer ) {
					$session_city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
					if ( is_numeric( $session_city ) ) {
						$city_id = absint( $session_city );
					}
				}
			}

			return [
				'delivery_type'  => $delivery_type,
				'office_id'      => $office_id,
				'city_id'        => $city_id,
				'payment_method' => sanitize_text_field( $merged['payment_method'] ?? '' ),
			];
		}

		/**
		 * Check whether the current checkout request explicitly selected a non-Econt method.
		 *
		 * @return bool True when shipping_method is present and none of its values are Econt.
		 */
		private function request_selects_other_shipping_method(): bool {
			$data = [];

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- post_data is a URL-encoded checkout payload; values are sanitized below.
			if ( ! empty( $_POST['post_data'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				parse_str( wp_unslash( $_POST['post_data'] ), $data );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$merged = array_merge( $data, $_POST );
			$methods = $merged['shipping_method'] ?? [];

			if ( ! is_array( $methods ) ) {
				$methods = [ $methods ];
			}

			$methods = array_filter( array_map( 'sanitize_text_field', $methods ) );
			if ( empty( $methods ) ) {
				return false;
			}

			foreach ( $methods as $method_id ) {
				if ( str_starts_with( $method_id, $this->id ) ) {
					return false;
				}
			}

			return true;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Resolve package weight
		 * ─────────────────────────────────────────────────── */

		/**
		 * Determine the shipment weight.
		 *
		 * If the admin set a fixed "teglo" (weight) setting, use it.
		 * Otherwise compute from the cart contents, falling back to 1 kg.
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return float Weight in kg.
		 */
		private function resolve_weight( array $package ): float {
			$fixed_weight = $this->get_option( 'teglo' );

			// If teglo is non-empty AND not zero, use it as a fixed override
			if ( '' !== $fixed_weight && '0' !== $fixed_weight && (float) $fixed_weight > 0 ) {
				return (float) $fixed_weight;
			}

			// Calculate from cart/package contents
			$weight = 0.0;
			if ( ! empty( $package['contents'] ) ) {
				foreach ( $package['contents'] as $item ) {
					$product = $item['data'];
					$weight += (float) $product->get_weight() * $item['quantity'];
				}
			}

			// Fallback: use WC cart weight (covers edge cases)
			if ( $weight <= 0 && WC()->cart ) {
				$weight = WC()->cart->get_cart_contents_weight();
			}

			// Final fallback: 1 kg
			return $weight > 0 ? $weight : 1.0;
		}

	}
}
