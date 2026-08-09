<?php
/**
 * Registers the payment method with WooCommerce.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Gateway;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use WooInvoicePayment\Gateway\Blocks\InvoiceBlockSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the gateway in front of both checkouts.
 *
 * Two registrations, because WooCommerce 11 has two checkouts and a gateway that only
 * did the first would be invisible on the one a new shop gets by default. They are not
 * alternatives: the block registration describes the method to the browser, and the
 * gateway registration is what actually takes the order — the Store API hands the
 * chosen method straight to `process_payment()`.
 */
class Gateways {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_block_support' ) );
	}

	/**
	 * Add the gateway to WooCommerce's list.
	 *
	 * @param array $gateways Registered gateway class names.
	 * @return array Gateways, with ours appended.
	 */
	public function add_gateway( $gateways ) {
		$gateways[] = InvoiceGateway::class;

		return $gateways;
	}

	/**
	 * Describe the method to the block checkout.
	 *
	 * The hook only fires when the Blocks package is present, which is why the
	 * integration class — which extends a class from that package — is not referenced
	 * anywhere that runs unconditionally.
	 *
	 * @param PaymentMethodRegistry $registry Blocks payment method registry.
	 * @return void
	 */
	public function register_block_support( $registry ) {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		$registry->register( new InvoiceBlockSupport() );
	}
}
