<?php
/**
 * What an invoice order records about itself.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Checkout;

use WooInvoicePayment\Gateway\InvoiceGateway;

defined( 'ABSPATH' ) || exit;

/**
 * The meta this plugin writes onto an order, and the only place that reads it back.
 *
 * The terms and the due date are **snapshots**, and deliberately so. They are what
 * this order was placed on, not what the organization's terms happen to say today:
 * shortening a customer's terms next month must not retrospectively make an invoice
 * from last month overdue, and an order is a financial record of an agreement that
 * was in force when it was made.
 *
 * The number of days is stored beside the date it produced even though one can be
 * derived from the other, because neither derivation is safe on its own. Reading the
 * days back off the current terms would be the retrospective edit above; recomputing
 * the date from the order date and the current terms would be the same edit wearing
 * arithmetic.
 */
final class OrderMeta {

	/**
	 * How many days the invoice was payable within, as agreed at checkout.
	 */
	const PAYMENT_TERMS_DAYS = '_woip_payment_terms_days';

	/**
	 * The date the invoice falls due, in Y-m-d.
	 */
	const DUE_DATE = '_woip_due_date';

	/**
	 * Record the invoice terms on an order.
	 *
	 * @param \WC_Order $order      The order.
	 * @param int       $terms_days Days the invoice is payable within.
	 * @param string    $due_date   Due date in Y-m-d.
	 * @return void
	 */
	public static function stamp( \WC_Order $order, $terms_days, $due_date ) {
		$order->update_meta_data( self::PAYMENT_TERMS_DAYS, absint( $terms_days ) );
		$order->update_meta_data( self::DUE_DATE, (string) $due_date );
	}

	/**
	 * Whether an order was placed on invoice.
	 *
	 * The payment method is the authority, not the presence of our meta: an order can
	 * carry a due date from a method it was later moved off.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool True when this gateway took the order.
	 */
	public static function is_invoice_order( \WC_Order $order ) {
		return InvoiceGateway::ID === $order->get_payment_method();
	}

	/**
	 * How many days the invoice was payable within.
	 *
	 * @param \WC_Order $order The order.
	 * @return int Days.
	 */
	public static function payment_terms_days( \WC_Order $order ) {
		return (int) $order->get_meta( self::PAYMENT_TERMS_DAYS );
	}

	/**
	 * The date the invoice falls due.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Date in Y-m-d, or an empty string.
	 */
	public static function due_date( \WC_Order $order ) {
		return (string) $order->get_meta( self::DUE_DATE );
	}

	/**
	 * The due date in the site's own date format.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Formatted date, or an empty string when there is none.
	 */
	public static function formatted_due_date( \WC_Order $order ) {
		$due = self::due_date( $order );

		if ( '' === $due ) {
			return '';
		}

		$timestamp = strtotime( $due . ' 00:00:00 UTC' );

		return false === $timestamp ? $due : wp_date( (string) get_option( 'date_format' ), $timestamp, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether an unpaid invoice is past its due date.
	 *
	 * Answered against the order rather than against the organization's current terms,
	 * and only for an order that has not been paid: a settled invoice cannot become
	 * overdue by the calendar moving on.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool True when the invoice is unpaid and its due date has passed.
	 */
	public static function is_overdue( \WC_Order $order ) {
		$due = self::due_date( $order );

		if ( '' === $due || ! self::is_invoice_order( $order ) ) {
			return false;
		}

		if ( null !== $order->get_date_paid() || $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return false;
		}

		$deadline = strtotime( $due . ' 23:59:59 UTC' );

		return false !== $deadline && time() > $deadline;
	}
}
