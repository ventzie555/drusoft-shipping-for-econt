<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin activation.
 */
class Drushfe_Activator {

	public static function activate(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		// Cities Table
		$table_cities = $wpdb->prefix . 'drushfe_cities';
		$sql_cities = "CREATE TABLE $table_cities (
			id mediumint(9) UNSIGNED NOT NULL,
			name varchar(255) NULL,
			post_code varchar(255) NULL,
			region varchar(255) NULL,
			type varchar(10) NULL,
			PRIMARY KEY  (id),
			KEY name_index (name)
		) $charset_collate;";

		dbDelta( $sql_cities );

		// Offices Table
		// NOTE: Econt office identifiers (`code`) are alphanumeric strings, not integers
		// like Speedy's office IDs. Stored as varchar; downstream queries must use %s.
		$table_offices = $wpdb->prefix . 'drushfe_offices';
		$sql_offices = "CREATE TABLE $table_offices (
			id varchar(32) NOT NULL,
			name varchar(512) NULL,
			city_id mediumint(9) UNSIGNED NULL,
			office_type varchar(32) NULL,
			city varchar(255) NULL,
			address varchar(512) NULL,
			latitude varchar(32) NULL,
			longitude varchar(32) NULL,
			post_code varchar(16) NULL,
			address_details text NULL,
			office_details text NULL,
			phone varchar(255) NULL,
			email varchar(255) NULL,
			PRIMARY KEY  (id),
			KEY city_id_index (city_id)
		) $charset_collate;";

		dbDelta( $sql_offices );
	}

	public static function deactivate(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drushfe_cities" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drushfe_offices" );
	}
}
