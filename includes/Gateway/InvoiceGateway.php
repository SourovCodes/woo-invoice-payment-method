<?php
/**
 * The Pay by invoice payment method.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Gateway;

use WooInvoicePayment\Credit;
use WooInvoicePayment\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * An offline payment method: the goods go out, the invoice follows, the money arrives
 * later.
 *
 * Structurally it is WooCommerce's own bank transfer gateway — nothing is charged,
 * the order is parked in a status that means "not paid yet" and a human settles it.
 * What makes it a different product is who it is offered to: an organization the shop
 * has agreed terms with, up to a credit limit it set, and nobody else. Those two
 * questions are asked in Eligibility and Credit rather than here, because this class
 * is also the plugin's settings screen and a settings screen is a bad place to keep a
 * rule.
 *
 * Both checkout surfaces are supported. The classic checkout renders payment_fields()
 * and calls validate_fields(); the block checkout renders assets/js/blocks/checkout.js
 * and never calls it. process_payment() is the one point both go through, so every
 * rule is enforced there whether or not it was also enforced earlier — the earlier
 * check exists to tell the customer sooner, not to be the check.
 */
class InvoiceGateway extends \WC_Payment_Gateway {

	/**
	 * The gateway ID.
	 *
	 * WooCommerce builds the settings option name, the settings screen URL and half a
	 * dozen hook names out of this, so Options::OPTION_KEY has to move with it.
	 */
	const ID = 'woip_invoice';

	/**
	 * What the customer is told on the order received page and in the emails.
	 *
	 * Declared rather than assigned into: WC_Payment_Gateway does not carry this
	 * property, and PHP 8.2 deprecates creating one on the fly. WooCommerce's own
	 * offline gateways declare it for the same reason.
	 *
	 * @var string
	 */
	public $instructions = '';

