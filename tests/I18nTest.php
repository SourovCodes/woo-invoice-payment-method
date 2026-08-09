<?php
/**
 * Tests for the translations.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Tests;

use MO;
use PO;
use WooInvoicePayment\Plugin;
use WP_UnitTestCase;

/**
 * Covers the shipped catalogues and the German locale mapping.
 *
 * The plugin is bilingual by requirement, so an untranslated string is a defect rather
 * than a nicety: WordPress falls back to English silently, one label at a time, and
 * nobody notices until a German shop manager is reading half an English screen.
 */
class I18nTest extends WP_UnitTestCase {

	/**
	 * Directory holding the catalogues.
	 *
	 * @var string
	 */
	private $languages;

	/**
	 * Locate the language directory and WordPress's PO/MO readers.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->languages = dirname( __DIR__ ) . '/languages';

		require_once ABSPATH . WPINC . '/pomo/po.php';
		require_once ABSPATH . WPINC . '/pomo/mo.php';
	}

	/**
	 * Restore English between tests, whatever a test switched to.
	 *
	 * @return void
	 */
	public function tear_down() {
		/*
		 * Only the catalogue is dropped, so the next test loads it for whatever locale
		 * it asks for. The `locale` filter is not touched here: with_locale() removes
		 * its own in a finally block, and clearing the hook wholesale would also
		 * discard filters that belong to WordPress or to another test.
		 */
		unload_textdomain( 'woo-invoice-payment-method', true );

		parent::tear_down();
	}

	/**
	 * Run a callback with WordPress reporting the given locale.
	 *
	 * `switch_to_locale()` is not usable here: it returns false for any locale whose
	 * *core* language pack is not installed, and the test site has none. Filtering
	 * `locale` and dropping the loaded catalogue drives the same just-in-time loading
	 * path WordPress uses on a real site — including the mofile filter this plugin
	 * hangs the German fallback on.
	 *
	 * @param string   $locale   Locale to pretend the site is running.
	 * @param callable $callback Assertions to run.
	 * @return void
	 */
	private function with_locale( $locale, callable $callback ) {
		$filter = static function () use ( $locale ) {
			return $locale;
		};

		add_filter( 'locale', $filter );
		unload_textdomain( 'woo-invoice-payment-method', true );

		try {
			$callback();
		} finally {
			remove_filter( 'locale', $filter );
			unload_textdomain( 'woo-invoice-payment-method', true );
		}
	}

	/**
	 * The locales the plugin maintains a catalogue for.
	 *
	 * @return array
	 */
	public function german_locales() {
		return array(
			'informal' => array( Plugin::GERMAN_INFORMAL ),
			'formal'   => array( Plugin::GERMAN_FORMAL ),
		);
	}

	/**
	 * Read a PO file.
	 *
	 * @param string $file Path.
	 * @return PO
	 */
	private function read_po( $file ) {
		$po = new PO();
		$po->import_from_file( $file );

		return $po;
	}

