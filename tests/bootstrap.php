<?php
/**
 * PHPUnit bootstrap.
 *
 * Uses the wp-phpunit package for the WordPress test library, so no SVN checkout
 * of core is required. Run bin/install-wp-tests.sh once first: it creates the test
 * database and generates tests/wp-tests-config.php.
 *
 * @package WooInvoicePayment
 */

$woip_plugin_dir = dirname( __DIR__ );
$woip_tests_dir  = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $woip_tests_dir ) {
	$woip_tests_dir = $woip_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $woip_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library. Run 'composer install' first." . PHP_EOL;
	exit( 1 );
}

if ( ! file_exists( __DIR__ . '/wp-tests-config.php' ) ) {
	echo "Missing tests/wp-tests-config.php. Run './bin/install-wp-tests.sh' first." . PHP_EOL;
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $woip_plugin_dir . '/vendor/autoload.php';
require_once $woip_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce, the organization plugin and this plugin into the test site.
 *
 * All three come from the WordPress install the tests run against, so the suite
 * exercises the same versions the development site runs.
 *
 * @return void
 */
function woip_manually_load_plugins() {
	$woocommerce = ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';

	if ( ! file_exists( $woocommerce ) ) {
		echo 'WooCommerce was not found at ' . $woocommerce . PHP_EOL;
		exit( 1 );
	}

	/*
	 * The organization plugin requires High-Performance Order Storage and refuses to
	 * boot without it — and this plugin refuses to boot without that one, so the suite
	 * has to run with HPOS enabled or nothing loads at all. Set this before WooCommerce
	 * loads: its data stores read the option while initialising.
	 */
	update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );

	require_once $woocommerce;

	/*
	 * Woodmart is a commercial theme and cannot be installed into the test library or
	 * into CI. The organization plugin's own escape hatch is the supported way to say
	 * "the theme is there", and it has to be filtered before that plugin loads, because
	 * its gate runs on plugins_loaded.
	 */
	add_filter( 'woap_theme_supported', '__return_true' );

	/*
	 * Load each plugin the way WordPress loads it: through wp-content/plugins, having
	 * registered the real path behind the symlink first. Requiring a checkout directly
	 * instead leaves plugin_basename() unable to shorten the path to the plugin slug,
	 * and everything keyed on that slug then behaves differently under test than in
	 * production — the HPOS compatibility declaration is recorded under an absolute
	 * path, and load_plugin_textdomain() registers a languages directory that does not
	 * exist.
	 *
	 * Order matters, and only in the file-inclusion sense: both plugins boot on
	 * plugins_loaded, and this one asks to run at a later priority precisely so it does
	 * not depend on which file was included first.
	 */
	$plugins = array(
		'woo-organization-accounts-pro' => WP_PLUGIN_DIR . '/woo-organization-accounts-pro/woo-organization-accounts-pro.php',
		'woo-invoice-payment-method'    => WP_PLUGIN_DIR . '/woo-invoice-payment-method/woo-invoice-payment-method.php',
	);

	foreach ( $plugins as $slug => $plugin ) {
		if ( ! file_exists( $plugin ) ) {
			echo $slug . ' was not found at ' . $plugin . '.' . PHP_EOL;
			echo 'Install or link it there and try again.' . PHP_EOL;
			exit( 1 );
		}

		wp_register_plugin_realpath( $plugin );

		require_once $plugin;
	}
}
tests_add_filter( 'muplugins_loaded', 'woip_manually_load_plugins' );

/**
 * Install the WooCommerce database tables, both plugins' tables, and the roles.
 *
 * @return void
 */
function woip_install_dependencies() {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	// Suppress the "installed" notices WC_Install emits while creating tables.
	$_SERVER['REQUEST_URI'] = '/';
	WC_Install::install();

	/*
	 * WC_Install() does not provision the orders tables here, because the features
	 * controller resolved before the suite enabled HPOS. Create them explicitly, or
	 * every order touched by a test raises "Table wptests_wc_orders doesn't exist".
	 */
	$synchronizer = \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class;

	if ( class_exists( $synchronizer ) && function_exists( 'wc_get_container' ) ) {
		wc_get_container()->get( $synchronizer )->create_database_tables();
	}

	/*
	 * Both plugins' tables and the organization roles. Creating them here rather than
	 * leaving it to the activation hooks — which the suite never fires — means every
	 * test starts against the schema the plugins actually ship, and a CREATE TABLE
	 * cannot land inside the transaction each test is wrapped in.
	 */
	\WooOrgAccounts\Install::install();
	\WooOrgAccounts\Roles::install();
	\WooInvoicePayment\Install::install();

	// WC_Install and Roles::install() both add roles, so the global has to be rebuilt.
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}
tests_add_filter( 'setup_theme', 'woip_install_dependencies' );

require $woip_tests_dir . '/includes/bootstrap.php';
