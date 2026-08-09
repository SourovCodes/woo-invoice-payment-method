<?php
/**
 * Tests for who is offered the payment method.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Eligibility;
use WooInvoicePayment\Gateway\Options;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

/**
 * Covers the two questions that decide whether the method is offered, and the order
 * they are asked in.
 */
class EligibilityTest extends TestCase {

	/**
	 * Nobody is offered the method while it is switched off.
	 */
	public function testTheMethodIsNotOfferedWhileDisabled() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_ALLOWED ) );
		$this->act_as( $member );

		$this->assertFalse( Eligibility::is_eligible() );
	}

	/**
	 * A logged-out visitor is offered nothing.
	 */
	public function testALoggedOutVisitorIsNotEligible() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_ALL );

		$this->assertSame( 0, Eligibility::organization_id() );
	}

	/**
	 * A user who belongs to no organization is offered nothing, whatever the setting.
	 *
	 * There is no organization to invoice, so there is nothing the method could bill.
	 */
	public function testAUserWithNoOrganizationIsNotEligible() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_ALL );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->flush();

		$this->assertSame( 0, Eligibility::organization_id() );
	}

	/**
	 * "Every organization" means every organization that could check out anyway.
	 */
	public function testEveryOrganizationIsEligibleUnderTheOpenSetting() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_ALL );

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->act_as( $member );

		$this->assertSame( $organization->get_id(), Eligibility::organization_id() );
	}

	/**
	 * Under the selective setting, nobody is eligible until they are allowed by name.
	 */
	public function testNobodyIsEligibleUnderTheSelectiveSettingUntilAllowed() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_SELECTED );

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->act_as( $member );
		$this->assertSame( 0, Eligibility::organization_id() );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_ALLOWED ) );

		$this->assertSame( $organization->get_id(), Eligibility::organization_id() );
	}

	/**
	 * A refusal outranks the open setting.
	 *
	 * This is why "follow the setting" has to be storable as its own answer: without
	 * it, refusing one customer and offering it to everyone else would be
	 * unrepresentable.
	 */
	public function testARefusalOutranksTheOpenSetting() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_ALL );

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_BLOCKED ) );
		$this->act_as( $member );

		$this->assertSame( 0, Eligibility::organization_id() );
	}

	/**
	 * An organization that cannot check out at all is not offered a way to check out.
	 *
	 * The organization plugin already blocks the whole checkout for a suspended
	 * account. Asking it here as well is what stops the invoice method being the last
	 * one standing on a checkout that should not be happening — which would let a
	 * suspended customer take goods away without paying.
	 */
	public function testASuspendedOrganizationIsNotEligible() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_ALL );

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_ALLOWED ) );
		$this->act_as( $member );
		$this->assertSame( $organization->get_id(), Eligibility::organization_id() );

		OrganizationRepository::set_status( $organization->get_id(), Organization::STATUS_SUSPENDED );
		$this->flush();

		$this->assertSame( 0, Eligibility::organization_id(), 'A suspended account was still offered invoicing.' );
	}

	/**
	 * A member whose own membership was switched off is not eligible either.
	 */
	public function testAnInactiveMemberIsNotEligible() {
		$this->enable_gateway();
		$this->set_option( 'availability', Options::AVAILABILITY_ALL );

		$organization = $this->make_organization();
		$member       = $this->make_member( $organization, Member::ROLE_MEMBER, array( 'status' => Member::STATUS_INACTIVE ) );

		$this->act_as( $member );

		$this->assertSame( 0, Eligibility::organization_id() );
	}

	/**
	 * The gateway's default terms apply until an organization is given its own.
	 */
	public function testTermsFallBackToTheGatewayDefault() {
		$organization = $this->make_organization();

		$this->set_option( 'payment_terms_days', 21 );

		$this->assertSame( 21, Eligibility::payment_terms_days( $organization->get_id() ) );

		$this->set_terms( $organization, array( 'payment_terms_days' => 7 ) );

		$this->assertSame( 7, Eligibility::payment_terms_days( $organization->get_id() ) );
	}

	/**
	 * Agreed terms of zero days are honoured rather than read as "no answer".
	 */
	public function testZeroDaysIsAnAgreedTermNotAnAbsentOne() {
		$organization = $this->make_organization();

		$this->set_option( 'payment_terms_days', 30 );
		$this->set_terms( $organization, array( 'payment_terms_days' => 0 ) );

		$this->assertSame( 0, Eligibility::payment_terms_days( $organization->get_id() ) );
		$this->assertSame(
			gmdate( 'Y-m-d' ),
			Eligibility::due_date( $organization->get_id() ),
			'Payable on receipt should fall due today.'
		);
	}

	/**
	 * The due date is the order date plus the agreed days.
	 */
	public function testTheDueDateCountsForwardFromTheOrder() {
		$organization = $this->make_organization();
		$placed       = strtotime( '2026-01-15 12:00:00 UTC' );

		$this->set_terms( $organization, array( 'payment_terms_days' => 30 ) );

		$this->assertSame( '2026-02-14', Eligibility::due_date( $organization->get_id(), $placed ) );
	}

	/**
	 * The terms are summarised in a sentence that copes with one day and with none.
	 */
	public function testTheTermsSummaryReadsAsASentence() {
		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'payment_terms_days' => 1 ) );
		$this->assertStringContainsString( '1 day', Eligibility::terms_summary( $organization->get_id() ) );

		$this->set_terms( $organization, array( 'payment_terms_days' => 14 ) );
		$this->assertStringContainsString( '14 days', Eligibility::terms_summary( $organization->get_id() ) );

		$this->set_terms( $organization, array( 'payment_terms_days' => 0 ) );
		$this->assertStringContainsString( 'on receipt', Eligibility::terms_summary( $organization->get_id() ) );
	}
}
