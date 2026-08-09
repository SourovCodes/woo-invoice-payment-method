<?php
/**
 * Tests for the payment method itself.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Gateway\InvoiceGateway;
use WooInvoicePayment\Gateway\Options;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

/**
 * Covers the gateway as WooCommerce sees it, and process_payment() as the one place
 * every rule is enforced.
 *
 * That method gets the attention because it is the only point both checkouts pass
 * through: the Store API never calls validate_fields(), so a rule enforced only there
 * would be a rule the block checkout does not have.
 */
class GatewayTest extends TestCase {

	/**
	 * A signed-in member of an organization allowed to pay by invoice.
	 *
	 * @param array $terms Terms to store for the organization.
	 * @return Organization The organization.
	 */
	private function signed_in_buyer( array $terms = array() ) {
		$this->enable_gateway();

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms( $organization, array_merge( array( 'status' => Terms::STATUS_ALLOWED ), $terms ) );
		$this->act_as( $member );

		return $organization;
	}

	/**
	 * An order for an organization, in the state the checkout hands to a gateway.
	 *
	 * @param Organization $organization Organization the order is for.
	 * @param float        $total        Order total.
	 * @return \WC_Order The order.
	 */
	private function pending_order( Organization $organization, $total ) {
		$order = $this->make_order( $organization, $total, 'pending' );

		$order->set_customer_id( get_current_user_id() );
		$order->save();

		return $order;
	}

	/**
	 * WooCommerce knows about the gateway.
	 */
	public function testTheGatewayIsRegistered() {
		$gateways = WC()->payment_gateways()->payment_gateways();

		$this->assertArrayHasKey( InvoiceGateway::ID, $gateways );
		$this->assertInstanceOf( InvoiceGateway::class, $gateways[ InvoiceGateway::ID ] );
	}

	/**
	 * Every settings field declares the default the rest of the plugin assumes.
	 *
	 * The gateway object does not exist on `plugins_loaded` and does not exist at all
	 * during an uninstall, so the settings are read straight from the option elsewhere.
	 * A default typed twice would mean one thing before the settings were first saved
	 * and another afterwards.
	 */
	public function testTheFormDefaultsAreTheOptionDefaults() {
		$gateway = new InvoiceGateway();

		foreach ( Options::defaults() as $key => $default ) {
			$this->assertArrayHasKey( $key, $gateway->form_fields, $key . ' has no field.' );
			$this->assertSame(
				(string) $default,
				(string) $gateway->form_fields[ $key ]['default'],
				$key . ' defaults differently on the form.'
			);
		}
	}

