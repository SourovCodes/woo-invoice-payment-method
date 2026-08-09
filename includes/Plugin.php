<?php
/**
 * Plugin bootstrap.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment;

use WooInvoicePayment\Admin\OrganizationTerms;
use WooInvoicePayment\Gateway\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * The plugin's text domain.
	 *
	 * @var string
	 */
	const TEXT_DOMAIN = 'woo-invoice-payment-method';

	/**
	 * The German catalogues that ship with the plugin.
	 *
	 * WordPress treats every German locale as unrelated to the others and never falls
	 * back between them, so `de_AT` finds no catalogue and shows English however
	 * complete the German translation is. These two are the ones that are actually
	 * maintained; map_german_locale() points the rest at whichever matches their
	 * register.
	 *
	 * @var string
	 */
	const GERMAN_INFORMAL = 'de_DE';
	const GERMAN_FORMAL   = 'de_DE_formal';

	/**
	 * The single shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already registered the plugin's hooks.
	 *
	 * @var bool
	 */
	private $initialised = false;

	/**
	 * Use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'load_textdomain_mofile', array( $this, 'map_german_locale' ), 10, 2 );

		/*
		 * WordPress updates a plugin by unpacking the new files over the old ones
		 * without deactivating it, so activation is not enough on its own to get a
		 * schema change onto a live site.
		 */
		Install::maybe_upgrade();

		/*
		 * Not gated on is_admin(): a gateway is asked whether it is available on every
		 * cart and checkout render, and the Store API answers the block checkout from a
		 * REST request that is neither an admin request nor a page load.
		 */
		( new Gateways() )->register();

		if ( is_admin() ) {
			( new OrganizationTerms() )->register();
		}

		/**
		 * Fires once the plugin has registered its own hooks.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'woo_invoice_payment_loaded', $this );
	}

	/**
	 * Load the plugin translations.
	 *
	 * Hooked to `init` because loading a text domain earlier is deprecated as of
	 * WordPress 6.7.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WOIP_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Serve the German catalogues to every German locale.
	 *
	 * The plugin ships `de_DE` and `de_DE_formal`. A shop set to `de_AT`, `de_CH` or
	 * `de_CH_informal` asks for a catalogue that does not exist and silently falls back
	 * to English — WordPress has no notion of one German locale being close to another.
	 * This points those requests at the catalogue matching their register instead, which
	 * is far better than an English admin screen for an Austrian shop.
	 *
	 * Filtering the `.mo` path is enough to bring the `.l10n.php` along: WordPress
	 * derives that filename from whatever this returns. A locale that does have its own
	 * catalogue — including one a site owner dropped into `wp-content/languages/plugins`
	 * — is left alone.
	 *
	 * @param string $mofile Path to the catalogue WordPress is about to load.
	 * @param string $domain Text domain being loaded.
	 * @return string
	 */
	public function map_german_locale( $mofile, $domain ) {
		if ( self::TEXT_DOMAIN !== $domain || file_exists( $mofile ) ) {
			return $mofile;
		}

		if ( ! preg_match( '/-(de(?:_[A-Za-z]+)*)\.mo$/', basename( $mofile ), $matches ) ) {
			return $mofile;
		}

		$locale   = $matches[1];
		$register = str_ends_with( $locale, '_formal' ) ? self::GERMAN_FORMAL : self::GERMAN_INFORMAL;
		$fallback = dirname( $mofile ) . '/' . self::TEXT_DOMAIN . '-' . $register . '.mo';

		return file_exists( $fallback ) ? $fallback : $mofile;
	}
}
