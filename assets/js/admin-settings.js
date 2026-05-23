/**
 * Drusoft Shipping for Econt – Admin Settings Script
 *
 * Handles dynamic field visibility and grouping in the shipping method settings modal.
 */
(function( $ ) {
	'use strict';

	/**
	 * When the WooCommerce backbone modal loads, attach change listeners
	 * to the relevant fields to toggle visibility of dependent rows.
	 */
	$( document.body ).on( 'wc_backbone_modal_loaded', function( event, target ) {
		if ( 'wc-modal-shipping-method-settings' !== target ) {
			return;
		}

		// --- Helper Functions ---

		/**
		 * Toggle a row based on a condition.
		 * Handles div-based (label+fieldset) layouts.
		 */
		function toggleRow( fieldKey, show ) {
			var $field = $( '[id$="' + fieldKey + '"]' );
			
			if ( $field.length > 1 ) {
				$field = $field.filter(function() {
					return $(this).closest('.wc-backbone-modal-content').length > 0;
				});
			}
			
			if ( ! $field.length ) {
				$field = $( '[name*="' + fieldKey + '"]' );
			}

			if ( ! $field.length ) return;

			// Div-based layout (label + fieldset siblings)
			var $fieldset = $field.closest( 'fieldset' );
			var fieldId   = $field.attr( 'id' );
			var $label    = $( 'label[for="' + fieldId + '"]' ).not( $fieldset.find('label') );
			
			if ( show ) {
				$fieldset.stop().slideDown(200);
				$label.stop().slideDown(200);
			} else {
				$fieldset.stop().slideUp(200);
				$label.stop().slideUp(200);
			}
		}

		// --- Visibility: hide the "Shipping from Office" select unless the
		// "Send from Office" toggle is set to YES.
		function setupDependency( sourceKey, targetKey, expectedValue ) {
			var $source = $( '[id$="' + sourceKey + '"]' );

			function update() {
				var val = $source.val();
				if ( $source.is(':checkbox') ) {
					val = $source.is(':checked') ? 'yes' : 'no';
				}
				toggleRow( targetKey, val === expectedValue );
			}

			if ( $source.length ) {
				$source.change( update );
				update();
			}
		}

		setupDependency( 'sender_officeyesno', 'sender_office', 'YES' );

		var $citySearch = $( '.econt-city-search' );
		
		// Filter for modal context to avoid duplicates
		if ( $citySearch.length > 1 ) {
			$citySearch = $citySearch.filter(function() {
				return $(this).closest('.wc-backbone-modal-content').length > 0;
			});
		}

		if ( $citySearch.length ) {
			$citySearch.select2({
				width: '100%',
				placeholder: $citySearch.data('placeholder') || 'Search...',
				allowClear: true,
				ajax: {
					url: ajaxurl,
					dataType: 'json',
					delay: 250,
				data: function (params) {
					return {
						action: 'drushfe_search_cities',
						nonce: drushfe_admin.nonce,
						term: params.term
					};
				},
					processResults: function (data) {
						return data;
					},
					cache: true
				},
				minimumInputLength: 3
			});
		}

		var $officeSearch = $( '.econt-office-search' );
		
		// Filter for modal context to avoid duplicates
		if ( $officeSearch.length > 1 ) {
			$officeSearch = $officeSearch.filter(function() {
				return $(this).closest('.wc-backbone-modal-content').length > 0;
			});
		}

		if ( $officeSearch.length ) {
			$officeSearch.select2({
				width: '100%',
				placeholder: $officeSearch.data('placeholder') || 'Search...',
				allowClear: true,
				ajax: {
					url: ajaxurl,
					dataType: 'json',
					delay: 250,
				data: function (params) {
					return {
						action: 'drushfe_search_offices',
						nonce: drushfe_admin.nonce,
						term: params.term,
						exclude_automats: '1'
					};
				},
					processResults: function (data) {
						return data;
					},
					cache: true
				},
				minimumInputLength: 3
			});
		}

	});

})( jQuery );
