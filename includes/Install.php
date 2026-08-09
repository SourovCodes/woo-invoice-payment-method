<?php
/**
 * Database schema.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

use WooInvoicePayment\Gateway\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's own table.
 *
 * One table, holding what the shop decided about one organization's invoicing: may it
 * buy on invoice at all, and up to how much. It is a side table on
 * `woap_organizations` rather than more columns in it, because the organization plugin
 * owns that schema and a plugin that can be deactivated must not leave columns behind
 * in somebody else's table.
 *
 * `organization_id` is the primary key, so "one organization has one set of invoice
 * terms" is a rule the database keeps rather than one every write has to remember —
 * the same reason `woap_members.user_id` is UNIQUE next door. An organization with no
 * row has not been decided about, which is a different thing from having been decided
 * about and refused; Data\Terms::STATUS_DEFAULT is what an absent row means.
 */
final class Install {

	/**
	 * Unprefixed name of the invoice terms table.
	 */
	const TERMS = 'woip_invoice_terms';

	/**
	 * Option holding the schema version the table was last built at.
	 */
	const VERSION_OPTION = 'woo_invoice_payment_db_version';

	/**
	 * Resolve a table name to its site-prefixed form.
	 *
	 * Every query in the plugin goes through this rather than interpolating
	 * `$wpdb->prefix` at the call site, so a table name is never assembled from
	 * anything but one of the constants above.
	 *
	 * @param string $table One of the class constants.
	 * @return string Prefixed table name.
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Every table the plugin owns.
	 *
	 * @return string[] Unprefixed table names.
	 */
	public static function tables() {
		return array( self::TERMS );
	}

	/**
	 * Bring the database up to the shipped schema and record the version.
	 *
	 * Idempotent: dbDelta() only issues the statements needed to reach the described
	 * schema, and the two clean-up passes below both check before they write, so running
	 * this against an up-to-date database does nothing.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}

		self::drop_removed_columns();
		self::drop_retired_settings();

		update_option( self::VERSION_OPTION, WOIP_DB_VERSION, false );
	}

	/**
	 * Forget the gateway settings the plugin no longer has a field for.
	 *
	 * WC_Settings_API writes the whole settings array back on every save, and a key it
	 * has no form field for is carried along untouched rather than dropped. A retired
	 * setting therefore outlives its field with its last value intact, which is a
	 * decision the shop can no longer see or change — and one that would come back to
	 * life if a later release ever reused the name.
	 *
	 * @return void
	 */
	private static function drop_retired_settings() {
		$settings = get_option( Options::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$retired = array_intersect( array( 'payment_terms_days' ), array_keys( $settings ) );

		if ( empty( $retired ) ) {
			return;
		}

		update_option( Options::OPTION_KEY, array_diff_key( $settings, array_flip( $retired ) ) );
	}

	/**
	 * Drop the columns the plugin used to have.
	 *
	 * Columns are added and widened by dbDelta() and never removed by it, so one left
	 * out of schema() simply stays on every table that already has it, holding data
	 * nothing reads and nothing maintains. This is the other half of removing a column,
	 * and it runs after the dbDelta pass above, which is what guarantees the table is
	 * there to alter.
	 *
	 * @return void
	 */
	private static function drop_removed_columns() {
		global $wpdb;

		$table = self::table( self::TERMS );

		/*
		 * `payment_terms_days` went with the payment terms themselves. Invoices are
		 * raised in the shop's own accounting system, which is where a due date belongs;
		 * this plugin decides who may buy on invoice and up to how much.
		 */
		foreach ( array( 'payment_terms_days' ) as $column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the column is a placeholder.
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

			if ( null === $found ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Neither a table nor a column name can be a placeholder; both come from constants in this file.
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
		}
	}

	/**
	 * Run the installer again when the shipped schema is newer than the database.
	 *
	 * Activation is not enough on its own: WordPress updates a plugin by unpacking
	 * the new files over the old ones without deactivating it, so a release that adds
	 * a column would otherwise reach a live site with the old table still in place.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( WOIP_DB_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether every table the plugin needs exists.
	 *
	 * @return bool True when all tables are present.
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$name = self::table( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from a class constant, and SHOW TABLES takes no placeholders.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop every table the plugin owns.
	 *
	 * Only ever called from uninstall.php, and only when the site asked for its data
	 * to be removed.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$name = self::table( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A table name cannot be a placeholder; this one is built from a class constant and $wpdb->prefix.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * The CREATE TABLE statements, one per table.
	 *
	 * Written in the shape dbDelta() insists on — one column per line, two spaces
	 * after PRIMARY KEY, KEY rather than INDEX — because it parses these strings
	 * rather than executing them and quietly does nothing when the format slips.
	 *
	 * Every datetime is nullable rather than defaulting to the zero date, which MySQL
	 * 8 rejects under its default strict SQL mode.
	 *
	 * @return string[] SQL statements.
	 */
	public static function schema() {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$terms   = self::table( self::TERMS );
		$schema  = array();

		/*
		 * `credit_limit` is nullable, and NULL is not zero here: it means "no limit",
		 * where zero is the real answer of no credit at all. A column that could not
		 * tell the two apart would make "unlimited" indistinguishable from "nothing".
		 *
		 * The money column is decimal rather than float, for the reason every money
		 * column is: a credit limit compared against an order total must not be off by
		 * a binary rounding error in either direction.
		 */
		$schema[] = "CREATE TABLE {$terms} (
			organization_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL default 'default',
			credit_limit decimal(19,4) NULL default null,
			note varchar(500) NOT NULL default '',
			date_modified datetime NULL default null,
			PRIMARY KEY  (organization_id),
			KEY status (status)
		) {$collate};";

		return $schema;
	}
}
