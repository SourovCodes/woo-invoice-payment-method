<?php
/**
 * Tests for what an invoice order records and how it is shown.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Admin\OrderColumn;
use WooInvoicePayment\Checkout\OrderDetails;
use WooInvoicePayment\Checkout\OrderMeta;
use WooInvoicePayment\Gateway\InvoiceGateway;

/**
 * Covers the order meta, the overdue rule and the one list of rows every screen
 * showing an invoice is built from.
 */
class OrderTest extends TestCase {

	/**
	 * An invoice order with terms already stamped on it.
	 *
	 * @param string $due    Due date in Y-m-d.
	 * @param int    $days   Days the invoice was payable within.
	 * @param string $status Order status.
	 * @param string $method Payment method.
	 * @return \WC_Order The order.
	 */
	private function invoice_order( $due, $days = 30, $status = 'on-hold', $method = InvoiceGateway::ID ) {
		$organization = $this->make_organization();
		$order        = $this->make_order( $organization, 100.00, $status, $method );

		OrderMeta::stamp( $order, $days, $due, 'PO-9' );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * The payment method decides whether an order is an invoice, not our meta.
	 *
	 * An order can carry a purchase order reference and have been paid for by card.
	 */
	public function testThePaymentMethodDecidesWhatIsAnInvoice() {
		$invoice = $this->invoice_order( '2026-12-01' );
		$card    = $this->invoice_order( '2026-12-01', 30, 'on-hold', 'bacs' );

		$this->assertTrue( OrderMeta::is_invoice_order( $invoice ) );
		$this->assertFalse( OrderMeta::is_invoice_order( $card ) );
		$this->assertSame( array(), OrderDetails::rows( $card ) );
	}

	/**
	 * An unpaid invoice past its date is overdue.
	 */
	public function testAnUnpaidInvoicePastItsDateIsOverdue() {
		$order = $this->invoice_order( gmdate( 'Y-m-d', time() - ( 2 * DAY_IN_SECONDS ) ) );

		$this->assertTrue( OrderMeta::is_overdue( $order ) );
	}

	/**
	 * An invoice due today is not overdue until the day is out.
	 */
	public function testAnInvoiceDueTodayIsNotYetOverdue() {
		$order = $this->invoice_order( gmdate( 'Y-m-d' ) );

		$this->assertFalse( OrderMeta::is_overdue( $order ) );
	}

	/**
	 * A settled invoice cannot become overdue by the calendar moving on.
	 */
	public function testAPaidInvoiceIsNeverOverdue() {
		$order = $this->invoice_order( gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ) );

		$order->update_status( 'completed' );

		$this->assertFalse( OrderMeta::is_overdue( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * Nor can a cancelled one.
	 */
	public function testACancelledInvoiceIsNeverOverdue() {
		$order = $this->invoice_order( gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ), 30, 'cancelled' );

		$this->assertFalse( OrderMeta::is_overdue( $order ) );
	}

	/**
	 * The rows say what the order was sold on.
	 */
	public function testTheRowsReportTheTerms() {
		$order = $this->invoice_order( '2026-12-01', 30 );
		$rows  = OrderDetails::rows( $order );

		$this->assertContains( 'PO-9', $rows );
		$this->assertContains( '30 days', $rows );
		$this->assertNotEmpty( $rows[ __( 'Invoice due', 'woo-invoice-payment-method' ) ] );
	}

	/**
	 * Payable on receipt is said in words rather than as "0 days".
	 */
	public function testPayableOnReceiptIsSaidInWords() {
		$order = $this->invoice_order( gmdate( 'Y-m-d' ), 0 );
		$rows  = OrderDetails::rows( $order );

		$this->assertContains( 'Payable on receipt', $rows );
	}

	/**
	 * An overdue invoice says so wherever it is shown.
	 */
	public function testAnOverdueInvoiceIsMarkedInTheRows() {
		$order = $this->invoice_order( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$rows  = OrderDetails::rows( $order );

		$this->assertStringContainsString( 'overdue', $rows[ __( 'Invoice due', 'woo-invoice-payment-method' ) ] );
	}

	/**
	 * Every surface prints the same rows.
	 *
	 * Three near-identical lists drifting apart is how a shop ends up with a due date
	 * on the email that the customer's account page disagrees with.
	 */
	public function testEverySurfacePrintsTheSameRows() {
		$order   = $this->invoice_order( '2026-12-01' );
		$details = new OrderDetails();

		foreach ( array( 'render_order_details', 'render_admin_details' ) as $method ) {
			ob_start();
			$details->{$method}( $order );
			$rendered = (string) ob_get_clean();

			foreach ( OrderDetails::rows( $order ) as $label => $value ) {
				$this->assertStringContainsString( $label, $rendered, $method );
				$this->assertStringContainsString( $value, $rendered, $method );
			}
		}

		// The plain text email carries the same values without any markup.
		ob_start();
		$details->render_email_details( $order, false, true );
		$plain = (string) ob_get_clean();

		$this->assertStringContainsString( 'PO-9', $plain );
		$this->assertStringNotContainsString( '<', $plain );
	}

	/**
	 * A non-invoice order adds nothing to any screen.
	 */
	public function testANonInvoiceOrderPrintsNothing() {
		$order   = $this->invoice_order( '2026-12-01', 30, 'on-hold', 'bacs' );
		$details = new OrderDetails();

		ob_start();
		$details->render_order_details( $order );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * The orders screen can be searched by the customer's purchase order number.
	 *
	 * A reference the customer quotes in an email is only useful if somebody at the
	 * shop can type it into the orders screen and find the order.
	 */
	public function testOrdersAreSearchableByPurchaseOrderNumber() {
		$keys = ( new OrderColumn() )->add_search_key( array( '_billing_address_index' ) );

		$this->assertContains( OrderMeta::PURCHASE_ORDER, $keys );
		$this->assertContains( '_billing_address_index', $keys, 'WooCommerce\'s own keys were dropped.' );
		$this->assertSame( array_unique( $keys ), $keys );
	}

	/**
	 * The invoice column reports the due date, the payment, or nothing at all.
	 */
	public function testTheOrdersColumnReportsTheState() {
		$column = new OrderColumn();

		$this->assertArrayHasKey(
			'woip_invoice',
			$column->add_column(
				array(
					'order_number' => 'Order',
					'order_total'  => 'Total',
				)
			)
		);

		$overdue = $this->invoice_order( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );

		ob_start();
		$column->render_column( 'woip_invoice', $overdue );
		$this->assertStringContainsString( 'overdue', (string) ob_get_clean() );

		$other = $this->invoice_order( '2026-12-01', 30, 'on-hold', 'bacs' );

		ob_start();
		$column->render_column( 'woip_invoice', $other );
		$this->assertSame( '&mdash;', trim( (string) ob_get_clean() ) );
	}
}
