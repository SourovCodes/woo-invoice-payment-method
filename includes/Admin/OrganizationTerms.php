<?php
/**
 * The per-organization invoice terms screen.
 *
 * @package WooInvoicePayment
 */

namespace WooInvoicePayment\Admin;

use WooInvoicePayment\Credit;
use WooInvoicePayment\Data\Terms;
use WooInvoicePayment\Data\TermsRepository;
use WooInvoicePayment\Gateway\InvoiceGateway;
use WooInvoicePayment\Gateway\Options;
use WooInvoicePayment\Updates\Updater;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Who may buy on invoice, and up to how much.
 *
 * The gateway's own settings screen answers the questions that are the same for every
 * customer. This one answers the questions that are not, and it is a separate screen
 * for the same reason: a credit limit is an agreement with one customer, and a
 * payments settings panel is not where anybody looks for it.
 *
 * **Editing one organization is its own screen**, reached by `?woip_organization=<id>`,
 * rather than a form folded underneath the list. The row being edited otherwise lives
 * only in a query argument the form does not post back, so a rejected submission
 * returns as a blank form and saving again writes the wrong organization's terms.
 *
 * The one setting whose stored form is ambiguous — the credit limit — is asked as a
 * radio naming the answer plus a field for the number, never as a field whose emptiness
 * has to be read as a decision. "No credit limit" and "a limit of zero" are opposite
 * answers, and a blank box cannot say which one was meant.
 */
class OrganizationTerms {

	/**
	 * Menu slug of the screen.
	 */
	const PAGE_SLUG = 'woo-invoice-payment-terms';

	/**
	 * Capability required to view or change any of this.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Query argument naming the organization being edited.
	 */
	const EDIT_ARG = 'woip_organization';

	/**
	 * Organizations shown per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_woip_save_terms', array( $this, 'handle_save' ) );
		add_action( 'admin_post_woip_check_updates', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Load the admin stylesheet, and only on this screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'woip-admin',
			WOIP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WOIP_VERSION
		);
	}

	/**
	 * Add the screen under the WooCommerce menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Invoice Payments', 'woo-invoice-payment-method' ),
			__( 'Invoice Payments', 'woo-invoice-payment-method' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of the list.
	 *
	 * @param array $args Extra query arguments.
	 * @return string URL.
	 */
	public static function list_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of one organization's terms.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string URL.
	 */
	public static function edit_url( $organization_id ) {
		return self::list_url( array( self::EDIT_ARG => absint( $organization_id ) ) );
	}

	/**
	 * Render whichever screen the request asked for.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which record to show. Nothing is changed here, and every write goes through handle_save().
		$editing = isset( $_GET[ self::EDIT_ARG ] ) ? absint( wp_unslash( $_GET[ self::EDIT_ARG ] ) ) : 0;

		echo '<div class="wrap woip-terms">';

		$this->render_notice();

		if ( $editing > 0 ) {
			$this->render_form( $editing );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Print the notice a redirect asked for.
	 *
	 * Read from a fixed list rather than from the URL, so the only messages that can
	 * appear are the ones written here.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which of the messages below to print; it changes nothing.
		$notice = isset( $_GET['woip_notice'] ) ? sanitize_key( wp_unslash( $_GET['woip_notice'] ) ) : '';

		$messages = array(
			'saved'   => array( 'success', __( 'The invoice terms were saved.', 'woo-invoice-payment-method' ) ),
			'cleared' => array( 'success', __( 'The invoice terms were cleared. This account now follows the payment method setting.', 'woo-invoice-payment-method' ) ),
			'unknown' => array( 'error', __( 'That account no longer exists.', 'woo-invoice-payment-method' ) ),
			'invalid' => array( 'error', __( 'The credit limit has to be a number, and it cannot be negative.', 'woo-invoice-payment-method' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Print the list of organizations and what each may do.
	 *
	 * @return void
	 */
	private function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Search and paging arguments; they change nothing.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		$total         = OrganizationRepository::count( array( 'search' => $search ) );
		$organizations = OrganizationRepository::query(
			array(
				'search' => $search,
				'limit'  => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			)
		);

		$terms = TermsRepository::find_many(
			array_map(
				static function ( Organization $organization ) {
					return $organization->get_id();
				},
				$organizations
			)
		);

		printf( '<h1>%s</h1>', esc_html__( 'Invoice Payments', 'woo-invoice-payment-method' ) );

