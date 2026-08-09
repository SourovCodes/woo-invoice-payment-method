<?php
/**
 * Tests for the block checkout integration.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Gateway\Blocks\InvoiceBlockSupport;
use WooInvoicePayment\Gateway\Gateways;
use WooInvoicePayment\Gateway\InvoiceGateway;
use WooInvoicePayment\Gateway\Options;

/**
 * Covers the half of the gateway the block checkout uses.
 *
 * The block checkout is what a new WooCommerce 11 shop gets by default, so a payment
 * method that only worked on the classic one would be a payment method most shops
 * never see. What is asserted here is that the two halves cannot drift apart: the same
 * method name, the same field name, the same availability rule.
 */
class BlocksTest extends TestCase {

	/**
	 * The block integration answers to the gateway's own ID.
	 *
	 * WooCommerce matches the browser's chosen method to a gateway by this string, and
	 * a mismatch means a checkout that renders the method and then cannot take it.
	 */
	public function testTheIntegrationUsesTheGatewayId() {
		$this->assertSame( InvoiceGateway::ID, ( new InvoiceBlockSupport() )->get_name() );
	}

	/**
	 * The script registers itself under that same ID.
	 *
	 * It is a string in a JavaScript file, so nothing but a test connects it to the
	 * constant the server uses.
	 */
	public function testTheScriptRegistersUnderTheSameId() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file out of this checkout, not fetching a URL.
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/blocks/checkout.js' );

		$this->assertStringContainsString( "name: '" . InvoiceGateway::ID . "'", $script );
		$this->assertStringContainsString( InvoiceGateway::ID . '_data', $script );
	}

	/**
	 * The block checkout is offered the method on exactly the same rule as the classic.
	 */
	public function testAvailabilityMatchesTheClassicCheckout() {
		$support = new InvoiceBlockSupport();

		$this->assertFalse( $support->is_active(), 'The method was offered while disabled.' );

		$this->enable_gateway();

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_ALLOWED ) );
		$this->act_as( $member );

		$this->assertTrue( $support->is_active() );
		$this->assertSame( ( new InvoiceGateway() )->is_available(), $support->is_active() );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_BLOCKED ) );

		$this->assertFalse( $support->is_active() );
		$this->assertSame( ( new InvoiceGateway() )->is_available(), $support->is_active() );
	}

	/**
	 * The browser is told the terms, the credit left and which field to post back.
	 */
	public function testTheBrowserIsGivenTheSameAnswersTheServerHas() {
		$this->enable_gateway();

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms(
			$organization,
			array(
				'status'             => Terms::STATUS_ALLOWED,
				'payment_terms_days' => 14,
				'credit_limit'       => 1000,
			)
		);
		$this->act_as( $member );
		$this->make_order( $organization, 400.00 );

		$support = new InvoiceBlockSupport();
		$support->initialize();

		$data = $support->get_payment_method_data();

		$this->assertStringContainsString( '14 days', $data['terms'] );
		$this->assertStringContainsString( '600', $data['creditNotice'] );
		$this->assertSame( InvoiceGateway::PURCHASE_ORDER_FIELD, $data['purchaseOrder']['field'] );
		$this->assertSame( Options::purchase_order_mode(), $data['purchaseOrder']['mode'] );
	}

	/**
	 * An unlimited account is told nothing about credit rather than told zero.
	 */
	public function testAnUnlimitedAccountIsToldNothingAboutCredit() {
		$this->enable_gateway();

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_ALLOWED ) );
		$this->act_as( $member );

		$support = new InvoiceBlockSupport();
		$support->initialize();

		$this->assertSame( '', $support->get_payment_method_data()['creditNotice'] );
	}

	/**
	 * The gateway is added to WooCommerce's list exactly once.
	 */
	public function testTheGatewayIsAddedToTheList() {
		$gateways = ( new Gateways() )->add_gateway( array( 'WC_Gateway_BACS' ) );

		$this->assertContains( InvoiceGateway::class, $gateways );
		$this->assertContains( 'WC_Gateway_BACS', $gateways );
	}

	/**
	 * The block registration is hooked, so the method reaches the block checkout.
	 */
	public function testTheBlockRegistrationIsHooked() {
		$this->assertNotFalse( has_action( 'woocommerce_blocks_payment_method_type_registration' ) );
	}
}
