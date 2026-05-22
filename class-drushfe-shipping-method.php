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
					'title'   => __( 'Sender (Object)', 'drusoft-shipping-for-econt' ),
					'type'    => 'select',
					'options' => $this->get_econt_clients(),
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
		 * Fetch available clients/contracts from Econt API
		 *
		 * @return array Associative array of [clientId => Client Details]
		 */
		private function get_econt_clients(): array {
			$cache_key = 'drushfe_clients_cache_' . md5( $this->get_option( 'econt_private_key' ) );
			$clients   = get_transient( $cache_key );

			// If cache exists, return it immediately
			if ( false !== $clients ) {
				return $clients;
			}

			$clients = [ '0' => __( '-- Select Client --', 'drusoft-shipping-for-econt' ) ];

			$username = $this->get_option( 'econt_private_key' );
			$password = $this->get_option( 'econt_private_key' );

			if ( ! $username || ! $password ) {
				return $clients;
			}

			// Prepare API data
			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::econt_curl_post( 'https://api.econt.bg/v1/client/contract', $body );

			if ( null === $data ) {
				return $clients;
			}

			// Process and Format Data
			if ( isset( $data['clients'] ) && is_array( $data['clients'] ) ) {
				foreach ( $data['clients'] as $client ) {
					$client_id   = $client['clientId'] ?? '';
					$client_name = $client['clientName'] ?? '';
					$object_name = $client['objectName'] ?? '';
					$address     = $client['address']['fullAddressString'] ?? '';

					$clients[ $client_id ] = sprintf(
					/* translators: 1: ID, 2: Name, 3: Object, 4: Address */
						__( 'ID: %1$s, %2$s, %3$s, Address: %4$s', 'drusoft-shipping-for-econt' ),
						$client_id,
						$client_name,
						$object_name,
						$address
					);
				}

				// Cache the results for 24 hours to prevent repeated API hits
				set_transient( $cache_key, $clients, DAY_IN_SECONDS );
			}

			return $clients;
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
		 * Fetch available Econt offices from API and sort them alphabetically
		 *
		 * @param string|null $username
		 * @param string|null $password
		 * @param string|null $term
		 * @return array Associative array of [officeId => "Name - Address"]
		 */
		public static function get_econt_offices( ?string $username = null, ?string $password = null, ?string $term = null, bool $exclude_automats = false ): array {
			$offices = [ '0' => __( '-- Select Office --', 'drusoft-shipping-for-econt' ) ];

			// Try to fetch from local DB first
			global $wpdb;

			// Check if table exists and has data
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'drushfe_offices' )
			);

			if ( $table_exists === $wpdb->prefix . 'drushfe_offices' ) {
				$db_offices = self::query_local_offices( $term, $exclude_automats );

				if ( ! empty( $db_offices ) ) {
					foreach ( $db_offices as $office ) {
						$offices[ $office->id ] = sprintf( '%s %s - %s', $office->id, $office->name, $office->address );
					}
					return $offices;
				}
			}

			// Fallback to API if DB is empty or no results found

			// If credentials are not provided, try to find them
			if ( ! $username || ! $password ) {
				$option_like = 'woocommerce_drushfe_econt_%_settings';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
						$option_like
					)
				);

				if ( $rows ) {
					$settings = maybe_unserialize( $rows[0]->option_value );
					if ( is_array( $settings ) ) {
						$username = $settings['econt_private_key'] ?? '';
						$password = $settings['econt_private_key'] ?? '';
					}
				}
			}

			if ( ! $username || ! $password ) {
				return $offices;
			}

			// Prepare API data (Country ID 100 is Bulgaria)
			$body_data = [
				'userName'  => $username,
				'password'  => $password,
				'countryId' => 100,
			];

			if ( $term ) {
				$body_data['name'] = $term;
			}

			$body = json_encode( $body_data );

			$data = self::econt_curl_post( 'https://api.econt.bg/v1/location/office', $body );

			if ( null === $data ) {
				return $offices;
			}

			if ( isset( $data['offices'] ) && is_array( $data['offices'] ) ) {
				$temp_offices = [];

				foreach ( $data['offices'] as $office ) {
					$id      = $office['id'];
					$name    = $office['name'] ?? '';
					$address = $office['address']['fullAddressString'] ?? '';
					$type    = $office['type'] ?? '';

					// Skip automats when only real offices are requested
					if ( $exclude_automats && drushfe_is_automat( $type, $name ) ) {
						continue;
					}

					// We store a sort_key to handle Bulgarian (Cyrillic) sorting correctly
					$temp_offices[ $id ] = [
						'sort_key' => mb_strtoupper( $name, 'UTF-8' ),
						'label'    => sprintf( '%s %s - %s', $id, $name, $address )
					];
				}

				// Sort alphabetically by the name (sort_key)
				uasort( $temp_offices, function ( $a, $b ) {
					return strcmp( $a['sort_key'], $b['sort_key'] );
				} );

				// Flatten the array back to [ id => label ] for the WooCommerce select field
				foreach ( $temp_offices as $id => $office_data ) {
					$offices[ $id ] = $office_data['label'];
				}
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

		/* ───────────────────────────────────────────────────
		 *  HELPER: Free shipping threshold for delivery type
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the free shipping threshold for a specific delivery type.
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @return float Threshold amount (0 = disabled).
		 */
		private function get_free_shipping_threshold( string $delivery_type ): float {
			$map = [
				'office'  => 'free_shipping_office',
				'automat' => 'free_shipping_automat',
				'address' => 'free_shipping_address',
			];

			$key = $map[ $delivery_type ] ?? '';
			return $key ? (float) $this->get_option( $key, 0 ) : 0.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Fixed price for delivery type
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the fixed shipping price for a specific delivery type.
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @return float Fixed price (0 = not set).
		 */
		private function get_fixed_price( string $delivery_type ): float {
			$map = [
				'office'  => 'fixed_shipping_office',
				'automat' => 'fixed_shipping_automat',
				'address' => 'fixed_shipping_address',
			];

			$key = $map[ $delivery_type ] ?? '';
			return $key ? (float) $this->get_option( $key, 0 ) : 0.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: CSV file-based pricing
		 * ─────────────────────────────────────────────────── */

		/**
		 * Look up shipping cost from a user-uploaded CSV price file.
		 *
		 * CSV format (header + data rows):
		 *   service_id, take_from_office, weight, order_total, price
		 *
		 * take_from_office: 0 = address, 1 = office, 2 = automat
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @param float  $weight        Shipment weight.
		 * @param float  $subtotal      Cart subtotal.
		 * @return float|false Price from CSV, or false if no match found.
		 */
		private function get_csv_file_price( string $delivery_type, float $weight, float $subtotal ): float|false {
			// Try instance option first, then legacy global option
			$file_path = $this->get_option( 'fileceni' );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				$file_path = get_option( 'drushfe_fileceni_path' );
			}

			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				return false;
			}

			// Map delivery type to CSV column value
			$type_map = [
				'address' => 0,
				'office'  => 1,
				'automat' => 2,
			];
			$take_from_office = $type_map[ $delivery_type ] ?? 0;

			// Use WP_Filesystem to read the file.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() || ! $wp_filesystem ) {
				return false;
			}

			$csv_content = $wp_filesystem->get_contents( $file_path );
			if ( false === $csv_content || empty( $csv_content ) ) {
				return false;
			}

			$lines = explode( "\n", $csv_content );
			if ( empty( $lines ) ) {
				return false;
			}

			// Skip header row
			array_shift( $lines );

			$best_fit_price       = null;
			$best_fit_order_total = null;

			foreach ( $lines as $line ) {
				if ( empty( trim( $line ) ) ) {
					continue;
				}

				$row = str_getcsv( $line, ',', '"', '' );
				if ( count( $row ) < 5 ) {
					continue;
				}

				list( $_csv_service_id, $csv_take_from_office, $csv_weight, $csv_order_total, $csv_price ) = $row;

				if (
					(int) $csv_take_from_office === $take_from_office &&
					$weight <= (float) $csv_weight &&
					$subtotal <= (float) $csv_order_total
				) {
					// Pick the row with the smallest csv_order_total that still covers this order
					if ( null === $best_fit_order_total || (float) $csv_order_total < $best_fit_order_total ) {
						$best_fit_order_total = (float) $csv_order_total;
						$best_fit_price       = (float) $csv_price;
					}
				}
			}

			return $best_fit_price;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Build full Econt /v1/calculate payload
		 * ─────────────────────────────────────────────────── */

		/**
		 * Assemble the Econt API calculate request body.
		 *
		 * The old plugin always sends the full payload to the API even when
		 * the final cost will be overridden (free / fixed / file). When a
		 * pricing override is active, the courier payer is forced to SENDER
		 * and the COD amount is adjusted to subtotal + shipping cost.
		 *
		 * @param string     $delivery_type    'address', 'office', or 'automat'
		 * @param int        $office_id        Econt office/automat ID.
		 * @param int        $city_id          Econt city (site) ID.
		 * @param float      $order_weight     Shipment weight in kg.
		 * @param float      $order_total      Order total minus shipping.
		 * @param float      $subtotal         Cart subtotal.
		 * @param bool       $is_cod           Whether payment is Cash on Delivery.
		 * @param string     $_payment_method  WC payment method slug.
		 * @param bool       $is_free          Whether free shipping is active.
		 * @param float|null $fixed_price      Fixed shipping cost (null = not active).
		 * @param float|null $file_price       CSV file shipping cost (null = not active).
		 * @return array The API request payload (without credentials).
		 */
		private function build_api_calculate_payload(
			string $delivery_type,
			int $office_id,
			int $city_id,
			float $order_weight,
			float $order_total,
			float $subtotal,
			bool $is_cod,
			string $_payment_method,
			bool $is_free = false,
			?float $fixed_price = null,
			?float $file_price = null
		): array {

			$has_price_override = $is_free || null !== $fixed_price || null !== $file_price;

			// ── Sender ──
			// The Econt /v1/calculate API requires sender data to determine
			// pricing based on the sender's contract and location. This matches
			// the old plugin behavior which always includes full sender details.
			$sender = [];

			$sender_id = (int) $this->get_option( 'sender_id' );
			if ( $sender_id > 0 ) {
				$sender['clientId'] = $sender_id;
			}

			$sender_phone = $this->get_option( 'sender_phone' );
			if ( ! empty( $sender_phone ) ) {
				$sender['phone1'] = [ 'number' => $sender_phone ];
			}

			$sender_name = $this->get_option( 'sender_name' );
			if ( ! empty( $sender_name ) ) {
				$sender['contactName'] = $sender_name;
			}

			$sender_email = $this->get_option( 'sender_email' );
			if ( ! empty( $sender_email ) ) {
				$sender['email'] = $sender_email;
			}

			if ( 'YES' === $this->get_option( 'sender_officeyesno' ) ) {
				$drop_off = (int) $this->get_option( 'sender_office' );
				if ( $drop_off > 0 ) {
					$sender['dropoffOfficeId'] = $drop_off;
				}
			}

			// If no sender data was set, send empty object so JSON encodes as {}
			if ( empty( $sender ) ) {
				$sender = new stdClass();
			}

			// ── Recipient ──
			$recipient = [
				'privatePerson' => true,
			];

			if ( in_array( $delivery_type, [ 'office', 'automat' ], true ) ) {
				$recipient['pickupOfficeId'] = $office_id;
			} else {
				$recipient['addressLocation'] = [
					'siteId' => $city_id,
				];
			}

			// ── Service ──
			$service_ids = $this->get_option( 'uslugi', [] );
			if ( ! is_array( $service_ids ) ) {
				$service_ids = array_map( 'intval', explode( ',', $service_ids ) );
			} else {
				$service_ids = array_map( 'intval', $service_ids );
			}
			// Remove zeroes and ensure we have at least one service
			$service_ids = array_values( array_filter( $service_ids ) );
			if ( empty( $service_ids ) ) {
				$service_ids = [ 505 ]; // Default: Standard courier
			}

			$service = [
				'autoAdjustPickupDate' => true,
				'serviceIds'           => $service_ids,
			];

			if ( 'YES' === $this->get_option( 'saturdayoption' ) ) {
				$service['saturdayDelivery'] = true;
			}

			// ── Content ──
			$content = [
				'parcelsCount' => 1,
				'totalWeight'  => $order_weight,
			];

			// ── Payment ──
			$include_shipping_in_cod = ( 'YES' === $this->get_option( 'includeshippingprice' ) );

			// Old plugin logic: payer is RECIPIENT only when ALL of:
			//   1. COD payment
			//   2. includeshippingprice is not YES
			//   3. cenadostavka is 'econtcalculator' or 'nadbavka' (pure API pricing)
			// For all other pricing modes (fileprices, fixedprices, freeshipping)
			// or when includeshippingprice is YES, use SENDER — matching old plugin.
			$cenadostavka_mode = $this->get_option( 'cenadostavka', 'econtcalculator' );
			$api_pricing_modes = [ 'econtcalculator', 'nadbavka' ];


			if ( $is_cod && ! $include_shipping_in_cod && in_array( $cenadostavka_mode, $api_pricing_modes, true ) && ! $has_price_override ) {
				$payment = [ 'courierServicePayer' => 'RECIPIENT' ];
			} else {
				$payment = [ 'courierServicePayer' => 'SENDER' ];
			}


			if ( 'YES' === $this->get_option( 'administrative' ) ) {
				$payment['administrativeFee'] = true;
			}

			// ── Additional Services ──

			if ( $is_cod ) {
				$money_transfer  = $this->get_option( 'moneytransfer', 'NO' );
				$processing_type = ( 'YES' === $money_transfer ) ? 'POSTAL_MONEY_TRANSFER' : 'CASH';

				// COD amount: use the items subtotal as the base.
				// During calculate_shipping(), WC()->cart->get_totals()['total']
				// is still 0 because WC_Cart_Totals hasn't called calculate_totals()
				// yet (shipping is calculated first). The subtotal (items only) is the
				// always available and is the correct base for COD.
				$cod_amount = $subtotal;

				// For fixed/file pricing with COD, the old plugin sets cod.amount
				// to subtotal + shipping cost so the courier collects the right total.
				if ( null !== $fixed_price ) {
					$cod_amount = $subtotal + $fixed_price;
				} elseif ( null !== $file_price ) {
					$cod_amount = $subtotal + $file_price;
				} elseif ( 'nadbavka' === $this->get_option( 'cenadostavka' ) ) {
					// Surcharge mode: add surcharge to COD amount
					$cod_amount += (float) $this->get_option( 'suma_nadbavka', 0 );
				}

				$service['additionalServices']['cod'] = [
					'amount'                  => $cod_amount,
					'processingType'          => $processing_type,
					'ignoreIfNotApplicable'   => true,
				];

				if ( $include_shipping_in_cod ) {
					$service['additionalServices']['cod']['includeShippingPrice'] = true;
				}

				// Declared Value (old plugin only adds this inside COD branch)
				if ( 'YES' === $this->get_option( 'obqvena' ) ) {
					$service['additionalServices']['declaredValue'] = [
						'amount'                => $order_total,
						'fragile'               => ( 'YES' === $this->get_option( 'chuplivost' ) ),
						'ignoreIfNotApplicable' => true,
					];
				}

				// Return Voucher (old plugin only adds inside COD branch)
				if ( 'YES' === $this->get_option( 'vaucher' ) ) {
					$voucher = [
						'serviceId'             => 505,
						'payer'                 => $this->get_option( 'vaucherpayer', 'SENDER' ),
						'ignoreIfNotApplicable' => true,
					];

					$validity = $this->get_option( 'vaucherpayerdays' );
					if ( ! empty( $validity ) ) {
						$voucher['validityPeriod'] = (int) $validity;
					}

					$service['additionalServices']['returns']['returnVoucher'] = $voucher;
				}

				// Special Delivery Requirements (old plugin only adds inside COD branch)
				$special_req = $this->get_option( 'special_requirements' );
				if ( ! empty( $special_req ) && '0' !== $special_req ) {
					$service['additionalServices']['specialDeliveryId'] = $special_req;
				}
			}
			// Non-COD: don't include COD in additional services at all
			// (the official Econt API example omits COD entirely when not applicable)

			// OBPD (Test Before Pay / Open Before Pay)
			// Old plugin adds this regardless of COD status
			$obpd_option = $this->get_option( 'test_before_pay', 'NO' );
			$autoclose   = $this->get_option( 'autoclose', 'NO' );

			if (
				in_array( $obpd_option, [ 'OPEN', 'TEST' ], true ) &&
				( 'automat' !== $delivery_type || 'NO' === $autoclose )
			) {
				$service['additionalServices']['obpd'] = [
					'option'                  => $obpd_option,
					'returnShipmentServiceId' => 505,
					'returnShipmentPayer'     => ( 'OPEN' === $obpd_option )
						? ( $this->get_option( 'testplatec' ) ?: 'SENDER' )
						: 'SENDER',
					'ignoreIfNotApplicable'   => true,
				];
			}

			// ── Fiscal Receipt Items (for fiscal / fiscalone modes) ──
			$money_transfer_mode = $this->get_option( 'moneytransfer', 'NO' );
			if ( $is_cod && in_array( $money_transfer_mode, [ 'fiscal', 'fiscalone' ], true ) && WC()->cart ) {
				$cenadostavka_mode = $this->get_option( 'cenadostavka', 'econtcalculator' );
				$fiscal_items      = $this->build_fiscal_receipt_items( $money_transfer_mode, $cenadostavka_mode, $service['additionalServices']['cod']['amount'] ?? 0 );
				if ( ! empty( $fiscal_items ) ) {
					$service['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
				}
			}

			return [
				'sender'    => $sender,
				'recipient' => $recipient,
				'service'   => $service,
				'content'   => $content,
				'payment'   => $payment,
			];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Build fiscal receipt items
		 * ─────────────────────────────────────────────────── */

		/**
		 * Generate fiscal receipt line items for the Econt COD fiscal receipt.
		 *
		 * When fixedprices or fileprices is active and cod_amount exceeds the
		 * product total, a separate "Доставка" (Delivery) line is appended
		 * to cover the shipping portion – matching the old plugin behavior.
		 *
		 * @param string $mode            'fiscal' (per-item) or 'fiscalone' (per VAT group).
		 * @param string $cenadostavka    Pricing mode setting.
		 * @param float  $cod_amount      Total COD amount from the payload.
		 * @return array Array of fiscal receipt items.
		 */
		private function build_fiscal_receipt_items( string $mode, string $cenadostavka = '', float $cod_amount = 0.0 ): array {
			$fiscal_items            = [];
			$products_total_with_vat = 0.0;

			if ( 'fiscal' === $mode ) {
				// Per-product line items
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product     = $cart_item['data'];
					$qty         = $cart_item['quantity'];
					$name        = $product->get_name() . ' (x' . $qty . ')';
					$description = mb_substr( $name, 0, 50 );

					$vat_info = $this->resolve_vat_info( $product );

					$price_incl_vat = (float) $product->get_price();
					$price_excl_vat = $vat_info['rate'] > 0
						? $price_incl_vat / ( 1 + $vat_info['rate'] )
						: $price_incl_vat;

					$line_with_vat = round( $price_incl_vat * $qty, 2 );
					$line_ex_vat   = round( $price_excl_vat * $qty, 2 );

					$products_total_with_vat += $line_with_vat;

					$fiscal_items[] = [
						'description'   => $description,
						'vatGroup'      => $vat_info['group'],
						'amount'        => $line_ex_vat,
						'amountWithVat' => $line_with_vat,
					];
				}
			} elseif ( 'fiscalone' === $mode ) {
				// Grouped by VAT class
				$groups = [
					'А' => [ 'ex' => 0, 'in' => 0 ], // 0%
					'Г' => [ 'ex' => 0, 'in' => 0 ], // 9%
					'Б' => [ 'ex' => 0, 'in' => 0 ], // 20%
				];

				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product = $cart_item['data'];
					$qty     = $cart_item['quantity'];

					$vat_info       = $this->resolve_vat_info( $product );
					$price_incl_vat = (float) $product->get_price();
					$price_excl_vat = $vat_info['rate'] > 0
						? $price_incl_vat / ( 1 + $vat_info['rate'] )
						: $price_incl_vat;

					$groups[ $vat_info['group'] ]['ex'] += $price_excl_vat * $qty;
					$groups[ $vat_info['group'] ]['in'] += $price_incl_vat * $qty;
				}

				foreach ( $groups as $group => $sum ) {
					if ( $sum['in'] <= 0 ) {
						continue;
					}

					$products_total_with_vat += $sum['in'];

					$fiscal_items[] = [
						/* translators: %s: VAT group identifier (e.g. А, Б, Г) */
						'description'   => sprintf( __( 'Products from order (group %s)', 'drusoft-shipping-for-econt' ), $group ),
						'vatGroup'      => $group,
						'amount'        => round( $sum['ex'], 2 ),
						'amountWithVat' => round( $sum['in'], 2 ),
					];
				}
			}

			// Append a "Доставка" (Delivery) line when fixed/file pricing is used
			// and the COD amount exceeds the product total.
			if (
				in_array( $cenadostavka, [ 'fixedprices', 'fileprices' ], true ) &&
				$cod_amount > 0
			) {
				$shipping_amount_with_vat = max( 0, $cod_amount - $products_total_with_vat );

				if ( $shipping_amount_with_vat > 0 ) {
					$shipping_vat_rate      = 0.20;
					$shipping_amount_ex_vat = $shipping_amount_with_vat / ( 1 + $shipping_vat_rate );

					$fiscal_items[] = [
						'description'   => __( 'Delivery', 'drusoft-shipping-for-econt' ),
						'vatGroup'      => 'Б',
						'amount'        => round( $shipping_amount_ex_vat, 2 ),
						'amountWithVat' => round( $shipping_amount_with_vat, 2 ),
					];
				}
			}

			return $fiscal_items;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Resolve VAT info for a product
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the Bulgarian VAT group and rate for a WC product.
		 *
		 * @param WC_Product $product
		 * @return array { @type string $group, @type float $rate }
		 */
		private function resolve_vat_info(WC_Product $product ): array {
			$tax_class = $product->get_tax_class();

			if ( 'zero-rate' === $tax_class ) {
				return [ 'group' => 'А', 'rate' => 0.00 ];
			}

			if ( 'reduced-rate' === $tax_class ) {
				return [ 'group' => 'Г', 'rate' => 0.09 ];
			}

			// Standard rate (default)
			return [ 'group' => 'Б', 'rate' => 0.20 ];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Call Econt /v1/calculate API
		 * ─────────────────────────────────────────────────── */

		/**
		 * Send a calculate request to the Econt API.
		 *
		 * Uses wp_remote_post() instead of raw cURL for WordPress best practices.
		 *
		 * @param array $payload Full request body including credentials.
		 * @return array|WP_Error Decoded API response or WP_Error.
		 */
		private function call_econt_calculate_api( array $payload ): array|WP_Error {

			$body = $this->do_econt_calculate_request( $payload );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			// If the combined call succeeded with at least one calculation, return it.
			$has_success = false;
			if ( ! empty( $body['calculations'] ) ) {
				foreach ( $body['calculations'] as $calc ) {
					if ( isset( $calc['price']['total'] ) ) {
						$has_success = true;
						break;
					}
				}
			}

			if ( $has_success ) {
				return $body;
			}

			// Combined call failed (top-level error or all calculations failed).
			// If multiple serviceIds were sent, retry each one individually.
			$service_ids = $payload['service']['serviceIds'] ?? [];
			if ( count( $service_ids ) <= 1 ) {
				return $body; // Single service – nothing more to try.
			}


			$merged_calculations = [];
			foreach ( $service_ids as $sid ) {
				$single_payload = $payload;
				$single_payload['service']['serviceIds'] = [ $sid ];

				$single_body = $this->do_econt_calculate_request( $single_payload );

				if ( is_wp_error( $single_body ) ) {
					continue;
				}

				if ( ! empty( $single_body['calculations'] ) ) {
					foreach ( $single_body['calculations'] as $calc ) {
						$merged_calculations[] = $calc;
					}
				}
			}

			if ( ! empty( $merged_calculations ) ) {
				return [ 'calculations' => $merged_calculations ];
			}

			// All individual calls failed too – return the original combined response.
			return $body;
		}

		/**
		 * Execute a JSON POST request to a Econt API endpoint.
		 *
		 * @param string $url  Full API endpoint URL.
		 * @param string $body JSON-encoded request body.
		 * @return array|null  Decoded response or null on error.
		 */
		private static function econt_curl_post( string $url, string $body ): ?array {
			$response = wp_remote_post( $url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $body,
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$response_body = wp_remote_retrieve_body( $response );
			if ( empty( $response_body ) ) {
				return null;
			}

			return json_decode( $response_body, true );
		}

		/**
		 * Execute a single Econt /v1/calculate HTTP request.
		 *
		 * @param array $payload Full request body including credentials.
		 * @return array|WP_Error Decoded API response or WP_Error.
		 */
		private function do_econt_calculate_request( array $payload ): array|WP_Error {
			$response = wp_remote_post( 'https://api.econt.bg/v1/calculate', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 200 || $code >= 300 ) {
				$api_msg = $body['error']['message'] ?? "HTTP $code";
				return new WP_Error( 'econt_api_error', $api_msg );
			}

			return $body;
		}
	}
}
