/**
 * The Pay by invoice method on the block checkout.
 *
 * Plain JavaScript against the globals WooCommerce already enqueues — no JSX and no
 * bundler, so this file is the file that ships and `node --check` over it is a real
 * test. Nothing here enforces anything: every value comes from the server, which
 * refuses the order again in process_payment() whatever the browser sends.
 */
( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var settings = wc.wcSettings.getSetting( 'woip_invoice_data', {} );
	var el = wp.element.createElement;
	var decodeEntities = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( value ) {
		return value;
	};

	var title = decodeEntities( settings.title || '' );

	/**
	 * What the customer sees once they have selected the method.
	 *
	 * The method collects nothing: everything below is a statement of what the server
	 * has already decided, so there is no onPaymentSetup handler and no payment data to
	 * send back. The order is still refused by process_payment() if any of it has
	 * changed by the time it is submitted.
	 *
	 * @return {Object} Element.
	 */
	function Content() {
		var children = [];

		if ( settings.description ) {
			children.push(
				el( 'p', { key: 'description' }, decodeEntities( settings.description ) )
			);
		}

		if ( settings.terms ) {
			children.push(
				el( 'p', { key: 'terms', className: 'woip-terms' }, decodeEntities( settings.terms ) )
			);
		}

		if ( settings.creditNotice ) {
			children.push(
				el( 'p', { key: 'credit', className: 'woip-credit' }, decodeEntities( settings.creditNotice ) )
			);
		}

		return el( 'div', { className: 'woip-payment-method' }, children );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'woip_invoice',
		label: el( 'span', null, title ),
		ariaLabel: title,
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () {
			// The server decided this already: an inactive method is never registered.
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wc, window.wp );
