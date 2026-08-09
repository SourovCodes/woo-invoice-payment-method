<?php
/**
 * Plugin Name:          WooCommerce Invoice Payment Method
 * Plugin URI:           https://github.com/SourovCodes/woo-invoice-payment-method
 * Update URI:           https://github.com/SourovCodes/woo-invoice-payment-method
 * Description:          Adds a Pay by invoice payment method to the WooCommerce checkout, offered only to the organizations a shop has approved for it, on the payment terms and credit limit it set them.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce, woo-organization-accounts-pro
 * Author:               Sourov Biswas
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-invoice-payment-method
 * Domain Path:          /languages
 * WC requires at least: 11.0
 * WC tested up to:      11.0
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

defined( 'ABSPATH' ) || exit;

define( 'WOIP_VERSION', '0.1.0' );
define( 'WOIP_PLUGIN_FILE', __FILE__ );
define( 'WOIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOIP_MIN_WC_VERSION', '11.0' );

/**
 * Slug and minimum version of the plugin this one extends.
 *
 * Invoicing is a property of an organization — which one is being billed, what it
 * agreed to pay in, how much credit it has — so there is nothing here that makes sense
 * without organization accounts. The floor is the release that first shipped
 * WooOrgAccounts\Membership\Context and the `_woap_organization_id` order meta this
 * plugin reads.
 */
define( 'WOIP_ORGANIZATION_PLUGIN', 'woo-organization-accounts-pro' );
define( 'WOIP_MIN_WOAP_VERSION', '0.3.0' );

/**
 * Schema version of the plugin's own table.
 *
 * Bumped whenever includes/Install.php changes the table, which is what makes an
 * existing site run dbDelta again on the next load.
 */
define( 'WOIP_DB_VERSION', '1.0.0' );

/**
 * Priority this plugin boots at on `plugins_loaded`.
 *
 * Later than the default, and that is load-bearing. WordPress includes active plugins
 * in directory order, so `woo-invoice-payment-method` is included before
 * `woo-organization-accounts-pro` and its `plugins_loaded` callback is registered
 * first — at the same priority it would therefore also *run* first, and every gate
 * below would read an organization plugin that had not booted yet. Running late is
 * what makes `did_action( 'woo_org_accounts_loaded' )` a question worth asking.
 */
define( 'WOIP_BOOT_PRIORITY', 20 );

/**
 * Load the Composer autoloader.
 *
 * A distributed build always ships vendor/, but a git checkout may not have run
 * `composer install` yet. Fail loudly in the admin rather than fatally.
 *
 * @return bool True when the autoloader was loaded.
 */
function load_autoloader() {
	$autoloader = WOIP_PLUGIN_DIR . 'vendor/autoload.php';

	if ( ! is_readable( $autoloader ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );
		return false;
	}

	require_once $autoloader;
	return true;
}

/**
 * Show an admin notice explaining that the Composer dependencies are missing.
 *
 * @return void
 */
function render_missing_autoloader_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'WooCommerce Invoice Payment Method is missing its dependencies. Run "composer install" in the plugin directory.',
			'woo-invoice-payment-method'
		)
	);
}

/**
 * Show an admin notice explaining that WooCommerce is required.
 *
 * @return void
 */
function render_missing_woocommerce_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'WooCommerce Invoice Payment Method requires WooCommerce %s or newer to be installed and active.', 'woo-invoice-payment-method' ),
				WOIP_MIN_WC_VERSION
			)
		)
	);
}

/**
 * Show an admin notice explaining what is wrong with the organization plugin.
 *
 * Three different problems, and they are told apart rather than folded into one
 * message, because the fix for each is different: install it, update it, or go and
 * read the notice it is already showing about its own requirements. "A dependency is
 * missing" would send somebody looking for a plugin they have had installed all along.
 *
 * @return void
 */