		$this->render_intro();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PAGE_SLUG ) );
		printf(
			'<p class="search-box"><label class="screen-reader-text" for="woip-search">%1$s</label><input type="search" id="woip-search" name="s" value="%2$s"> <button type="submit" class="button">%1$s</button></p>',
			esc_html__( 'Search accounts', 'woo-invoice-payment-method' ),
			esc_attr( $search )
		);
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html( Labels::organization() ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Account status', 'woo-invoice-payment-method' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Invoicing', 'woo-invoice-payment-method' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Credit limit', 'woo-invoice-payment-method' ) );
		echo '</tr></thead><tbody>';

		if ( empty( $organizations ) ) {
			printf(
				'<tr><td colspan="4">%s</td></tr>',
				esc_html(
					sprintf(
						/* translators: %s: the plural organization noun for the site's mode. */
						__( 'No %s to show.', 'woo-invoice-payment-method' ),
						Labels::organizations()
					)
				)
			);
		}

		foreach ( $organizations as $organization ) {
			$this->render_row( $organization, $terms[ $organization->get_id() ] );
		}

		echo '</tbody></table>';

		$this->render_pagination( $total, $paged, $search );
		$this->render_updates_section();
	}

	/**
	 * Render the update status and the manual re-check.
	 *
	 * Gated on `update_plugins` rather than on this screen's own capability: a shop
	 * manager may agree credit limits here but may not install a plugin, so the whole
	 * section is hidden from them rather than offering an update they could not apply.
	 *
	 * @return void
	 */
	private function render_updates_section() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = Updater::status();

		printf( '<h2>%s</h2>', esc_html__( 'Updates', 'woo-invoice-payment-method' ) );

		if ( 'available' === $status['state'] ) {
			$message = sprintf(
				/* translators: 1: available version, 2: installed version. */
				__( 'Version %1$s is available. You are running %2$s.', 'woo-invoice-payment-method' ),
				$status['version'],
				WOIP_VERSION
			);
		} elseif ( 'current' === $status['state'] ) {
			$message = sprintf(
				/* translators: %s: installed version. */
				__( 'You are running the latest version, %s.', 'woo-invoice-payment-method' ),
				WOIP_VERSION
			);
		} else {
			$message = __( 'The last update check did not complete. GitHub may have been unreachable.', 'woo-invoice-payment-method' );
		}

		printf( '<p>%s</p>', esc_html( $message ) );

		printf( '<form action="%s" method="post">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woip_check_updates">';
		wp_nonce_field( 'woip_check_updates' );
		submit_button( __( 'Check for updates', 'woo-invoice-payment-method' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle the "Check for updates" button.
	 *
	 * @return void
	 */
	public function handle_check_updates() {
		check_admin_referer( 'woip_check_updates' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'woo-invoice-payment-method' ),
				esc_html__( 'Permission denied', 'woo-invoice-payment-method' ),
				array( 'response' => 403 )
			);
		}

		( new Updater() )->refresh();

		wp_safe_redirect( self::list_url() );
		exit;
	}

	/**
	 * Explain what the screen decides and what it does not.
	 *
	 * @return void
	 */
	private function render_intro() {
		$availability = Options::AVAILABILITY_ALL === Options::availability()
			? sprintf(
				/* translators: %s: the plural organization noun for the site's mode. */
				__( 'Invoicing is currently offered to every %s that can place orders, except the ones refused it below.', 'woo-invoice-payment-method' ),
				Labels::organizations()
			)
			: sprintf(
				/* translators: %s: the plural organization noun for the site's mode. */
				__( 'Invoicing is currently offered only to the %s allowed it below.', 'woo-invoice-payment-method' ),
				Labels::organizations()
			);

		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html( $availability ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . InvoiceGateway::ID ) ),
			esc_html__( 'Payment method settings', 'woo-invoice-payment-method' )
		);

		if ( ! Options::is_enabled() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The Pay by invoice payment method is switched off, so nothing here is offered at the checkout yet.', 'woo-invoice-payment-method' )
			);
		}
	}

	/**
	 * Print one organization's row.
	 *
	 * @param Organization $organization The organization.
	 * @param Terms        $terms        Its invoice terms.
	 * @return void
	 */
	private function render_row( Organization $organization, Terms $terms ) {
		$limit = $terms->get_credit_limit();

		echo '<tr>';

		printf(
			'<td><strong><a href="%1$s">%2$s</a></strong></td>',
			esc_url( self::edit_url( $organization->get_id() ) ),
			esc_html( $organization->get_name() )
		);

		printf( '<td>%s</td>', esc_html( $organization->get_status_label() ) );

		printf(
			'<td><span class="woip-status woip-status--%1$s">%2$s</span></td>',
			esc_attr( $terms->get_status() ),
			esc_html( $this->invoicing_label( $terms ) )
		);

		printf(
			'<td>%s</td>',
			esc_html( null === $limit ? __( 'No limit', 'woo-invoice-payment-method' ) : Credit::format( (float) $limit ) )
		);

		echo '</tr>';
	}

	/**
	 * What an organization's invoicing status amounts to right now.
	 *
	 * The stored status alone is not the answer when it is "follow the payment method
	 * setting" — reporting that back is reporting the question.
	 *
	 * @param Terms $terms The terms.
	 * @return string Translated label.
	 */
	private function invoicing_label( Terms $terms ) {
		if ( Terms::STATUS_ALLOWED === $terms->get_status() ) {
			return __( 'Allowed', 'woo-invoice-payment-method' );
		}

		if ( Terms::STATUS_BLOCKED === $terms->get_status() ) {
			return __( 'Refused', 'woo-invoice-payment-method' );
		}

		return Options::AVAILABILITY_ALL === Options::availability()
			? __( 'Allowed by default', 'woo-invoice-payment-method' )
			: __( 'Not offered by default', 'woo-invoice-payment-method' );
	}

	/**
	 * Print the paging links.
	 *
	 * @param int    $total  Total matching organizations.
	 * @param int    $paged  Current page.
	 * @param string $search Current search term.
	 * @return void
	 */
	private function render_pagination( $total, $paged, $search ) {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => self::list_url( array( 's' => $search ) ) . '%_%',
				'format'    => '&paged=%#%',
				'current'   => $paged,
				'total'     => $pages,
				'type'      => 'array',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);

		if ( empty( $links ) ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';

		foreach ( $links as $link ) {
			echo wp_kses_post( $link );
		}

		echo '</div></div>';
	}

	/**
	 * Print the form for one organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return void
	 */
	private function render_form( $organization_id ) {
		$organization = OrganizationRepository::find( $organization_id );

		if ( ! $organization instanceof Organization ) {
			printf( '<h1>%s</h1>', esc_html__( 'Invoice Payments', 'woo-invoice-payment-method' ) );
			printf( '<p>%s</p>', esc_html__( 'That account no longer exists.', 'woo-invoice-payment-method' ) );
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( self::list_url() ),
				esc_html__( 'Back to the list', 'woo-invoice-payment-method' )
			);

			return;
		}

		$terms       = TermsRepository::find( $organization_id );
		$outstanding = Credit::outstanding( $organization_id );

		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: %s: the organization's name. */
					__( 'Invoice terms for %s', 'woo-invoice-payment-method' ),
					$organization->get_name()
				)
			)
		);

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: formatted amount currently outstanding on unpaid invoices. */
					__( 'Currently outstanding on unpaid invoices: %s.', 'woo-invoice-payment-method' ),
					Credit::format( $outstanding )
				)
			)
		);

		printf( '<form action="%s" method="post">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woip_save_terms">';
		printf( '<input type="hidden" name="organization_id" value="%d">', absint( $organization_id ) );
		wp_nonce_field( 'woip_save_terms' );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_status_row( $terms );
		$this->render_credit_limit_row( $terms );
		$this->render_note_row( $terms );

		echo '</tbody></table>';

		submit_button( __( 'Save invoice terms', 'woo-invoice-payment-method' ) );

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::list_url() ),
			esc_html__( 'Back to the list', 'woo-invoice-payment-method' )
		);

		echo '</form>';
	}

	/**
	 * The invoicing status radios.
	 *
	 * @param Terms $terms The terms.
	 * @return void
	 */
	private function render_status_row( Terms $terms ) {
		printf( '<tr><th scope="row">%s</th><td><fieldset>', esc_html__( 'Invoicing', 'woo-invoice-payment-method' ) );

		foreach ( Terms::statuses() as $value => $label ) {
			printf(
				'<label><input type="radio" name="status" value="%1$s"%2$s> %3$s</label><br>',
				esc_attr( $value ),
				checked( $terms->get_status(), $value, false ),
				esc_html( $label )
			);
		}

		printf(
			'</fieldset><p class="description">%s</p></td></tr>',
			esc_html__( 'The first answer follows whatever the payment method is set to offer. The other two are decisions about this account and outlive a change to that setting.', 'woo-invoice-payment-method' )
		);
	}

	/**
	 * The credit limit choice.
	 *
	 * @param Terms $terms The terms.
	 * @return void
	 */
	private function render_credit_limit_row( Terms $terms ) {
		$limited = $terms->has_credit_limit();

		printf( '<tr><th scope="row">%s</th><td><fieldset class="woip-choice">', esc_html__( 'Credit limit', 'woo-invoice-payment-method' ) );

		printf(
			'<label><input type="radio" name="credit_mode" value="none"%1$s> %2$s</label><br>',
			checked( $limited, false, false ),
			esc_html__( 'No limit', 'woo-invoice-payment-method' )
		);

		printf(
			'<label><input type="radio" name="credit_mode" value="limited"%1$s> %2$s</label> <input type="text" name="credit_limit" class="regular-text woip-amount" value="%3$s" inputmode="decimal"> %4$s',
			checked( $limited, true, false ),
			esc_html__( 'Owe no more than:', 'woo-invoice-payment-method' ),
			esc_attr( $limited ? wc_format_localized_price( (string) $terms->get_credit_limit() ) : '' ),
			esc_html( get_woocommerce_currency_symbol() )
		);

		printf(
			'</fieldset><p class="description">%s</p></td></tr>',
			esc_html__( 'Checked against every unpaid invoice order this account has open, when it tries to place another. A limit of zero refuses new invoices while anything is outstanding, which is not the same as no limit.', 'woo-invoice-payment-method' )
		);
	}

	/**
	 * The shop's own note about the arrangement.
	 *
	 * @param Terms $terms The terms.
	 * @return void
	 */
	private function render_note_row( Terms $terms ) {
		printf( '<tr><th scope="row">%s</th><td>', esc_html__( 'Note', 'woo-invoice-payment-method' ) );

		printf(
			'<textarea name="note" rows="3" class="large-text" maxlength="500">%s</textarea>',
			esc_textarea( $terms->get_note() )
		);

		printf(
			'<p class="description">%s</p></td></tr>',
			esc_html__( 'For the shop only. The customer never sees it.', 'woo-invoice-payment-method' )
		);
	}

	/**
	 * Save one organization's terms.
	 *
	 * @return void
	 */
	public function handle_save() {
		check_admin_referer( 'woip_save_terms' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'woo-invoice-payment-method' ),
				esc_html__( 'Permission denied', 'woo-invoice-payment-method' ),
				array( 'response' => 403 )
			);
		}

		$organization_id = isset( $_POST['organization_id'] ) ? absint( wp_unslash( $_POST['organization_id'] ) ) : 0;

		if ( ! OrganizationRepository::find( $organization_id ) instanceof Organization ) {
			$this->redirect( 0, 'unknown' );
		}

		$status      = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : Terms::STATUS_DEFAULT;
		$credit_mode = isset( $_POST['credit_mode'] ) ? sanitize_key( wp_unslash( $_POST['credit_mode'] ) ) : 'none';
		$note        = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$limit = null;

		if ( 'limited' === $credit_mode ) {
			$raw = isset( $_POST['credit_limit'] ) ? sanitize_text_field( wp_unslash( $_POST['credit_limit'] ) ) : '';

			/*
			 * wc_format_decimal() is what turns whatever the shop's locale writes a
			 * number as — 1.234,56 as readily as 1,234.56 — into something comparable
			 * with an order total.
			 *
			 * It is called twice on purpose. Asked for a number of decimal places it
			 * runs the result through number_format(), and number_format() turns
			 * anything that was never a number into 0.00 — which here would mean "this
			 * customer may never buy on invoice again" rather than "that was not a
			 * number". Without the decimals it returns an empty string instead, which
			 * is a refusal that can be told apart from the perfectly valid answer of
			 * zero.
			 */
			$normalised = wc_format_decimal( $raw );

			if ( '' === $raw || ! is_numeric( $normalised ) || (float) $normalised < 0 ) {
				$this->redirect( $organization_id, 'invalid' );
			}

			$limit = (float) wc_format_decimal( $normalised, wc_get_price_decimals() );
		}

		$terms = new Terms( $organization_id );
		$terms->set_props(
			array(
				'status'       => $status,
				'credit_limit' => $limit,
				'note'         => mb_substr( $note, 0, 500 ),
			)
		);

		TermsRepository::save( $terms );

		$this->redirect( $organization_id, $terms->is_default() ? 'cleared' : 'saved' );
	}

	/**
	 * Send the browser back to the screen it came from.
	 *
	 * @param int    $organization_id Organization to return to, or 0 for the list.
	 * @param string $notice          Notice key.
	 * @return void
	 */
	private function redirect( $organization_id, $notice ) {
		$url = $organization_id > 0
			? self::edit_url( $organization_id )
			: self::list_url();

		wp_safe_redirect( add_query_arg( 'woip_notice', $notice, $url ) );
		exit;
	}
}
