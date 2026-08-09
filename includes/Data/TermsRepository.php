<?php
/**
 * Reads and writes the invoice terms table.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Data;

use WooInvoicePayment\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Every line of SQL in this plugin lives here.
 *
 * Keeping it to one class is what makes "the terms are always looked up by
 * organization" readable rather than hoped for: there is no method that returns a
 * row without being told whose row it is, and the one that returns many takes a list
 * of IDs the caller has already resolved.
 */
final class TermsRepository {

	/**
	 * Terms already loaded this request, keyed by organization ID.
	 *
	 * The gateway asks whether it is available several times per checkout render —
	 * once for the cart totals, once for the payment method list, once more when the
	 * chosen method is validated — and each of those would otherwise be a query.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * The prefixed table name.
	 *
	 * @return string Table name.
	 */
	public static function table() {
		return Install::table( Install::TERMS );
	}

	/**
	 * The terms for one organization.
	 *
	 * An organization that has never been decided about gets an all-default Terms
	 * rather than null, so every caller has an object to ask. "No row" and "a row
	 * saying nothing" are the same answer, and this is where they become the same
	 * value.
	 *
	 * @param int $organization_id Organization ID.
	 * @return Terms The terms, defaulted when there is no row.
	 */
	public static function find( $organization_id ) {
		global $wpdb;

		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return new Terms();
		}

		if ( array_key_exists( $organization_id, self::$memo ) ) {
			return self::$memo[ $organization_id ];
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE organization_id = %d", $organization_id ), ARRAY_A );

		self::$memo[ $organization_id ] = new Terms( $organization_id, $row );

		return self::$memo[ $organization_id ];
	}

	/**
	 * The terms for several organizations at once.
	 *
	 * One query for a screenful of organizations rather than one per row. Every ID
	 * asked about comes back, defaulted when it has no row, so the caller never has to
	 * decide what an absent key means.
	 *
	 * @param int[] $organization_ids Organization IDs.
	 * @return Terms[] Map of organization ID to terms.
	 */
	public static function find_many( array $organization_ids ) {
		global $wpdb;

		$organization_ids = array_values( array_unique( array_filter( array_map( 'absint', $organization_ids ) ) ) );

		if ( empty( $organization_ids ) ) {
			return array();
		}

		$found        = array_fill_keys( $organization_ids, null );
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $organization_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name from a class constant; $placeholders is a list of %d built from the count of IDs, one per value bound below, which the sniff cannot see through the interpolation.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE organization_id IN ({$placeholders})", $organization_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$id = (int) $row['organization_id'];

			$found[ $id ]      = new Terms( $id, $row );
			self::$memo[ $id ] = $found[ $id ];
		}

		foreach ( $found as $id => $terms ) {
			if ( null === $terms ) {
				$found[ $id ] = new Terms( $id );
			}
		}

		return $found;
	}

	/**
	 * Every organization ID that has been decided about, by status.
	 *
	 * Used by the admin screen to say how many customers each answer applies to
	 * without loading a row per organization.
	 *
	 * @return array Map of status to count.
	 */
	public static function counts_by_status() {
		global $wpdb;

		$counts = array_fill_keys( array_keys( Terms::statuses() ), 0 );
		$table  = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the query takes no values.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];

			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Store one organization's terms.
	 *
	 * A set of terms that says nothing is deleted rather than written, because a row
	 * of defaults and no row at all mean the same thing and keeping both would leave
	 * the admin screen reporting a decision nobody made.
	 *
	 * @param Terms $terms Terms to persist.
	 * @return bool True when the write succeeded.
	 */
	public static function save( Terms $terms ) {
		global $wpdb;

		$organization_id = $terms->get_organization_id();

		if ( 0 === $organization_id ) {
			return false;
		}

		self::flush_cache();

		if ( $terms->is_default() ) {
			return self::delete( $organization_id );
		}

		$data = $terms->to_array();

		$data['date_modified']   = current_time( 'mysql', true );
		$data['organization_id'] = $organization_id;

		/*
		 * REPLACE rather than an insert-or-update pair. The primary key is the
		 * organization, so there is exactly one row to replace, and doing it in one
		 * statement means two simultaneous saves cannot both decide there is no row yet.
		 *
		 * $wpdb has no format specifier for a null value, so a null column is left out
		 * of the statement altogether and takes its column default, which is null. That
		 * is why the specifiers are collected while walking $data rather than declared
		 * as a list: they have to arrive in the same order as the columns that survived.
		 */
		$types   = array(
			'organization_id'    => '%d',
			'status'             => '%s',
			'payment_terms_days' => '%d',
			'credit_limit'       => '%f',
			'note'               => '%s',
			'date_modified'      => '%s',
		);
		$formats = array();

		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				unset( $data[ $column ] );
				continue;
			}

			$formats[] = $types[ $column ];
		}

		return false !== $wpdb->replace( self::table(), $data, $formats );
	}

	/**
	 * Forget one organization's terms.
	 *
	 * @param int $organization_id Organization ID.
	 * @return bool True when the delete succeeded.
	 */
	public static function delete( $organization_id ) {
		global $wpdb;

		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return false;
		}

		self::flush_cache();

		return false !== $wpdb->delete( self::table(), array( 'organization_id' => $organization_id ), array( '%d' ) );
	}

	/**
	 * Forget everything loaded this request.
	 *
	 * Needed by the test suite, and by any code that changes the terms and then has to
	 * read them back within the same request.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$memo = array();
	}
}
