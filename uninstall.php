<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. WordPress loads this file
 * directly, so nothing from the plugin's own bootstrap is available here — not the
 * autoloader, not the constants, not a single class. Everything below is written
 * against WordPress and $wpdb alone.
 *
 * @package WooInvoicePayment
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$woip_settings = get_option( 'woocommerce_woip_invoice_settings', array() );
$woip_purge    = is_array( $woip_settings ) && isset( $woip_settings['remove_data_on_uninstall'] )
	&& 'yes' === $woip_settings['remove_data_on_uninstall'];

delete_option( 'woo_invoice_payment_version' );
delete_site_transient( 'woip_update_manifest' );

/*
 * The agreed terms are the shop's arrangements with its customers — who may buy on
 * invoice, on what terms, up to how much. Deleting a plugin is not a decision to
 * forget them, so they survive unless the site explicitly asked for them to go. The
 * setting is off by default, and turning it on says so in as many words.
 */
if ( $woip_purge ) {
	global $wpdb;

	$woip_table = $wpdb->prefix . 'woip_invoice_terms';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A table name cannot be a placeholder; this one is built from a literal and $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$woip_table}" );

	delete_option( 'woo_invoice_payment_db_version' );
	delete_option( 'woocommerce_woip_invoice_settings' );
}

/*
 * The _woip_* meta on orders is never removed, whatever the setting says. The purchase
 * order number, the terms agreed and the date the invoice fell due are part of the
 * order — they are the terms it was sold on — and an order is a financial record the
 * shop may be required to keep intact. Nor is any order's status touched: an unpaid
 * invoice is still owed after this plugin has gone.
 */