	/**
	 * The method is offered to an allowed organization and to nobody else.
	 */
	public function testAvailabilityFollowsEligibility() {
		$organization = $this->signed_in_buyer();

		$this->assertTrue( ( new InvoiceGateway() )->is_available() );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_BLOCKED ) );

		$this->assertFalse( ( new InvoiceGateway() )->is_available() );
	}

	/**
	 * A cart bigger than the credit limit does not hide the method.
	 *
	 * Availability is about the customer. A payment method that vanished when somebody
	 * added one more case to their cart would read as a bug in the shop, so the limit
	 * is enforced when the order is placed — and explained in so many words.
	 */
	public function testTheCreditLimitDoesNotHideTheMethod() {
		$organization = $this->signed_in_buyer( array( 'credit_limit' => 10 ) );

		$this->make_order( $organization, 10.00 );

		$this->assertTrue( ( new InvoiceGateway() )->is_available() );
	}

	/**
	 * A successful payment parks the order on the gateway without paying for it.
	 */
	public function testPlacingAnOrderParksItUnpaid() {
		$organization = $this->signed_in_buyer();
		$order        = $this->pending_order( $organization, 120.00 );

		$result = ( new InvoiceGateway() )->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertNotEmpty( $result['redirect'] );

		$stored = wc_get_order( $order->get_id() );

		$this->assertSame( InvoiceGateway::ID, $stored->get_payment_method() );
		$this->assertTrue( $stored->has_status( 'on-hold' ) );
		$this->assertNull( $stored->get_date_paid(), 'Nothing has been paid yet.' );
	}

	/**
	 * Choosing Processing tells WooCommerce the order is paid, which is what it means.
	 */
	public function testTheProcessingSettingMarksTheOrderPaid() {
		$organization = $this->signed_in_buyer();
		$order        = $this->pending_order( $organization, 75.00 );

		$this->set_option( 'order_status', 'processing' );

		( new InvoiceGateway() )->process_payment( $order->get_id() );

		$stored = wc_get_order( $order->get_id() );

		$this->assertNotNull( $stored->get_date_paid() );
	}

	/**
	 * An order over the credit limit is refused, with a message that says why.
	 */
	public function testAnOrderOverTheLimitIsRefused() {
		$organization = $this->signed_in_buyer( array( 'credit_limit' => 100 ) );

		$this->make_order( $organization, 90.00 );

		$order = $this->pending_order( $organization, 50.00 );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/cannot go on invoice/' );

		( new InvoiceGateway() )->process_payment( $order->get_id() );
	}

	/**
	 * An order for exactly the credit that is left goes through.
	 */
	public function testAnOrderInsideTheLimitIsAccepted() {
		$organization = $this->signed_in_buyer( array( 'credit_limit' => 100 ) );

		$this->make_order( $organization, 40.00 );

		$order  = $this->pending_order( $organization, 60.00 );
		$result = ( new InvoiceGateway() )->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
	}

	/**
	 * An organization refused invoicing between rendering and submitting is refused.
	 *
	 * This is the case validate_fields() cannot cover on the block checkout, and the
	 * reason every rule is asked again here.
	 */
	public function testAnOrganizationRefusedInTheMeantimeIsStopped() {
		$organization = $this->signed_in_buyer();
		$order        = $this->pending_order( $organization, 30.00 );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_BLOCKED ) );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/not available on this account/' );

		( new InvoiceGateway() )->process_payment( $order->get_id() );
	}

	/**
	 * An organization suspended between rendering and submitting is refused too.
	 */
	public function testASuspendedOrganizationIsStopped() {
		$organization = $this->signed_in_buyer();
		$order        = $this->pending_order( $organization, 30.00 );

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_SUSPENDED );
		$this->flush();

		$this->expectException( \Exception::class );

		( new InvoiceGateway() )->process_payment( $order->get_id() );
	}

	/**
	 * The checkout tells the customer what credit is left.
	 */
	public function testTheCheckoutStatesTheCreditLeft() {
		$this->signed_in_buyer( array( 'credit_limit' => 1000 ) );

		ob_start();
		( new InvoiceGateway() )->payment_fields();

		$this->assertStringContainsString( 'Credit still available', (string) ob_get_clean() );
	}

	/**
	 * The instructions reach the customer's emails and not the shop's.
	 *
	 * An admin email saying "please pay this invoice" is addressed to the wrong person.
	 */
	public function testTheInstructionsGoToTheCustomerOnly() {
		$organization = $this->signed_in_buyer();
		$order        = $this->pending_order( $organization, 30.00 );

		( new InvoiceGateway() )->process_payment( $order->get_id() );

		$stored  = wc_get_order( $order->get_id() );
		$gateway = new InvoiceGateway();

		ob_start();
		$gateway->email_instructions( $stored, false, false );
		$this->assertNotSame( '', trim( (string) ob_get_clean() ) );

		ob_start();
		$gateway->email_instructions( $stored, true, false );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * An order paid for by another method gets no invoice instructions.
	 */
	public function testAnotherMethodsOrderGetsNoInstructions() {
		$organization = $this->signed_in_buyer();
		$order        = $this->make_order( $organization, 30.00, 'on-hold', 'bacs' );

		ob_start();
		( new InvoiceGateway() )->email_instructions( $order, false, false );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}
}
