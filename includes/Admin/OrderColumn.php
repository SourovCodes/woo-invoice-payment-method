<?php
/**
 * Invoices on the orders screen.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Admin;

use WooInvoicePayment\Checkout\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the invoice due date to the orders list, and makes orders findable by the
 * customer's own purchase order number.
 *
 * The hooks are the High-Performance Order Storage ones. HPOS is guaranteed here — the
 * organization plugin this one depends on refuses to boot without it — so there is no
 * legacy `manage_shop_order_posts_columns` path and no screen-ID branch.
 *
 * **The search key is the reason a purchase order number is worth collecting at all.**
 * A reference the customer quotes in an email is only useful if somebody at the shop
 * can type it into the orders screen and find the order; WooCommerce searches a fixed
 * list of meta keys and knows nothing about ours until it is told.
 */
class OrderColumn {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( $this, 'add_search_key' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Add the invoice column after the order total.
	 *
	 * @param array $columns Existing columns.
	 * @return array Columns, with ours inserted.
	 */
	public function add_column( $columns ) {
		$inserted = array();

		foreach ( (array) $columns as $key => $label ) {
			$inserted[ $key ] = $label;

			if ( 'order_total' === $key ) {
				$inserted['woip_invoice'] = __( 'Invoice due', 'woo-invoice-payment-method' );
			}
		}

		if ( ! isset( $inserted['woip_invoice'] ) ) {
			$inserted['woip_invoice'] = __( 'Invoice due', 'woo-invoice-payment-method' );
		}

		return $inserted;
	}

	/**
	 * Print the invoice column for one order.
	 *
	 * Everything comes off the order's own meta, so a screenful of orders costs no
	 * extra queries.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  The order.
	 * @return void
	 */
	public function render_column( $column, $order ) {
		if ( 'woip_invoice' !== $column || ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! OrderMeta::is_invoice_order( $order ) ) {
			echo '&mdash;';

			return;
		}

		$due = OrderMeta::formatted_due_date( $order );

		if ( '' === $due ) {
			echo '&mdash;';

			return;
		}

		if ( OrderMeta::is_overdue( $order ) ) {
			printf(
				'<mark class="woip-status woip-status--overdue">%s</mark>',
				esc_html(
					sprintf(
						/* translators: %s: the date the invoice fell due. */
						__( '%s — overdue', 'woo-invoice-payment-method' ),
						$due
					)
				)
			);

			return;
		}

		if ( null !== $order->get_date_paid() ) {
			printf( '<span class="woip-status woip-status--paid">%s</span>', esc_html__( 'Paid', 'woo-invoice-payment-method' ) );

			return;
		}

		echo esc_html( $due );
	}

	/**
	 * Let the orders screen search on the purchase order number.
	 *
	 * @param array $keys Meta keys WooCommerce already searches.
	 * @return array Keys, with ours added.
	 */
	public function add_search_key( $keys ) {
		$keys   = (array) $keys;
		$keys[] = OrderMeta::PURCHASE_ORDER;

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Load the admin stylesheet on the screens that use it.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = ( null === $screen ) ? '' : (string) $screen->id;

		$wanted = ( false !== strpos( $id, 'wc-orders' ) )
			|| ( false !== strpos( (string) $hook_suffix, OrganizationTerms::PAGE_SLUG ) );

		if ( ! $wanted ) {
			return;
		}

		wp_enqueue_style(
			'woip-admin',
			WOIP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WOIP_VERSION
		);
	}
}
