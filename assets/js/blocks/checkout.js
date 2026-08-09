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
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var decodeEntities = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( value ) {
		return value;
	};

	var title = decodeEntities( settings.title || '' );
	var purchaseOrder = settings.purchaseOrder || {};

	/**
	 * What the customer sees once they have selected the method.
	 *
	 * @param {Object} props Payment method props supplied by the block checkout.
	 * @return {Object} Element.
	 */
	function Content( props ) {
		var eventRegistration = props.eventRegistration || {};
		var emitResponse = props.emitResponse || {};
		var reference = useState( '' );
		var value = reference[ 0 ];
		var setValue = reference[ 1 ];

		useEffect(
			function () {
				if ( ! eventRegistration.onPaymentSetup ) {
					return;
				}

				/*
				 * Whatever is returned here as paymentMethodData reaches the gateway as
				 * $_POST, which is what lets one process_payment() serve both checkouts.
				 */
				return eventRegistration.onPaymentSetup( function () {
					var trimmed = value.trim();

					if ( 'required' === purchaseOrder.mode && '' === trimmed ) {
						return {
							type: emitResponse.responseTypes.ERROR,
							message: purchaseOrder.error || '',
						};
					}

					var data = {};
					data[ purchaseOrder.field ] = trimmed;

					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: { paymentMethodData: data },
					};
				} );
			},
			[ eventRegistration, emitResponse, value ]
		);

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

		if ( 'hidden' !== purchaseOrder.mode ) {
			children.push(
				el(
					'div',
					{ key: 'po', className: 'woip-purchase-order wc-block-components-text-input' },
					el(
						'label',
						{ htmlFor: 'woip-purchase-order-field' },
						decodeEntities( purchaseOrder.label || '' ) +
							( 'required' === purchaseOrder.mode ? ' *' : '' )
					),
					el( 'input', {
						id: 'woip-purchase-order-field',
						type: 'text',
						maxLength: 100,
						value: value,
						required: 'required' === purchaseOrder.mode,
						onChange: function ( event ) {
							setValue( event.target.value );
						},
					} ),
					el(
						'span',
						{ className: 'woip-field-description' },
						decodeEntities( purchaseOrder.description || '' )
					)
				)
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
