<?php
/**
 * Activation routine.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

use WooInvoicePayment\Gateway\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Prepare the site for the plugin.
	 *
	 * Keep this idempotent: WordPress runs it on every activation, including
	 * reactivation after an update.
	 *
	 * The gateway is deliberately left switched off. Turning a payment method on for a
	 * shop that has not been asked which of its customers may use it would offer every
	 * organization the right to take goods away without paying — and an activation
	 * routine is not where that decision belongs.
	 *
	 * @return void
	 */
	public static function activate() {
		// Seed the settings so every screen has a complete array to read, including the
		// ones that run before WooCommerce has instantiated the gateway. Autoload is off
		// because they are read at the checkout and in wp-admin, not on every request.
		add_option( Options::OPTION_KEY, Options::defaults(), '', false );
		add_option( 'woo_invoice_payment_version', WOIP_VERSION, '', false );

		Install::install();

		/**
		 * Fires after the plugin has finished its activation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_invoice_payment_activated' );
	}
}
