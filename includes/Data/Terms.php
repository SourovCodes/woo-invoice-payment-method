<?php
/**
 * One organization's invoice terms.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Data;

defined( 'ABSPATH' ) || exit;

/**
 * What the shop decided about one organization's invoicing.
 *
 * Two answers, and each has an explicit "no answer of its own" state rather than a
 * value that has to be read as one:
 *
 * - `status` says whether this organization may pay by invoice. STATUS_DEFAULT means
 *   "whatever the gateway's own availability setting says", which is a different
 *   answer from STATUS_BLOCKED and has to stay tellable apart from it — a shop that
 *   offers invoicing to everyone and a shop that refused this one customer are not the
 *   same shop.
 * - `credit_limit` is null when there is no limit. Zero is a real answer meaning no
 *   credit at all, which is why "unlimited" cannot be stored as zero.
 *
 * An organization with no row at all reads as an all-default Terms, so every caller
 * gets an object to ask rather than a null to check.
 */
class Terms {

	/**
	 * Follow the gateway's own availability setting.
	 */
	const STATUS_DEFAULT = 'default';

	/**
	 * May pay by invoice, whatever the gateway's availability setting says.
	 */
	const STATUS_ALLOWED = 'allowed';

	/**
	 * May not pay by invoice, whatever the gateway's availability setting says.
	 */
	const STATUS_BLOCKED = 'blocked';

	/**
	 * The organization these terms belong to.
	 *
	 * @var int
	 */
	private $organization_id = 0;

	/**
	 * Column values, keyed by column name.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Every storable column and the value a new row starts with.
	 *
	 * @return array Map of column name to default value.
	 */
	public static function defaults() {
		return array(
			'status'        => self::STATUS_DEFAULT,
			'credit_limit'  => null,
			'note'          => '',
			'date_modified' => null,
		);
	}

	/**
	 * Every status with a translated label.
	 *
	 * @return array Map of status to label.
	 */
	public static function statuses() {
		return array(
			self::STATUS_DEFAULT => __( 'Follow the payment method setting', 'woo-invoice-payment-method' ),
			self::STATUS_ALLOWED => __( 'Always allowed', 'woo-invoice-payment-method' ),
			self::STATUS_BLOCKED => __( 'Never allowed', 'woo-invoice-payment-method' ),
		);
	}

	/**
	 * Build the terms, optionally from a database row.
	 *
	 * @param int               $organization_id Organization the terms belong to.
	 * @param array|object|null $row             Row as returned by $wpdb, or null for defaults.
	 */
	public function __construct( $organization_id = 0, $row = null ) {
		$this->organization_id = absint( $organization_id );
		$this->data            = self::defaults();

		if ( null === $row ) {
			return;
		}

		$row = (array) $row;

		if ( isset( $row['organization_id'] ) ) {
			$this->organization_id = (int) $row['organization_id'];
		}

		foreach ( array_keys( $this->data ) as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$this->set( $key, $row[ $key ] );
			}
		}
	}

	/**
	 * The organization these terms belong to.
	 *
	 * @return int Organization ID.
	 */
	public function get_organization_id() {
		return $this->organization_id;
	}

	/**
	 * Read a column.
	 *
	 * @param string $key Column name.
	 * @return mixed Column value.
	 */
	public function get( $key ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : null;
	}

	/**
	 * Write a column.
	 *
	 * Anything that is not a declared column is ignored rather than stored, so a stray
	 * form field can never reach the database as a column nobody expected. The nullable
	 * column keeps null as null and casts everything else, because null is the value
	 * that carries meaning here and `(float) null` would silently turn "no limit" into
	 * "no credit".
	 *
	 * @param string $key   Column name.
	 * @param mixed  $value Value.
	 * @return $this
	 */
	public function set( $key, $value ) {
		if ( ! array_key_exists( $key, $this->data ) ) {
			return $this;
		}

		if ( 'credit_limit' === $key ) {
			$this->data[ $key ] = ( null === $value || '' === $value ) ? null : (float) $value;

			return $this;
		}

		if ( 'status' === $key ) {
			$value = sanitize_key( $value );

			$this->data[ $key ] = array_key_exists( $value, self::statuses() ) ? $value : self::STATUS_DEFAULT;

			return $this;
		}

		$this->data[ $key ] = ( null === $value ) ? null : (string) $value;

		return $this;
	}

	/**
	 * Write several columns at once.
	 *
	 * @param array $props Map of column name to value.
	 * @return $this
	 */
	public function set_props( array $props ) {
		foreach ( $props as $key => $value ) {
			$this->set( $key, $value );
		}

		return $this;
	}

	/**
	 * The stored status.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function get_status() {
		return (string) $this->data['status'];
	}

	/**
	 * The stored status, translated.
	 *
	 * @return string Label.
	 */
	public function get_status_label() {
		$statuses = self::statuses();
		$status   = $this->get_status();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * How much this organization may owe at once, or null when it is not limited.
	 *
	 * @return float|null Amount in the shop's currency, or null.
	 */
	public function get_credit_limit() {
		return $this->data['credit_limit'];
	}

	/**
	 * Whether a credit limit applies at all.
	 *
	 * @return bool True when a limit is set.
	 */
	public function has_credit_limit() {
		return null !== $this->data['credit_limit'];
	}

	/**
	 * The shop's own note about this arrangement.
	 *
	 * @return string Note.
	 */
	public function get_note() {
		return (string) $this->data['note'];
	}

	/**
	 * Whether anything has been decided about this organization.
	 *
	 * An all-default row says the same as no row at all, which is what lets the admin
	 * screen delete a row rather than store a set of answers that answer nothing.
	 *
	 * @return bool True when every column still holds its default.
	 */
	public function is_default() {
		return self::STATUS_DEFAULT === $this->get_status()
			&& null === $this->get_credit_limit()
			&& '' === $this->get_note();
	}

	/**
	 * The storable columns and their current values.
	 *
	 * @return array Map of column name to value.
	 */
	public function to_array() {
		return $this->data;
	}
}
