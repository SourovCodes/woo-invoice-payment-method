<?php
/**
 * Tests for the credit limit.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Credit;

/**
 * Covers what counts as outstanding, and what a limit refuses.
 *
 * The interesting cases are all about what *stops* counting: an order that was paid,
 * one that was cancelled, one that belongs to somebody else, and the order that is
 * being placed right now.
 */
class CreditTest extends TestCase {

	/**
	 * An account with no orders owes nothing.
	 */
	public function testAnAccountWithNoOrdersOwesNothing() {
		$organization = $this->make_organization();

		$this->assertSame( 0.0, Credit::outstanding( $organization->get_id() ) );
	}

	/**
	 * Unpaid invoice orders add up.
	 */
	public function testUnpaidInvoiceOrdersAddUp() {
		$organization = $this->make_organization();

		$this->make_order( $organization, 100.00 );
		$this->make_order( $organization, 250.50 );

		$this->assertSame( 350.50, Credit::outstanding( $organization->get_id() ) );
	}

	/**
	 * Another organization's invoices are not this one's problem.
	 */
	public function testAnotherAccountsInvoicesDoNotCount() {
		$mine   = $this->make_organization( array( 'name' => 'Mine GmbH' ) );
		$theirs = $this->make_organization( array( 'name' => 'Theirs GmbH' ) );

		$this->make_order( $mine, 100.00 );
		$this->make_order( $theirs, 900.00 );

		$this->assertSame( 100.0, Credit::outstanding( $mine->get_id() ) );
	}

	/**
	 * An order paid for by another method is not an invoice.
	 */
	public function testOrdersPaidByAnotherMethodDoNotCount() {
		$organization = $this->make_organization();

		$this->make_order( $organization, 100.00 );
		$this->make_order( $organization, 400.00, 'on-hold', 'bacs' );

		$this->assertSame( 100.0, Credit::outstanding( $organization->get_id() ) );
	}

	/**
	 * A paid invoice stops counting.
	 *
	 * WooCommerce stamps `date_paid` when an order reaches a paid status, and that is
	 * the plugin's only answer to "has this been paid?" — there is deliberately no
	 * second flag of our own that could disagree with it.
	 */
	public function testAPaidInvoiceStopsCounting() {
		$organization = $this->make_organization();
		$order        = $this->make_order( $organization, 500.00 );

		$this->assertSame( 500.0, Credit::outstanding( $organization->get_id() ) );

		$order->update_status( 'completed' );
		Credit::flush_cache();

		$this->assertNotNull( $order->get_date_paid(), 'WooCommerce did not record the payment.' );
		$this->assertSame( 0.0, Credit::outstanding( $organization->get_id() ) );
	}

	/**
	 * A cancelled or refunded invoice stops counting too.
	 */
	public function testCancelledAndRefundedInvoicesStopCounting() {
		$organization = $this->make_organization();

		$this->make_order( $organization, 100.00 );
		$this->make_order( $organization, 700.00, 'cancelled' );
		$this->make_order( $organization, 800.00, 'refunded' );

		$this->assertSame( 100.0, Credit::outstanding( $organization->get_id() ) );
	}

	/**
	 * An order can be left out of its own limit check.
	 *
	 * On the order-pay screen the order already exists and is already unpaid, so
	 * counting it would make every such order push itself over the limit.
	 */
	public function testAnOrderCanBeLeftOutOfTheSum() {
		$organization = $this->make_organization();
		$order        = $this->make_order( $organization, 300.00 );

		$this->assertSame( 300.0, Credit::outstanding( $organization->get_id() ) );
		$this->assertSame( 0.0, Credit::outstanding( $organization->get_id(), $order->get_id() ) );
	}

	/**
	 * Without a limit, any amount is allowed.
	 */
	public function testNoLimitAllowsAnything() {
		$organization = $this->make_organization();

		$this->make_order( $organization, 10000.00 );

		$this->assertNull( Credit::available( $organization->get_id() ) );
		$this->assertTrue( Credit::allows( $organization->get_id(), 99999.00 ) );
		$this->assertSame( '', Credit::blocked_reason( $organization->get_id(), 99999.00 ) );
	}

	/**
	 * A limit is what is left after everything already outstanding.
	 */
	public function testAvailableCreditIsTheLimitLessWhatIsOwed() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'credit_limit' => 1000 ) );
		$this->make_order( $organization, 400.00 );

		$this->assertSame( 600.0, Credit::available( $organization->get_id() ) );
		$this->assertTrue( Credit::allows( $organization->get_id(), 600.00 ) );
		$this->assertFalse( Credit::allows( $organization->get_id(), 600.01 ) );
	}

	/**
	 * An order for exactly the credit that is left is allowed.
	 *
	 * The comparison is rounded to the shop's own precision, because the float
	 * arithmetic behind one of the two totals is otherwise enough to refuse it.
	 */
	public function testAnExactMatchIsAllowed() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'credit_limit' => 100.10 ) );
		$this->make_order( $organization, 0.10 );

		$this->assertTrue( Credit::allows( $organization->get_id(), 100.00 ) );
	}

	/**
	 * A limit of zero is a real answer, and it is not "no limit".
	 */
	public function testALimitOfZeroRefusesEverything() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'credit_limit' => 0 ) );

		$this->assertSame( 0.0, Credit::available( $organization->get_id() ) );
		$this->assertFalse( Credit::allows( $organization->get_id(), 0.01 ) );
	}

	/**
	 * The refusal says what the customer needs in order to act on it.
	 *
	 * A blocked checkout that does not say what is left is a support ticket.
	 */
	public function testTheRefusalNamesTheAmounts() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'credit_limit' => 1000 ) );
		$this->make_order( $organization, 900.00 );

		$reason = Credit::blocked_reason( $organization->get_id(), 500.00 );

		$this->assertNotSame( '', $reason );
		$this->assertStringContainsString( '500', $reason );
		$this->assertStringContainsString( '100', $reason );

		// The formatted amounts are plain text, not the markup wc_price() returns:
		// whoever prints this escapes it, and the customer would read the span.
		$this->assertStringNotContainsString( '<', $reason );
	}

	/**
	 * When nothing is left at all, the message says so rather than offering zero.
	 */
	public function testAnExhaustedLimitSaysSo() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'credit_limit' => 500 ) );
		$this->make_order( $organization, 500.00 );

		$reason = Credit::blocked_reason( $organization->get_id(), 10.00 );

		$this->assertStringContainsString( 'already outstanding', $reason );
	}

	/**
	 * Every status that is neither paid nor dead counts as an unpaid invoice.
	 */
	public function testUnpaidStatusesAreDerivedRatherThanListed() {
		$statuses = Credit::unpaid_statuses();

		$this->assertContains( 'on-hold', $statuses );
		$this->assertContains( 'pending', $statuses );
		$this->assertNotContains( 'processing', $statuses );
		$this->assertNotContains( 'completed', $statuses );
		$this->assertNotContains( 'cancelled', $statuses );
		$this->assertNotContains( 'refunded', $statuses );
		$this->assertNotContains( 'failed', $statuses );
	}
}
