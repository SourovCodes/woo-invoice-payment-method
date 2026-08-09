<?php
/**
 * The invoice terms wherever an order is shown.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what an order was invoiced on, on every screen that shows the order.
 *
 * Four surfaces — the customer's order details, the order emails in both formats, and
 * the admin order screen — and one `rows()` that decides what they say. Three
 * near-identical lists drifting apart is how a shop ends up with a due date on the
 * email that the customer's account page disagrees with.
 *
 * Everything printed here is read off the order, never recomputed from the
 * organization's current terms. See Checkout\OrderMeta for why that distinction is the
 * whole point.
 */
class OrderDetails {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_details' ), 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_details' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * What an invoice order says about itself.
	 *
	 * @param \WC_Order $order The order.
	 * @return array Ordered map of label to value; empty when the order is not on invoice.
	 */
	public static function rows( \WC_Order $order ) {
		if ( ! OrderMeta::is_invoice_order( $order ) ) {
			return array();
		}

		$rows = array();
		$days = OrderMeta::payment_terms_days( $order );

		if ( 0 === $days ) {
			$rows[ __( 'Payment terms', 'woo-invoice-payment-method' ) ] = __( 'Payable on receipt', 'woo-invoice-payment-method' );
		} else {
			$rows[ __( 'Payment terms', 'woo-invoice-payment-method' ) ] = sprintf(
				/* translators: %d: number of days the invoice is payable within. */
				_n( '%d day', '%d days', $days, 'woo-invoice-payment-method' ),
				$days
			);
		}

		$due = OrderMeta::formatted_due_date( $order );

		if ( '' !== $due ) {
			$rows[ __( 'Invoice due', 'woo-invoice-payment-method' ) ] = OrderMeta::is_overdue( $order )
				? sprintf(
					/* translators: %s: the date the invoice fell due. */
					__( '%s — overdue', 'woo-invoice-payment-method' ),
					$due
				)
				: $due;
		}

		return $rows;
	}

	/**
	 * Print the terms under the order table on the customer's own screens.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	public function render_order_details( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$rows = self::rows( $order );

		if ( empty( $rows ) ) {
			return;
		}

		echo '<section class="woocommerce-order-invoice woip-invoice-details">';
		printf( '<h2>%s</h2>', esc_html__( 'Invoice', 'woo-invoice-payment-method' ) );
		echo '<ul class="woocommerce-order-overview woip-invoice-details__list">';

		foreach ( $rows as $label => $value ) {
			printf(
				'<li class="woip-invoice-details__item">%1$s <strong>%2$s</strong></li>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</ul>';
		echo '</section>';
	}

	/**
	 * Print the terms in the order emails.
	 *
	 * The shop's own copy of an order gets them too, unlike the gateway's "please pay
	 * this" instructions: when an invoice falls due is exactly what somebody looking at
	 * the shop's copy needs to know.
	 *
	 * @param \WC_Order $order         The order.
	 * @param bool      $sent_to_admin Whether this is one of the shop's own emails.
	 * @param bool      $plain_text    Whether the email is the plain text version.
	 * @return void
	 */
	public function render_email_details( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$rows = self::rows( $order );

		if ( empty( $rows ) ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html( wp_strip_all_tags( __( 'Invoice', 'woo-invoice-payment-method' ) ) ) . PHP_EOL;

			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . PHP_EOL;
			}

			echo PHP_EOL;

			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Invoice', 'woo-invoice-payment-method' ) );
		echo '<ul>';

		foreach ( $rows as $label => $value ) {
			printf( '<li>%1$s: <strong>%2$s</strong></li>', esc_html( $label ), esc_html( $value ) );
		}

		echo '</ul>';
	}

	/**
	 * Print the terms on the admin order screen.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	public function render_admin_details( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$rows = self::rows( $order );

		if ( empty( $rows ) ) {
			return;
		}

		echo '<div class="woip-admin-invoice">';
		printf( '<h3>%s</h3>', esc_html__( 'Invoice', 'woo-invoice-payment-method' ) );

		foreach ( $rows as $label => $value ) {
			printf(
				'<p class="form-field form-field-wide"><strong>%1$s:</strong> %2$s</p>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</div>';
	}

	/**
	 * Load the frontend stylesheet, and only where the plugin renders anything.
	 *
	 * The checkout is included on its own terms rather than through is_checkout(): the
	 * block checkout renders the same fields, and its markup is on the page whether or
	 * not the classic templates ran.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}

		if ( ! is_checkout() && ! is_account_page() && ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		wp_enqueue_style(
			'woip-checkout',
			WOIP_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			WOIP_VERSION
		);
	}
}