	/**
	 * Set the gateway up and register the hooks it owns.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'Pay by invoice', 'woo-invoice-payment-method' );
		$this->method_description = __(
			'Lets an organization complete the checkout without paying, and be invoiced separately. Offered only to the organizations you allow it to, and only up to the credit limit you set them.',
			'woo-invoice-payment-method'
		);
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = (string) $this->get_option( 'title' );
		$this->description  = (string) $this->get_option( 'description' );
		$this->instructions = (string) $this->get_option( 'instructions' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * The settings screen, under WooCommerce → Settings → Payments.
	 *
	 * Every default is read from Options::defaults() rather than written out again
	 * here. The rest of the plugin reads the option directly — the gateway object does
	 * not exist on `plugins_loaded`, and it certainly does not exist during an
	 * uninstall — so a default typed twice would be a setting that means one thing
	 * before it is first saved and another afterwards.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$defaults = Options::defaults();

		$this->form_fields = array(
			'enabled'                  => array(
				'title'   => __( 'Enable/Disable', 'woo-invoice-payment-method' ),
				'type'    => 'checkbox',
				'label'   => __( 'Let approved organizations pay by invoice', 'woo-invoice-payment-method' ),
				'default' => $defaults['enabled'],
			),
			'title'                    => array(
				'title'       => __( 'Title', 'woo-invoice-payment-method' ),
				'type'        => 'safe_text',
				'description' => __( 'What the payment method is called at the checkout.', 'woo-invoice-payment-method' ),
				'default'     => $defaults['title'],
				'desc_tip'    => true,
			),
			'description'              => array(
				'title'       => __( 'Description', 'woo-invoice-payment-method' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method when the customer selects it. The credit still available is added to it automatically.', 'woo-invoice-payment-method' ),
				'default'     => $defaults['description'],
				'desc_tip'    => true,
			),
			'instructions'             => array(
				'title'       => __( 'Instructions', 'woo-invoice-payment-method' ),
				'type'        => 'textarea',
				'description' => __( 'Shown on the order received page and in the order emails.', 'woo-invoice-payment-method' ),
				'default'     => $defaults['instructions'],
				'desc_tip'    => true,
			),
			'availability'             => array(
				'title'       => __( 'Offer it to', 'woo-invoice-payment-method' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Either way, an organization you have refused invoicing never sees it, and one you have allowed it always does.', 'woo-invoice-payment-method' ),
				'default'     => $defaults['availability'],
				'desc_tip'    => true,
				'options'     => array(
					Options::AVAILABILITY_SELECTED => __( 'Only the organizations I allow one by one', 'woo-invoice-payment-method' ),
					Options::AVAILABILITY_ALL      => __( 'Every organization that can place orders', 'woo-invoice-payment-method' ),
				),
			),
			'order_status'             => array(
				'title'       => __( 'Order status', 'woo-invoice-payment-method' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'On hold is the honest answer: nothing has been paid. Processing puts the order in your fulfilment queue, but WooCommerce records it as paid the moment it gets there, and a paid order stops counting against the credit limit.', 'woo-invoice-payment-method' ),
				'default'     => $defaults['order_status'],
				'desc_tip'    => true,
				'options'     => array(
					'on-hold'    => __( 'On hold — awaiting payment of the invoice', 'woo-invoice-payment-method' ),
					'processing' => __( 'Processing — ready to fulfil', 'woo-invoice-payment-method' ),
				),
			),
			'woip_data'                => array(
				'title'       => __( 'Data', 'woo-invoice-payment-method' ),
				'type'        => 'title',
				'description' => __( 'What happens to the invoice terms you have agreed when this plugin is deleted from the Plugins screen.', 'woo-invoice-payment-method' ),
			),
			'remove_data_on_uninstall' => array(
				'title'       => __( 'On uninstall', 'woo-invoice-payment-method' ),
				'type'        => 'checkbox',
				'label'       => __( 'Delete every organization\'s invoice terms and credit limits when the plugin is deleted', 'woo-invoice-payment-method' ),
				'description' => __( 'Off by default. These are agreements with customers, and deleting the plugin should not delete them by accident. Orders and the terms recorded on them are never touched either way.', 'woo-invoice-payment-method' ),
				'default'     => $defaults['remove_data_on_uninstall'],
			),
		);
	}

	/**
	 * Whether to offer the method to the customer looking at the checkout.
	 *
	 * Availability is about the *customer*, never about the basket. A method that
	 * disappeared when somebody added one more case to their cart would read as a bug
	 * in the shop, so the credit limit is enforced when the order is placed and
	 * explained in so many words — see process_payment().
	 *
	 * @return bool True when the method should be offered.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		return Eligibility::is_eligible();
	}

	/**
	 * Render the classic checkout's fields for this method.
	 *
	 * @return void
	 */
	public function payment_fields() {
		$organization_id = Eligibility::organization_id();

		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( 0 === $organization_id ) {
			return;
		}

		$available = Credit::available( $organization_id );

		if ( null !== $available ) {
			printf(
				'<p class="woip-credit">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: formatted amount of credit still available. */
						__( 'Credit still available: %s.', 'woo-invoice-payment-method' ),
						Credit::format( max( 0, $available ) )
					)
				)
			);
		}
	}

	/**
	 * Validate the classic checkout's fields.
	 *
	 * Nothing here is the enforcement — process_payment() is, because the block
	 * checkout never calls this method. What this buys is the customer hearing about
	 * the problem at the checkout rather than after the order has been built.
	 *
	 * @return bool True when the submission may proceed.
	 */
	public function validate_fields() {
		$valid           = true;
		$organization_id = Eligibility::organization_id();

		if ( 0 === $organization_id ) {
			wc_add_notice( $this->unavailable_message(), 'error' );

			return false;
		}

		$cart = WC()->cart;

		if ( $cart instanceof \WC_Cart ) {
			$reason = Credit::blocked_reason( $organization_id, (float) $cart->get_total( 'edit' ) );

			if ( '' !== $reason ) {
				wc_add_notice( $reason, 'error' );

				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Place the order on invoice.
	 *
	 * Every rule is re-asked here, and this is the only place both checkouts pass
	 * through: the Store API does not call validate_fields(), and a payment method
	 * that was available when the page rendered can have stopped being available by
	 * the time the order is submitted. An exception thrown here becomes a checkout
	 * error on the classic checkout and a REST error on the block one, both carrying
	 * the message as written.
	 *
	 * @param int $order_id Order being paid for.
	 * @return array WooCommerce's result array.
	 * @throws \Exception When the order may not go on invoice.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'That order could not be found.', 'woo-invoice-payment-method' ) );
		}

		$organization_id = Eligibility::organization_id( $order->get_customer_id() );

		if ( 0 === $organization_id ) {
			throw new \Exception( esc_html( $this->unavailable_message() ) );
		}

		/*
		 * The order itself is left out of the outstanding sum. On the order-pay screen
		 * it already exists and is already unpaid, so counting it would make every such
		 * order push itself over the limit.
		 */
		$reason = Credit::blocked_reason( $organization_id, (float) $order->get_total(), $order->get_id() );

		if ( '' !== $reason ) {
			throw new \Exception( esc_html( $reason ) );
		}

		$order->set_payment_method( $this );
		$order->save();

		if ( 'processing' === Options::order_status() ) {
			/*
			 * payment_complete() is what stamps `date_paid`, and that is exactly what
			 * this setting means: the shop has told WooCommerce the order is paid for so
			 * that it lands in the fulfilment queue. Credit stops counting it, which is
			 * why the setting says so on the settings screen.
			 */
			$order->payment_complete();
		} else {
			wc_maybe_reduce_stock_levels( $order->get_id() );

			$order->update_status(
				'on-hold',
				__( 'Awaiting payment of the invoice.', 'woo-invoice-payment-method' )
			);
		}

		$cart = WC()->cart;

		if ( $cart instanceof \WC_Cart ) {
			$cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Print the instructions on the order received page.
	 *
	 * @return void
	 */
	public function thankyou_page() {
		if ( '' === $this->instructions ) {
			return;
		}

		echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
	}

	/**
	 * Print the instructions in the order emails.
	 *
	 * Only on the customer's own emails, and only for an order that actually went on
	 * invoice: an admin email carrying "please pay this invoice" is addressed to the
	 * wrong person.
	 *
	 * @param \WC_Order $order         The order.
	 * @param bool      $sent_to_admin Whether this is one of the shop's own emails.
	 * @param bool      $plain_text    Whether the email is the plain text version.
	 * @return void
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() ) {
			return;
		}

		if ( '' === $this->instructions ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html( wp_strip_all_tags( $this->instructions ) ) . PHP_EOL . PHP_EOL;

			return;
		}

		echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
	}

	/**
	 * The message shown when the method turns out not to be available after all.
	 *
	 * Deliberately one message for every reason. The customer's organization may have
	 * been suspended, refused invoicing or had the whole method switched off under
	 * them, and none of those is theirs to fix at a checkout — telling them apart would
	 * only turn the checkout into a way of asking the shop questions about its own
	 * customer records.
	 *
	 * @return string Translated message.
	 */
	private function unavailable_message() {
		return __( 'Paying by invoice is not available on this account. Please choose another payment method, or contact the shop.', 'woo-invoice-payment-method' );
	}
}
