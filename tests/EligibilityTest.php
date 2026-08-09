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
}
