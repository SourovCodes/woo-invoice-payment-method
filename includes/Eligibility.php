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
	 * wants the organization it applies to — the credit check, the order stamp and the
	 * terms shown at checkout all need it, and looking it up again would be a second
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

	/**
	 * How many days an organization has to pay.
	 *
	 * @param int $organization_id Organization ID.
	 * @return int Days. Zero means due on receipt.
	 */
	public static function payment_terms_days( $organization_id ) {
		$days = TermsRepository::find( $organization_id )->get_payment_terms_days();

		return null === $days ? Options::payment_terms_days() : (int) $days;
	}

	/**
	 * The date an invoice raised now would fall due.
	 *
	 * Computed from the site's own clock in UTC and stored as a plain date, because a
	 * due date is a day rather than a moment: an invoice due on the 30th is due on the
	 * 30th wherever the customer reads it.
	 *
	 * @param int      $organization_id Organization ID.
	 * @param int|null $from            Timestamp to count from, or null for now.
	 * @return string Date in Y-m-d.
	 */
	public static function due_date( $organization_id, $from = null ) {
		$from = null === $from ? time() : (int) $from;
		$days = self::payment_terms_days( $organization_id );

		return gmdate( 'Y-m-d', $from + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * The payment terms as a sentence, for the checkout and the order.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string Translated sentence.
	 */
	public static function terms_summary( $organization_id ) {
		$days = self::payment_terms_days( $organization_id );

		if ( 0 === $days ) {
			return __( 'Payable on receipt.', 'woo-invoice-payment-method' );
		}

		return sprintf(
			/* translators: %d: number of days the invoice is payable within. */
			_n( 'Payable within %d day of the invoice date.', 'Payable within %d days of the invoice date.', $days, 'woo-invoice-payment-method' ),
			$days
		);
	}
}
