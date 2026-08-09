<?php
/**
 * Shared test case.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Credit;
use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Data\TermsRepository;
use WooInvoicePayment\Gateway\InvoiceGateway;
use WooInvoicePayment\Gateway\Options;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Roles;

/**
 * Base class for the plugin's tests.
 *
 * Carries the factory helpers every suite needs and, more importantly, resets the
 * per-request memos between tests — this plugin's and the organization plugin's. Both
 * cache deliberately for the life of a request, and a test run is one long request as
 * far as PHP is concerned, so without this the second test in a file sees the first
 * one's answers.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset every request-scoped memo before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->flush();
		wp_set_current_user( 0 );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		update_option( Options::OPTION_KEY, Options::defaults(), false );
	}

	/**
	 * Reset it again afterwards, so a failing test cannot leak into the next file.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->flush();
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Forget everything both plugins remember for the length of a request.
	 *
	 * @return void
	 */
	protected function flush() {
		Context::flush();
		TermsRepository::flush_cache();
		Credit::flush_cache();
	}

	/**
	 * Replace one gateway setting for the duration of a test.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value New value.
	 * @return void
	 */
	protected function set_option( $key, $value ) {
		$settings         = Options::all();
		$settings[ $key ] = $value;

		update_option( Options::OPTION_KEY, $settings, false );
	}

	/**
	 * Switch the payment method on, which no test gets for free.
	 *
	 * @return void
	 */
	protected function enable_gateway() {
		$this->set_option( 'enabled', 'yes' );
	}

	/**
	 * Create an organization.
	 *
	 * @param array $props Column values to override.
	 * @return Organization The saved organization.
	 */
	protected function make_organization( array $props = array() ) {
		$organization = new Organization();

		$organization->set_props(
			array_merge(
				array(
					'name'   => 'Acme GmbH',
					'email'  => 'buy@acme.test',
					'status' => Organization::STATUS_ACTIVE,
				),
				$props
			)
		);

		$organization->set_billing_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Byron',
				'company'    => 'Acme GmbH',
				'address_1'  => '1 Hauptstrasse',
				'city'       => 'Berlin',
				'postcode'   => '10115',
				'country'    => 'DE',
				'email'      => 'buy@acme.test',
			)
		);

		OrganizationRepository::save( $organization );

		return $organization;
	}

	/**
	 * Create a user and make them a member of an organization.
	 *
	 * @param Organization $organization Organization to join.
	 * @param string       $role         One of the Member::ROLE_* constants.
	 * @param array        $props        Member column values to override.
	 * @return Member The saved membership.
	 */
	protected function make_member( Organization $organization, $role = Member::ROLE_ADMIN, array $props = array() ) {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::wordpress_role( $role ),
				'user_email' => uniqid( 'member', true ) . '@acme.test',
			)
		);

		$member = new Member();
		$member->set_props(
			array_merge(
				array(
					'organization_id' => $organization->get_id(),
					'user_id'         => $user_id,
					'role'            => $role,
					'status'          => Member::STATUS_ACTIVE,
				),
				$props
			)
		);

		MemberRepository::save( $member );
		$this->flush();

		return $member;
	}

	/**
	 * Sign a membership's user in.
	 *
	 * @param Member $member Membership to act as.
	 * @return int The user ID.
	 */
	protected function act_as( Member $member ) {
		wp_set_current_user( $member->get_user_id() );
		$this->flush();

		return $member->get_user_id();
	}

	/**
	 * Store invoice terms for an organization.
	 *
	 * @param Organization $organization Organization the terms belong to.
	 * @param array        $props        Column values to set.
	 * @return Terms The saved terms.
	 */
	protected function set_terms( Organization $organization, array $props ) {
		$terms = new Terms( $organization->get_id() );
		$terms->set_props( $props );

		TermsRepository::save( $terms );
		$this->flush();

		return $terms;
	}

	/**
	 * Create an order belonging to an organization.
	 *
	 * @param Organization $organization Organization the order is for.
	 * @param float        $total        Order total.
	 * @param string       $status       Order status, without the `wc-` prefix.
	 * @param string       $method       Payment method ID.
	 * @return \WC_Order The saved order.
	 */
	protected function make_order( Organization $organization, $total, $status = 'on-hold', $method = InvoiceGateway::ID ) {
		$order = new \WC_Order();

		$order->set_payment_method( $method );
		$order->set_total( $total );
		$order->update_meta_data( \WooOrgAccounts\Checkout\OrderMeta::ORGANIZATION_ID, $organization->get_id() );
		$order->set_status( $status );
		$order->save();

		Credit::flush_cache();

		return $order;
	}
}
