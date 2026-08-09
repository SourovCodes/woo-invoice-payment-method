<?php
/**
 * How much an organization already owes, and how much more it may.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

use WooInvoicePayment\Data\TermsRepository;
use WooInvoicePayment\Gateway\InvoiceGateway;
use WooOrgAccounts\Checkout\OrderMeta as OrganizationMeta;

defined( 'ABSPATH' ) || exit;

/**
 * The credit limit, and the only place that decides whether it has been reached.
 *
 * **There is no invoice paid-flag.** WooCommerce already answers "has this been
 * paid?" — it stamps `date_paid` on an order the moment the order reaches a status in
 * `wc_get_is_paid_statuses()`, which is what a shop marking an invoice settled does.
 * A `_woip_invoice_paid` meta beside that would be a second answer to one question,
 * and the two would disagree the first time somebody changed a status without using
 * whichever screen maintained our flag. So an invoice is outstanding while the order
 * it belongs to has no `date_paid` and has not been cancelled, refunded or failed.
 *
 * The consequence is worth stating plainly, because it decides how a shop should
 * configure the gateway: moving an order to Processing or Completed frees the credit
 * it was using. A shop that ships before it is paid and wants the limit to keep
 * meaning something should leave new invoice orders **on hold** and move them on when
 * the money lands.
 */
final class Credit {

	/**
	 * Outstanding totals already computed this request, keyed by organization ID.
	 *
	 * The limit is checked at least twice per checkout — once to tell the customer
	 * what is left, once to decide whether the order may be placed — and each check is
	 * an order query.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * The order statuses an unpaid invoice can be sitting in.
	 *
	 * Derived rather than listed, so a shop with a custom status gets the sensible
	 * answer: everything except the statuses WooCommerce considers paid and the ones
	 * that mean the order is not going to be paid because it no longer exists as a
	 * sale.
	 *
	 * @return string[] Status slugs, without the `wc-` prefix.
	 */
	public static function unpaid_statuses() {
		$all  = array_map(
			static function ( $status ) {
				return (string) preg_replace( '/^wc-/', '', $status );
			},
			array_keys( wc_get_order_statuses() )
		);
		$dead = array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' );

		return array_values( array_diff( $all, wc_get_is_paid_statuses(), $dead ) );
	}

	/**
	 * How much an organization currently owes on unpaid invoices.
	 *
	 * @param int $organization_id Organization ID.
	 * @param int $exclude_order   Order to leave out of the sum.
	 * @return float Amount in the shop's currency.
	 */
	public static function outstanding( $organization_id, $exclude_order = 0 ) {
		$organization_id = absint( $organization_id );
		$exclude_order   = absint( $exclude_order );

		if ( 0 === $organization_id ) {
			return 0.0;
		}

		$key = $organization_id . ':' . $exclude_order;

		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		$orders = wc_get_orders(
			array(
				'limit'          => -1,
				'type'           => 'shop_order',
				'status'         => self::unpaid_statuses(),
				'payment_method' => InvoiceGateway::ID,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The organization is the only way to ask this question, and it is an indexed order meta key.
					array(
						'key'     => OrganizationMeta::ORGANIZATION_ID,
						'value'   => $organization_id,
						'compare' => '=',
					),
				),
			)
		);

		$total = 0.0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || $order->get_id() === $exclude_order ) {
				continue;
			}

			/*
			 * The status filter above has already excluded the paid statuses, so this
			 * catches the other case: an order that was paid and then moved back to a
			 * status that is not a paid one. The money arrived; it is not owed twice.
			 */
			if ( null !== $order->get_date_paid() ) {
				continue;
			}

			$total += (float) $order->get_total() - (float) $order->get_total_refunded();
		}

		self::$memo[ $key ] = round( $total, wc_get_price_decimals() );

		return self::$memo[ $key ];
	}

	/**
	 * How much more an organization may put on invoice.
	 *
	 * @param int $organization_id Organization ID.
	 * @param int $exclude_order   Order to leave out of the outstanding sum.
	 * @return float|null Amount remaining, or null when the organization is not limited.
	 */
	public static function available( $organization_id, $exclude_order = 0 ) {
		$terms = TermsRepository::find( $organization_id );

		if ( ! $terms->has_credit_limit() ) {
			return null;
		}

		return (float) $terms->get_credit_limit() - self::outstanding( $organization_id, $exclude_order );
	}

	/**
	 * Whether an amount would fit inside what is left of an organization's credit.
	 *
	 * @param int   $organization_id Organization ID.
	 * @param float $amount          Amount about to be put on invoice.
	 * @param int   $exclude_order   Order to leave out of the outstanding sum.
	 * @return bool True when the amount is within the limit.
	 */
	public static function allows( $organization_id, $amount, $exclude_order = 0 ) {
		$available = self::available( $organization_id, $exclude_order );

		if ( null === $available ) {
			return true;
		}

		/*
		 * Compared at the shop's own precision. Without the rounding, a cart total of
		 * 100.00 against 100.00 of credit can fail on the float arithmetic that
		 * produced one of them.
		 */
		$decimals = wc_get_price_decimals();

		return round( (float) $amount, $decimals ) <= round( $available, $decimals );
	}

	/**
	 * Why an amount does not fit, as a sentence for the customer.
	 *
	 * @param int   $organization_id Organization ID.
	 * @param float $amount          Amount about to be put on invoice.
	 * @param int   $exclude_order   Order to leave out of the outstanding sum.
	 * @return string Translated message, or an empty string when the amount is allowed.
	 */
	public static function blocked_reason( $organization_id, $amount, $exclude_order = 0 ) {
		if ( self::allows( $organization_id, $amount, $exclude_order ) ) {
			return '';
		}

		$available = self::available( $organization_id, $exclude_order );

		if ( $available <= 0 ) {
			return sprintf(
				/* translators: %s: formatted amount already outstanding. */
				__( 'This order cannot go on invoice: %s is already outstanding, which is the whole of the agreed credit. Please settle an open invoice, or choose another payment method.', 'woo-invoice-payment-method' ),
				self::format( self::outstanding( $organization_id, $exclude_order ) )
			);
		}

		return sprintf(
			/* translators: 1: formatted order total, 2: formatted amount of credit still available. */
			__( 'This order cannot go on invoice: it comes to %1$s and only %2$s of the agreed credit is left. Please settle an open invoice, or choose another payment method.', 'woo-invoice-payment-method' ),
			self::format( $amount ),
			self::format( $available )
		);
	}

	/**
	 * Format an amount as plain text in the shop's currency.
	 *
	 * `wc_price()` returns markup, which is right in a template and wrong in a
	 * sentence that will be escaped by whoever prints it — the customer would read the
	 * span. The entities go too, because `&euro;` is not a currency symbol to anything
	 * that is not a browser.
	 *
	 * @param float $amount Amount.
	 * @return string Formatted amount.
	 */
	public static function format( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Forget everything computed this request.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$memo = array();
	}
}