function render_organization_plugin_notice() {
	$state = organization_plugin_state();

	if ( 'outdated' === $state ) {
		$message = sprintf(
			/* translators: 1: dependency plugin name, 2: minimum supported version, 3: installed version. */
			__( 'WooCommerce Invoice Payment Method requires %1$s %2$s or newer. Version %3$s is installed.', 'woo-invoice-payment-method' ),
			'WooCommerce Organization Accounts Pro',
			WOIP_MIN_WOAP_VERSION,
			defined( 'WOAP_VERSION' ) ? WOAP_VERSION : ''
		);
	} elseif ( 'inert' === $state ) {
		$message = sprintf(
			/* translators: %s: dependency plugin name. */
			__( '%s is active but is not running — it is waiting on a requirement of its own. Invoice payments stay disabled until it is.', 'woo-invoice-payment-method' ),
			'WooCommerce Organization Accounts Pro'
		);
	} else {
		$message = sprintf(
			/* translators: %s: dependency plugin name. */
			__( 'WooCommerce Invoice Payment Method requires %s to be installed and active. Who may pay by invoice is a property of an organization, so there is nothing to offer without it.', 'woo-invoice-payment-method' ),
			'WooCommerce Organization Accounts Pro'
		);
	}

	printf(
		'<div class="notice notice-error"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
		esc_html( $message ),
		esc_url( admin_url( 'plugins.php' ) ),
		esc_html__( 'Manage plugins', 'woo-invoice-payment-method' )
	);
}

/**
 * Determine whether a supported version of WooCommerce is active.
 *
 * The "Requires Plugins" header covers WordPress 6.5 and newer, but it does not
 * enforce a minimum WooCommerce version, so check that here too.
 *
 * @return bool True when WooCommerce is active and new enough.
 */
function is_woocommerce_supported() {
	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		return false;
	}

	return version_compare( WC_VERSION, WOIP_MIN_WC_VERSION, '>=' );
}

/**
 * What state the organization plugin is in.
 *
 * `Requires Plugins` stops the plugin being *activated* without its dependency, and
 * stops the dependency being deactivated underneath it. It says nothing about version,
 * and nothing at all about whether the dependency actually booted: Organization
 * Accounts Pro defines its constants at the top of its main file and only then decides
 * whether to initialise, so `defined( 'WOAP_VERSION' )` is true on a site where it
 * refused to run for want of HPOS or its theme. `did_action()` on the hook it fires at
 * the end of its own init is the question with a useful answer, and it is also how this
 * plugin inherits every requirement that one enforces without restating any of them.
 *
 * @return string One of "missing", "outdated", "inert" or "ready".
 */
function organization_plugin_state() {
	if ( ! defined( 'WOAP_VERSION' ) ) {
		return 'missing';
	}

	if ( version_compare( WOAP_VERSION, WOIP_MIN_WOAP_VERSION, '<' ) ) {
		return 'outdated';
	}

	return did_action( 'woo_org_accounts_loaded' ) ? 'ready' : 'inert';
}

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * High-Performance Order Storage must be declared before WooCommerce initialises,
 * otherwise the plugin is listed as incompatible and HPOS stays disabled — and the
 * organization plugin this one depends on refuses to boot without HPOS, so a site that
 * believed us incompatible would have neither. The blocks declaration matters just as
 * much: a payment gateway that is not declared compatible with the Cart and Checkout
 * blocks is hidden from the block checkout, which is what a new WooCommerce 11 shop
 * gets by default.
 *
 * @return void
 */
function declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOIP_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOIP_PLUGIN_FILE, true );
}

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * There is deliberately no HPOS gate and no theme gate here, though this plugin needs
 * both: the organization plugin already refuses to boot without them, so the state
 * check below is the same answer arrived at once. Restating them would give a site two
 * notices about one problem, and two places to update when a floor moves.
 *
 * @return void
 */
function bootstrap() {
	if ( ! is_woocommerce_supported() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_woocommerce_notice' );
		return;
	}

	if ( 'ready' !== organization_plugin_state() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_organization_plugin_notice' );
		return;
	}

	Plugin::instance()->init();
}

if ( load_autoloader() ) {
	/*
	 * Registered ahead of the requirement gates, not inside bootstrap(). An update is
	 * often the thing that fixes a plugin sitting inert behind one of those gates, so
	 * a site whose dependency is too old must still be offered the version that works
	 * with it.
	 */
	( new Updates\Updater() )->register();

	add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', WOIP_BOOT_PRIORITY );

	register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
}
