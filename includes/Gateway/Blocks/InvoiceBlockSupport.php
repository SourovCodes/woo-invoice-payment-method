<?php
/**
 * The payment method as the block checkout sees it.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Gateway\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WooInvoicePayment\Credit;
use WooInvoicePayment\Eligibility;
use WooInvoicePayment\Gateway\InvoiceGateway;
use WooInvoicePayment\Gateway\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the block checkout a script and the data to render the method with.
 *
 * **Nothing here enforces anything.** Every value below is a copy of an answer the
 * server has already given, sent to the browser so the customer can read it before
 * they submit; the order is still refused by `InvoiceGateway::process_payment()` if any
 * of it has changed or was tampered with in between. `is_active()` is availability, not
 * authorisation — it decides whether the method appears in the list.
 *
 * The script is plain JavaScript against the globals WooCommerce already enqueues. No
 * JSX and no bundler, so the plugin still builds with nothing but Composer, and CI's
 * `node --check assets/js` stays a meaningful test of the file that actually ships.
 */
final class InvoiceBlockSupport extends AbstractPaymentMethodType {

	/**
	 * The payment method name, matching the gateway ID.
	 *
	 * @var string
	 */
	protected $name = InvoiceGateway::ID;

	/**
	 * Read the gateway's settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = Options::all();
	}

	/**
	 * Whether the method is offered to this customer.
	 *
	 * @return bool True when the method should appear.
	 */
	public function is_active() {
		return Eligibility::is_eligible();
	}

	/**
	 * Register the script and return its handle.
	 *
	 * @return string[] Script handles.
	 */
	public function get_payment_method_script_handles() {
		$handle = 'woip-blocks-checkout';
		$path   = 'assets/js/blocks/checkout.js';

		wp_register_script(
			$handle,
			WOIP_PLUGIN_URL . $path,
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			WOIP_VERSION,
			true
		);

		/*
		 * The strings in the script are translated in the browser, from the catalogue
		 * WordPress serves for this handle. Without this the block checkout would be the
		 * one screen in the plugin that is always English.
		 */
		wp_set_script_translations( $handle, 'woo-invoice-payment-method', WOIP_PLUGIN_DIR . 'languages' );

		return array( $handle );
	}

	/**
	 * The data the script renders the method from.
	 *
	 * @return array Payment method data.
	 */
	public function get_payment_method_data() {
		$organization_id = Eligibility::organization_id();
		$available       = 0 === $organization_id ? null : Credit::available( $organization_id );

		return array(
			'title'        => (string) $this->get_setting( 'title' ),
			'description'  => (string) $this->get_setting( 'description' ),
			'creditNotice' => null === $available ? '' : sprintf(
				/* translators: %s: formatted amount of credit still available. */
				__( 'Credit still available: %s.', 'woo-invoice-payment-method' ),
				Credit::format( max( 0, $available ) )
			),
			'supports'     => $this->get_supported_features(),
		);
	}

	/**
	 * What the gateway supports, read from the gateway itself.
	 *
	 * @return string[] Supported features.
	 */
	public function get_supported_features() {
		$gateways = WC()->payment_gateways()->payment_gateways();

		return isset( $gateways[ $this->name ] ) ? array_values( $gateways[ $this->name ]->supports ) : array( 'products' );
	}
}
