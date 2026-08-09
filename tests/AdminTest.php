<?php
/**
 * Tests for the invoice terms admin screen.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use WooInvoicePayment\Admin\OrganizationTerms;
use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Data\TermsRepository;
use WooOrgAccounts\Data\Organization;

/**
 * Covers the one handler in this plugin that writes anything from a form.
 *
 * The two things worth asserting are that it cannot be reached without both a nonce
 * and the capability, and that the ambiguous fields — the ones where an empty box
 * could mean two opposite things — are read from the radio that names the answer
 * rather than from the box.
 */
class AdminTest extends TestCase {

	/**
	 * Catch the redirect the handler ends in instead of letting it exit the run.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
	}

	/**
	 * Stop catching it.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		parent::tear_down();
	}

	/**
	 * Turn a redirect into something a test can assert on.
	 *
	 * @param string $location Redirect target.
	 * @return void This never returns; it always throws.
	 * @throws RedirectException Always.
	 */
	public function intercept_redirect( $location ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Never printed; the message exists so a test can read the destination back.
		throw new RedirectException( $location );
	}

	/**
	 * Sign in somebody who runs the shop.
	 *
	 * @return int User ID.
	 */
	private function act_as_shop_manager() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Submit the form.
	 *
	 * @param Organization $organization Organization being edited.
	 * @param array        $fields       Form fields.
	 * @return string The redirect target.
	 */
	private function submit( Organization $organization, array $fields ) {
		$_POST = array_merge(
			array(
				'organization_id' => $organization->get_id(),
				'_wpnonce'        => wp_create_nonce( 'woip_save_terms' ),
			),
			$fields
		);

		// check_admin_referer() reads $_REQUEST, which is what a real submission fills
		// alongside $_POST and what the shared test case clears between tests.
		$_REQUEST = $_POST;

		try {
			( new OrganizationTerms() )->handle_save();
		} catch ( RedirectException $redirect ) {
			$this->flush();

			return $redirect->location;
		}

		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * The screen and its handler both need the shop capability.
	 */
	public function testTheHandlerRequiresTheCapability() {
		$organization = $this->make_organization();
		$member       = $this->make_member( $organization );

		$this->act_as( $member );

		$_POST = array(
			'organization_id' => $organization->get_id(),
			'_wpnonce'        => wp_create_nonce( 'woip_save_terms' ),
			'status'          => Terms::STATUS_ALLOWED,
		);

		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );

		( new OrganizationTerms() )->handle_save();
	}

	/**
	 * A submission without a valid nonce is refused.
	 */
	public function testTheHandlerRequiresANonce() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$_POST = array(
			'organization_id' => $organization->get_id(),
			'status'          => Terms::STATUS_ALLOWED,
		);

		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );

		( new OrganizationTerms() )->handle_save();
	}

	/**
	 * A complete submission is stored.
	 */
	public function testTermsAreSaved() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$location = $this->submit(
			$organization,
			array(
				'status'             => Terms::STATUS_ALLOWED,
				'terms_mode'         => 'custom',
				'payment_terms_days' => '14',
				'credit_mode'        => 'limited',
				'credit_limit'       => '2500',
				'note'               => 'Agreed with Ada.',
			)
		);

		$this->assertStringContainsString( 'woip_notice=saved', $location );

		$stored = TermsRepository::find( $organization->get_id() );

		$this->assertSame( Terms::STATUS_ALLOWED, $stored->get_status() );
		$this->assertSame( 14, $stored->get_payment_terms_days() );
		$this->assertSame( 2500.0, $stored->get_credit_limit() );
		$this->assertSame( 'Agreed with Ada.', $stored->get_note() );
	}

	/**
	 * The radio is what the server reads, not whether the box happens to be filled in.
	 *
	 * This is the bug the pattern exists to prevent: a number left in the box from the
	 * last edit would otherwise keep applying after somebody chose "use the default",
	 * and an empty box cannot say whether "no limit" or "a limit of zero" was meant.
	 */
	public function testTheRadioDecidesRatherThanTheBox() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$this->submit(
			$organization,
			array(
				'status'             => Terms::STATUS_ALLOWED,
				'terms_mode'         => 'default',
				'payment_terms_days' => '14',
				'credit_mode'        => 'none',
				'credit_limit'       => '2500',
			)
		);

		$stored = TermsRepository::find( $organization->get_id() );

		$this->assertNull( $stored->get_payment_terms_days(), 'A stale box overrode "use the default".' );
		$this->assertNull( $stored->get_credit_limit(), 'A stale box overrode "no limit".' );
	}

	/**
	 * A limit of zero is stored as a limit, not discarded as an empty answer.
	 */
	public function testALimitOfZeroIsStored() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$this->submit(
			$organization,
			array(
				'status'       => Terms::STATUS_DEFAULT,
				'terms_mode'   => 'default',
				'credit_mode'  => 'limited',
				'credit_limit' => '0',
			)
		);

		$stored = TermsRepository::find( $organization->get_id() );

		$this->assertTrue( $stored->has_credit_limit() );
		$this->assertSame( 0.0, $stored->get_credit_limit() );
	}

	/**
	 * A limit that is not a number is refused rather than stored as zero.
	 *
	 * Storing it as zero would silently refuse the customer every future invoice.
	 */
	public function testANonNumericLimitIsRefused() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$location = $this->submit(
			$organization,
			array(
				'status'       => Terms::STATUS_ALLOWED,
				'terms_mode'   => 'default',
				'credit_mode'  => 'limited',
				'credit_limit' => 'quite a lot',
			)
		);

		$this->assertStringContainsString( 'woip_notice=invalid', $location );
		$this->assertTrue( TermsRepository::find( $organization->get_id() )->is_default() );
	}

	/**
	 * A negative limit is refused too.
	 */
	public function testANegativeLimitIsRefused() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$location = $this->submit(
			$organization,
			array(
				'status'       => Terms::STATUS_DEFAULT,
				'terms_mode'   => 'default',
				'credit_mode'  => 'limited',
				'credit_limit' => '-50',
			)
		);

		$this->assertStringContainsString( 'woip_notice=invalid', $location );
	}

	/**
	 * Clearing every answer removes the row and says so.
	 */
	public function testClearingTheTermsRemovesTheRow() {
		$this->act_as_shop_manager();

		$organization = $this->make_organization();

		$this->set_terms( $organization, array( 'status' => Terms::STATUS_BLOCKED ) );

		$location = $this->submit(
			$organization,
			array(
				'status'      => Terms::STATUS_DEFAULT,
				'terms_mode'  => 'default',
				'credit_mode' => 'none',
			)
		);

		$this->assertStringContainsString( 'woip_notice=cleared', $location );
		$this->assertTrue( TermsRepository::find( $organization->get_id() )->is_default() );
	}

	/**
	 * Terms cannot be written for an organization that does not exist.
	 */
	public function testAnUnknownOrganizationIsRefused() {
		$this->act_as_shop_manager();

		$_POST = array(
			'organization_id' => 987654,
			'_wpnonce'        => wp_create_nonce( 'woip_save_terms' ),
			'status'          => Terms::STATUS_ALLOWED,
		);

		$_REQUEST = $_POST;

		try {
			( new OrganizationTerms() )->handle_save();
		} catch ( RedirectException $redirect ) {
			$this->assertStringContainsString( 'woip_notice=unknown', $redirect->location );

			return;
		}

		$this->fail( 'The handler did not redirect.' );
	}
}
