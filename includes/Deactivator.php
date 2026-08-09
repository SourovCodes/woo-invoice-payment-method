<?php
/**
 * Deactivation routine.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

use WooInvoicePayment\Updates\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 */
final class Deactivator {

	/**
	 * Tidy up what only makes sense while the plugin is running.
	 *
	 * The table, the settings and every organization's agreed terms are left in place:
	 * a deactivate/reactivate cycle must not cost a shop the credit limits it agreed
	 * with its customers, and switching a payment method off for an afternoon is not a
	 * decision to forget who was allowed to use it. Removal belongs in uninstall.php,
	 * and even there it is opt-in.
	 *
	 * The cached release manifest does go. It is a copy of something on GitHub, held
	 * only so the update check does not go to the network on every admin page load, and
	 * a copy of it belonging to a plugin that is no longer running is nothing but a
	 * stale row in the options table.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_site_transient( Updater::CACHE_KEY );

		/**
		 * Fires after the plugin has finished its deactivation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_invoice_payment_deactivated' );
	}
}