	/**
	 * Whether an entry is plugin header metadata rather than an interface string.
	 *
	 * The plugin name, its URI and the author are not translated: a plugin is listed
	 * under one name in every language, and translating a URL is meaningless.
	 *
	 * @param object $entry PO entry.
	 * @return bool
	 */
	private function is_metadata( $entry ) {
		foreach ( (array) $entry->extracted_comments as $comment ) {
			if ( preg_match( '/^(Plugin Name|Plugin URI|Author|Author URI) of the plugin$/m', trim( $comment ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every catalogue the plugin ships is present in all three forms.
	 *
	 * The .po is the source, the .mo is the catalogue older readers expect, and the
	 * .l10n.php is what WordPress 6.5 and newer actually loads. Shipping the source
	 * without the compiled files means shipping no translation at all.
	 *
	 * @dataProvider german_locales
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	public function test_catalogue_is_compiled( $locale ) {
		foreach ( array( 'po', 'mo', 'l10n.php' ) as $extension ) {
			$file = sprintf( '%s/woo-invoice-payment-method-%s.%s', $this->languages, $locale, $extension );

			$this->assertFileExists( $file );
			$this->assertGreaterThan( 0, filesize( $file ), $file . ' is empty.' );
		}
	}

	/**
	 * Every string extracted from the source is translated in every catalogue.
	 *
	 * @dataProvider german_locales
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	public function test_catalogue_is_complete( $locale ) {
		$pot = $this->read_po( $this->languages . '/woo-invoice-payment-method.pot' );
		$po  = $this->read_po( sprintf( '%s/woo-invoice-payment-method-%s.po', $this->languages, $locale ) );

		$this->assertNotEmpty( $pot->entries, 'The POT is empty; run ./bin/make-translations.sh.' );

		$untranslated = array();
		$absent       = array();

		foreach ( $pot->entries as $key => $entry ) {
			if ( $this->is_metadata( $entry ) ) {
				continue;
			}

			if ( ! isset( $po->entries[ $key ] ) ) {
				$absent[] = $entry->singular;
				continue;
			}

			$translated = $po->entries[ $key ];

			foreach ( (array) $translated->translations as $translation ) {
				if ( '' === trim( (string) $translation ) ) {
					$untranslated[] = $entry->singular;
					break;
				}
			}

			if ( in_array( 'fuzzy', (array) $translated->flags, true ) ) {
				$untranslated[] = $entry->singular;
			}
		}

		$this->assertSame(
			array(),
			$absent,
			sprintf( 'Strings missing from the %s catalogue. Run ./bin/make-translations.sh.', $locale )
		);

		$this->assertSame(
			array(),
			$untranslated,
			sprintf( 'Strings still untranslated (or fuzzy) in %s.', $locale )
		);
	}

	/**
	 * The compiled catalogue carries the same translations as its source.
	 *
	 * Editing a .po without recompiling is the easiest way to ship a translation that
	 * looks complete in the repository and does nothing on the site.
	 *
	 * @dataProvider german_locales
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	public function test_compiled_catalogue_matches_its_source( $locale ) {
		$po = $this->read_po( sprintf( '%s/woo-invoice-payment-method-%s.po', $this->languages, $locale ) );

		$mo = new MO();
		$mo->import_from_file( sprintf( '%s/woo-invoice-payment-method-%s.mo', $this->languages, $locale ) );

		$stale = array();

		foreach ( $po->entries as $key => $entry ) {
			if ( ! array_filter( (array) $entry->translations, 'strlen' ) ) {
				continue;
			}

			if ( ! isset( $mo->entries[ $key ] ) || $mo->entries[ $key ]->translations !== $entry->translations ) {
				$stale[] = $entry->singular;
			}
		}

		$this->assertSame(
			array(),
			$stale,
			sprintf( 'The compiled %s catalogue is out of date. Run ./bin/make-translations.sh.', $locale )
		);

		// The PHP catalogue is what WordPress prefers, so it has to agree as well.
		$php = include sprintf( '%s/woo-invoice-payment-method-%s.l10n.php', $this->languages, $locale );

		$this->assertIsArray( $php );
		$this->assertArrayHasKey( 'messages', $php );
		$this->assertSame( 'Kauf auf Rechnung', $php['messages']['Pay by invoice'] );
	}

	/**
	 * Switching to German translates the interface.
	 *
	 * @return void
	 */
	public function test_german_translates_the_interface() {
		$this->assertSame( 'Pay by invoice', __( 'Pay by invoice', 'woo-invoice-payment-method' ) );

		$this->with_locale(
			Plugin::GERMAN_INFORMAL,
			function () {
				$this->assertSame( 'Kauf auf Rechnung', __( 'Pay by invoice', 'woo-invoice-payment-method' ) );
				$this->assertSame( 'Rechnung fällig', __( 'Invoice due', 'woo-invoice-payment-method' ) );
			}
		);

		// English is what an unswitched site still gets.
		$this->assertSame( 'Pay by invoice', __( 'Pay by invoice', 'woo-invoice-payment-method' ) );
	}

	/**
	 * Everything the customer reads at the checkout is translated in both registers.
	 *
	 * These are the strings a German shop's customers actually see, and the ones most
	 * likely to be left half-translated: they are scattered across the gateway, the
	 * block integration and the order screens rather than sitting together.
	 *
	 * @return void
	 */
	public function test_the_checkout_is_translated_in_both_registers() {
		foreach ( array( Plugin::GERMAN_INFORMAL, Plugin::GERMAN_FORMAL ) as $locale ) {
			$this->with_locale(
				$locale,
				function () use ( $locale ) {
					$this->assertSame( 'Kauf auf Rechnung', __( 'Pay by invoice', 'woo-invoice-payment-method' ), $locale );
					$this->assertSame( 'Zahlungsziel', __( 'Payment terms', 'woo-invoice-payment-method' ), $locale );
					$this->assertSame( 'Kreditlimit', __( 'Credit limit', 'woo-invoice-payment-method' ), $locale );
					$this->assertSame( 'Bestellreferenz', __( 'Purchase order number', 'woo-invoice-payment-method' ), $locale );
					$this->assertSame( 'Rechnung fällig', __( 'Invoice due', 'woo-invoice-payment-method' ), $locale );
					$this->assertSame(
						'Zahlbar sofort nach Erhalt.',
						__( 'Payable on receipt.', 'woo-invoice-payment-method' ),
						$locale
					);
				}
			);
		}
	}

	/**
	 * Both plural forms of the payment terms are translated.
	 *
	 * German needs two, and a catalogue carrying only the singular reads correctly
	 * everywhere except the case that is almost always the one on screen.
	 *
	 * @return void
	 */
	public function test_the_payment_terms_have_both_plural_forms() {
		$this->with_locale(
			Plugin::GERMAN_INFORMAL,
			function () {
				$this->assertSame(
					'Zahlbar innerhalb von 1 Tag ab Rechnungsdatum.',
					/* translators: %d: number of days the invoice is payable within. */
					sprintf( _n( 'Payable within %d day of the invoice date.', 'Payable within %d days of the invoice date.', 1, 'woo-invoice-payment-method' ), 1 )
				);
				$this->assertSame(
					'Zahlbar innerhalb von 30 Tagen ab Rechnungsdatum.',
					/* translators: %d: number of days the invoice is payable within. */
					sprintf( _n( 'Payable within %d day of the invoice date.', 'Payable within %d days of the invoice date.', 30, 'woo-invoice-payment-method' ), 30 )
				);
			}
		);
	}

	/**
	 * The informal and formal catalogues address the reader differently.
	 *
	 * This is the whole reason for shipping two of them: WordPress's own de_DE says
	 * "du", and a plugin saying "Sie" in the middle of it reads as a different product.
	 *
	 * @return void
	 */
	public function test_registers_differ_between_the_two_catalogues() {
		$this->with_locale(
			Plugin::GERMAN_INFORMAL,
			function () {
				$this->assertStringStartsWith(
					'Du hast',
					__( 'You do not have permission to do that.', 'woo-invoice-payment-method' )
				);
				$this->assertStringStartsWith(
					'Du erhältst',
					__( 'Receive an invoice with your delivery and pay it within the agreed terms.', 'woo-invoice-payment-method' )
				);
			}
		);

		$this->with_locale(
			Plugin::GERMAN_FORMAL,
			function () {
				$this->assertStringStartsWith(
					'Sie haben',
					__( 'You do not have permission to do that.', 'woo-invoice-payment-method' )
				);
				$this->assertStringStartsWith(
					'Sie erhalten',
					__( 'Receive an invoice with your delivery and pay it within the agreed terms.', 'woo-invoice-payment-method' )
				);
			}
		);
	}

	/**
	 * Austrian and Swiss shops get German rather than English.
	 *
	 * @return void
	 */
	public function test_other_german_locales_fall_back_to_a_shipped_catalogue() {
		foreach ( array( 'de_AT', 'de_CH', 'de_CH_informal' ) as $locale ) {
			$this->with_locale(
				$locale,
				function () use ( $locale ) {
					$this->assertSame(
						'Kauf auf Rechnung',
						__( 'Pay by invoice', 'woo-invoice-payment-method' ),
						$locale . ' did not fall back to the German catalogue.'
					);
				}
			);
		}

		// A locale with no catalogue of ours is still English rather than German.
		$this->with_locale(
			'fr_FR',
			function () {
				$this->assertSame( 'Pay by invoice', __( 'Pay by invoice', 'woo-invoice-payment-method' ) );
			}
		);
	}

	/**
	 * The fallback leaves everything that is not a missing German catalogue alone.
	 *
	 * @return void
	 */
	public function test_locale_mapping_is_narrow() {
		$plugin = Plugin::instance();
		$path   = $this->languages . '/woo-invoice-payment-method-%s.mo';

		// Another plugin's catalogue is none of our business.
		$other = sprintf( $path, 'de_AT' );
		$this->assertSame( $other, $plugin->map_german_locale( $other, 'some-other-plugin' ) );

		// A locale that exists is loaded as asked.
		$existing = sprintf( $path, Plugin::GERMAN_FORMAL );
		$this->assertSame( $existing, $plugin->map_german_locale( $existing, 'woo-invoice-payment-method' ) );

		// A missing non-German catalogue is left missing rather than turned into German.
		$french = sprintf( $path, 'fr_FR' );
		$this->assertSame( $french, $plugin->map_german_locale( $french, 'woo-invoice-payment-method' ) );

		// A missing German one is redirected, formal to formal.
		$this->assertSame(
			sprintf( $path, Plugin::GERMAN_INFORMAL ),
			$plugin->map_german_locale( sprintf( $path, 'de_AT' ), 'woo-invoice-payment-method' )
		);
		$this->assertSame(
			sprintf( $path, Plugin::GERMAN_FORMAL ),
			$plugin->map_german_locale( sprintf( $path, 'de_AT_formal' ), 'woo-invoice-payment-method' )
		);
	}
}
