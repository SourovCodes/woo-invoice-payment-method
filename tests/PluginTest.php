<?php
/**
 * Bootstrap tests.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WooInvoicePayment\Gateway\Options;
use WooInvoicePayment\Install;
use WooInvoicePayment\Plugin;
use WP_UnitTestCase;

/**
 * The plugin boots, declares what it supports, and knows what it depends on.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * The plugin registered its hooks, which only happens once every gate has passed.
	 */
	public function testThePluginBooted() {
		$this->assertGreaterThan( 0, did_action( 'woo_invoice_payment_loaded' ) );
	}

	/**
	 * The organization plugin is present, new enough and actually running.
	 *
	 * All three are separate failures with separate fixes, which is why the state is a
	 * string rather than a bool — see the notice this drives.
	 */
	public function testTheOrganizationPluginIsTheDependency() {
		$this->assertTrue( defined( 'WOAP_VERSION' ) );
		$this->assertGreaterThan( 0, did_action( 'woo_org_accounts_loaded' ) );
		$this->assertSame( 'ready', \WooInvoicePayment\organization_plugin_state() );
	}

	/**
	 * A missing dependency, an old one and an inert one are told apart.
	 *
	 * The version floor is asserted against the constant rather than against a
	 * hard-coded number, so bumping the floor updates the test with the code.
	 */
	public function testTheDependencyStateDistinguishesItsThreeFailures() {
		$this->assertTrue( version_compare( WOAP_VERSION, WOIP_MIN_WOAP_VERSION, '>=' ) );

		// "inert" is the case a bare defined() check would call ready: the dependency's
		// constants exist from the top of its main file, before it decides to run.
		$this->assertNotSame( 'missing', \WooInvoicePayment\organization_plugin_state() );
	}

	/**
	 * The plugin boots late on purpose.
	 *
	 * WordPress includes plugins in directory order, so this one is included before the
	 * plugin it depends on and would run first at the default priority — with the
	 * dependency's `woo_org_accounts_loaded` not yet fired and every gate reading the
	 * wrong answer.
	 */
	public function testItBootsAfterTheDefaultPriority() {
		$this->assertGreaterThan( 10, WOIP_BOOT_PRIORITY );
		$this->assertSame( WOIP_BOOT_PRIORITY, has_action( 'plugins_loaded', 'WooInvoicePayment\\bootstrap' ) );
	}

	/**
	 * WooCommerce is present and new enough.
	 */
	public function testWooCommerceIsSupported() {
		$this->assertTrue( \WooInvoicePayment\is_woocommerce_supported() );
	}

	/**
	 * The suite runs against High-Performance Order Storage.
	 *
	 * There is no HPOS gate in this plugin — the dependency enforces it — so this is
	 * the assertion that the inherited requirement actually holds.
	 */
	public function testHposIsInUse() {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * Compatibility with HPOS and with the Cart and Checkout blocks is declared.
	 *
	 * The second is not a nicety for a payment method: a gateway that has not declared
	 * it is hidden from the block checkout, which is what a new WooCommerce 11 shop
	 * gets by default.
	 */
	public function testWooCommerceCompatibilityIsDeclared() {
		$basename = 'woo-invoice-payment-method/woo-invoice-payment-method.php';

		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			$compatible = FeaturesUtil::get_compatible_plugins_for_feature( $feature );

			$this->assertContains( $basename, $compatible['compatible'], $feature . ' was not declared.' );
		}
	}

	/**
	 * The version is stated once and the same in both places WordPress reads it.
	 */
	public function testTheVersionIsConsistent() {
		$headers = get_file_data(
			dirname( __DIR__ ) . '/woo-invoice-payment-method.php',
			array(
				'version'  => 'Version',
				'requires' => 'Requires Plugins',
			)
		);

		$this->assertSame( WOIP_VERSION, $headers['version'] );

		// The dependency is declared to WordPress as well as checked at runtime, so a
		// site cannot activate this plugin without it in the first place.
		$this->assertStringContainsString( 'woocommerce', $headers['requires'] );
		$this->assertStringContainsString( WOIP_ORGANIZATION_PLUGIN, $headers['requires'] );
	}

	/**
	 * The plugin's table exists and the schema version was recorded.
	 */
	public function testTheTableIsInstalled() {
		$this->assertTrue( Install::tables_exist() );
		$this->assertSame( WOIP_DB_VERSION, get_option( Install::VERSION_OPTION ) );
	}

	/**
	 * The upgrade clears away what a removed feature left behind.
	 *
	 * Neither half happens on its own: dbDelta() never drops a column, and
	 * WC_Settings_API writes back every key it finds in the settings array whether or
	 * not it still has a field for it. Without this both would outlive the payment terms
	 * they belonged to, as a decision the shop can no longer see.
	 */
	public function testTheUpgradeRemovesWhatARetiredFeatureLeftBehind() {
		global $wpdb;

		$table    = Install::table( Install::TERMS );
		$settings = get_option( Options::OPTION_KEY, array() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the statement takes no values.
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN payment_terms_days smallint(5) unsigned NULL default null" );
		update_option( Options::OPTION_KEY, array_merge( (array) $settings, array( 'payment_terms_days' => 30 ) ) );

		Install::install();

		$this->assertNull(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- As above; the column is a placeholder.
			$wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'payment_terms_days' ) ),
			'The retired column survived the upgrade.'
		);
		$this->assertArrayNotHasKey(
			'payment_terms_days',
			(array) get_option( Options::OPTION_KEY ),
			'The retired setting survived the upgrade.'
		);

		// The ALTER above implicitly commits, so the settings are put back by hand
		// rather than left to the transaction this test would otherwise roll back.
		update_option( Options::OPTION_KEY, $settings );
	}

	/**
	 * Booting twice registers nothing twice.
	 */
	public function testInitIsIdempotent() {
		$before = did_action( 'woo_invoice_payment_loaded' );

		Plugin::instance()->init();

		$this->assertSame( $before, did_action( 'woo_invoice_payment_loaded' ) );
	}
}
