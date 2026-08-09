<?php
/**
 * Who may pay by invoice.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Data\TermsRepository;
use WooInvoicePayment\Gateway\Options;
use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The one expression of the rule the payment method is offered on.
 *
 * Two questions, in this order, and the order is the point:
 *
 * 1. **May this person buy at all?** That is not this plugin's question — it is
 *    `Context::can_purchase()`, and the organization plugin already refuses the whole
 *    checkout when the answer is no. Asking it here anyway means the invoice method
 *    can never be the *last* one standing on a checkout that should not be happening:
 *    a suspended organization gets no payment method rather than the one that lets it
 *    take goods away without paying.
 * 2. **Has this organization been given invoicing?** The stored terms answer outright
 *    when they say allowed or blocked, and defer to the gateway's own availability
 *    setting when they say nothing.
 *
 * Everything here is about the *customer*, so it decides whether the method appears.
 * Whether a particular basket may go on it is a question about the basket, and lives
 * in Credit — a method that vanished when the cart grew would look like a bug.
 */
final class Eligibility {

	/**
	 * Whether a user may be offered the invoice payment method.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return bool True when the method should be offered.
	 */
	public static function is_eligible( $user_id = 0 ) {
		return self::organization_id( $user_id ) > 0;
	}

	/**
	 * The organization a user would be invoicing, when they may be invoiced at all.
	 *
	 * Returned rather than a bare bool because every caller that wants the answer also
	 * wants the organization it applies to — the credit check and the credit still
	 * available at the checkout both need it, and looking it up again would be a second
	 * chance to look it up differently.
	 *
	 * @param int $user_id User ID, or 0 for the current user.
	 * @return int Organization ID, or 0 when the user may not pay by invoice.
	 */
	public static function organization_id( $user_id = 0 ) {
		if ( ! Options::is_enabled() ) {
			return 0;
		}

		if ( ! Context::can_purchase( $user_id ) ) {
			return 0;
		}

		$organization_id = Context::organization_id( $user_id );

		if ( 0 === $organization_id ) {
			return 0;
		}

		return self::organization_allows( $organization_id ) ? $organization_id : 0;
	}

	/**
	 * Whether one organization may pay by invoice.
	 *
	 * @param int $organization_id Organization ID.
	 * @return bool True when invoicing is open to it.
	 */
	public static function organization_allows( $organization_id ) {
		$terms = TermsRepository::find( $organization_id );

		if ( Terms::STATUS_BLOCKED === $terms->get_status() ) {
			return false;
		}

		if ( Terms::STATUS_ALLOWED === $terms->get_status() ) {
			return true;
		}

		return Options::AVAILABILITY_ALL === Options::availability();
	}
}
