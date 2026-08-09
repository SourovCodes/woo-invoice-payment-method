<?php
/**
 * The gateway's own settings, readable without the gateway.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the option WC_Settings_API stores the gateway's settings in.
 *
 * The gateway is the only settings screen this plugin has, and everything else —
 * eligibility, credit, the admin terms screen, uninstall — has to read those same
 * answers. Going through the gateway object to get them would mean waiting for
 * WooCommerce to instantiate it, which has not happened on `plugins_loaded`, is not
 * guaranteed inside an uninstall, and would make a plain question about a setting
 * depend on the payment gateways being loaded.
 *
 * So the option is read directly, and the defaults live here rather than in
 * init_form_fields() — the form is built from this list, which is what stops the
 * default a field renders with drifting from the default the rest of the plugin
 * assumes when the field has never been saved.
 */
final class Options {

	/**
	 * Option WC_Settings_API stores this gateway's settings in.
	 *
	 * WooCommerce derives the name from the gateway ID, so this constant and
	 * InvoiceGateway::ID have to move together.
	 */
	const OPTION_KEY = 'woocommerce_woip_invoice_settings';

	/**
	 * Offer invoicing to every organization that has not been refused it.
	 */
	const AVAILABILITY_ALL = 'all';

	/**
	 * Offer invoicing only to the organizations allowed it one by one.
	 */
	const AVAILABILITY_SELECTED = 'selected';

	/**
	 * The default settings.
	 *
	 * @return array Map of setting name to default value.
	 */
	public static function defaults() {
		return array(
			'enabled'                  => 'no',
			'title'                    => __( 'Pay by invoice', 'woo-invoice-payment-method' ),
			'description'              => __( 'Receive an invoice with your delivery and pay it within the agreed terms.', 'woo-invoice-payment-method' ),
			'instructions'             => __( 'Your invoice follows separately. Please quote the order number when you pay it.', 'woo-invoice-payment-method' ),
			'availability'             => self::AVAILABILITY_SELECTED,
			'payment_terms_days'       => 30,
			'order_status'             => 'on-hold',
			'remove_data_on_uninstall' => 'no',
		);
	}

	/**
	 * Every setting, merged over the defaults.
	 *
	 * @return array Complete settings array.
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed Setting value, or null when there is no such setting.
	 */
	public static function get( $key ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
	}

	/**
	 * Whether the gateway is switched on at all.
	 *
	 * @return bool True when enabled.
	 */
	public static function is_enabled() {
		return 'yes' === self::get( 'enabled' );
	}

	/**
	 * Who the payment method is offered to before per-organization terms are applied.
	 *
	 * @return string One of the AVAILABILITY_* constants.
	 */
	public static function availability() {
		$value = (string) self::get( 'availability' );

		return self::AVAILABILITY_ALL === $value ? self::AVAILABILITY_ALL : self::AVAILABILITY_SELECTED;
	}

	/**
	 * How long an invoice is payable for by default, in days.
	 *
	 * @return int Days. Zero means due on receipt.
	 */
	public static function payment_terms_days() {
		return absint( self::get( 'payment_terms_days' ) );
	}

	/**
	 * The status an order is moved to once it has been placed on invoice.
	 *
	 * Restricted to the two statuses that mean something here. On-hold is the default
	 * and the honest one: nothing has been paid yet. Processing is offered because a
	 * shop that ships before it is paid needs the order in its fulfilment queue — but
	 * WooCommerce stamps `date_paid` on an order the moment it reaches a paid status,
	 * so choosing it means the shop is telling WooCommerce the invoice is settled and
	 * the order stops counting against the customer's credit limit.
	 *
	 * @return string Status slug, without the `wc-` prefix.
	 */
	public static function order_status() {
		$value = (string) self::get( 'order_status' );

		return 'processing' === $value ? 'processing' : 'on-hold';
	}

	/**
	 * Whether deleting the plugin should delete the invoice terms with it.
	 *
	 * @return bool True when the site asked for its data to be removed.
	 */
	public static function removes_data_on_uninstall() {
		return 'yes' === self::get( 'remove_data_on_uninstall' );
	}
}
