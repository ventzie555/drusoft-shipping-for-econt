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

		/**
		 * Wraps a trigger field and its dependents in a visual group.
		 */
		function createVisualGroup( triggerKey, dependentKeys ) {
			// Find the trigger elements
			var $triggerField = $( '[id$="' + triggerKey + '"]' );
			
			// Filter for modal context to avoid duplicates if field exists elsewhere
			if ( $triggerField.length > 1 ) {
				$triggerField = $triggerField.filter(function() {
					return $(this).closest('.wc-backbone-modal-content').length > 0;
				});
			}
			
			if ( ! $triggerField.length ) return;

			// Check if already grouped to prevent errors or double wrapping
			if ( $triggerField.closest('.econt-settings-group').length ) {
				return;
			}

			var $triggerFieldset = $triggerField.closest( 'fieldset' );
			
			// Find the main label, ensuring we don't grab the label wrapping the checkbox inside the fieldset
			var $triggerLabel = $( 'label[for="' + $triggerField.attr('id') + '"]' ).not( $triggerFieldset.find('label') );

			// Start collection with trigger elements
			var $elementsToGroup = $triggerLabel.add($triggerFieldset);

			// Add dependent elements
			$.each( dependentKeys, function( i, key ) {
				var $field = $( '[id$="' + key + '"]' );
				if ( $field.length ) {
					var $fieldset = $field.closest( 'fieldset' );
					// Same logic for dependents: only grab the external label
					var $label = $( 'label[for="' + $field.attr('id') + '"]' ).not( $fieldset.find('label') );
					
					$elementsToGroup = $elementsToGroup.add($label).add($fieldset);
				}
			});

			// Wrap all collected elements in a single container
			// wrapAll inserts the wrapper at the position of the first element in the set
			$elementsToGroup.wrapAll('<div class="econt-settings-group"></div>');
		}

		/**
		 * Transforms a text input into a file upload UI.
		 */
		function setupFileUpload( inputClass ) {
			// Find the input directly by class
			var $textInput = $( 'input.' + inputClass );
			
			// Filter for modal context
			if ( $textInput.length > 1 ) {
				$textInput = $textInput.filter(function() {
					return $(this).closest('.wc-backbone-modal-content').length > 0;
				});
			}

			if ( ! $textInput.length ) return;

			var savedPath = $textInput.val();
			var fieldName = $textInput.attr('name');

			// Hide the original text input
			$textInput.hide();

			// Create the UI
			var uiHtml = '<div class="econt-file-ui">';
			
			// Status/Info area
			uiHtml += '<div class="econt-file-info">';
			if ( savedPath ) {
				var fileName = savedPath.split(/[\\/]/).pop();
				uiHtml += '<p class="description" style="margin-top: 5px;"><strong>' + 'Current file:' + '</strong> ' + fileName + '</p>';
			}
			uiHtml += '</div>';

			// File input (using a different name to avoid confusion with the text input)
			uiHtml += '<input type="file" class="econt-file-upload-input" style="margin-top: 5px;">';
			uiHtml += '<span class="spinner" style="float: none; margin-left: 5px;"></span>';
			uiHtml += '<div class="econt-upload-error" style="color: red; margin-top: 5px;"></div>';
			
			uiHtml += '</div>';

			var $ui = $(uiHtml);
			$textInput.after($ui);

			// Handle file selection
			$ui.find('.econt-file-upload-input').on('change', function(e) {
				var file = this.files[0];
				if ( ! file ) return;

				var $spinner = $ui.find('.spinner');
				var $error   = $ui.find('.econt-upload-error');
				var $info    = $ui.find('.econt-file-info');

				$spinner.addClass('is-active');
				$error.text('');

				var formData = new FormData();
				formData.append('action', 'drushfe_upload_file');
				formData.append('nonce', drushfe_admin.nonce);
				formData.append('file', file);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: formData,
					contentType: false,
					processData: false,
					success: function( response ) {
						$spinner.removeClass('is-active');
						if ( response.success ) {
							// Update the hidden text input with the new path
							$textInput.val( response.data.path ).trigger('change');
							
							// Update UI
							$info.html('<p class="description" style="margin-top: 5px; color: green;"><strong>' + 'Uploaded:' + '</strong> ' + response.data.name + '</p>');
						} else {
							$error.text( response.data );
						}
					},
					error: function() {
						$spinner.removeClass('is-active');
						$error.text( 'Upload failed. Please try again.' );
					}
				});
			});
		}

		// --- Grouping Logic ---
		createVisualGroup( 'woocommerce_drushfe_econt_free_shipping', ['free_shipping_automat', 'free_shipping_office', 'free_shipping_address'] );
		createVisualGroup( 'woocommerce_drushfe_econt_fixed_shipping', ['fixed_shipping_automat', 'fixed_shipping_office', 'fixed_shipping_address'] );
		createVisualGroup( 'woocommerce_drushfe_econt_vaucher', ['vaucherpayer', 'vaucherpayerdays'] );
		createVisualGroup( 'woocommerce_drushfe_econt_test_before_pay', ['testplatec', 'autoclose'] );


		// --- Visibility Logic ---

		function setupDependency( sourceKey, targetKey, expectedValue ) {
			var $source = $( '[id$="' + sourceKey + '"]' );
			
			function update() {
				var val = $source.val();
				if ( $source.is(':checkbox') ) {
					val = $source.is(':checked') ? 'yes' : 'no';
				}
				
				var show = false;
				if ( Array.isArray( expectedValue ) ) {
					show = expectedValue.includes( val );
				} else {
					show = ( val === expectedValue );
				}
				
				toggleRow( targetKey, show );
			}

			if ( $source.length ) {
				$source.change( update );
				update();
			}
		}

		function setupCheckboxToggle( checkboxId, targetKeys ) {
			var selector = checkboxId.indexOf('woocommerce_') === 0 ? '#' + checkboxId : '[id$="' + checkboxId + '"]';
			var $checkbox = $( selector );

			function update() {
				var isChecked = $checkbox.is(':checked');
				$.each( targetKeys, function( index, key ) {
					toggleRow( key, isChecked );
				});
			}

			if ( $checkbox.length ) {
				$checkbox.change( update );
				update();
			}
		}

		setupCheckboxToggle( 'woocommerce_drushfe_econt_free_shipping', [
			'free_shipping_automat',
			'free_shipping_office',
			'free_shipping_address'
		]);

		setupCheckboxToggle( 'woocommerce_drushfe_econt_fixed_shipping', [
			'fixed_shipping_automat',
			'fixed_shipping_office',
			'fixed_shipping_address'
		]);

		var $pricingSelect = $( '[id$="cenadostavka"]' );
		var $fixedCheckbox = $( '#woocommerce_drushfe_econt_fixed_shipping' );
		var $freeCheckbox  = $( '#woocommerce_drushfe_econt_free_shipping' );

		function updatePricingMethod() {
			var method = $pricingSelect.val();

			toggleRow( 'suma_nadbavka', method === 'nadbavka' );
			toggleRow( 'fileceni', method === 'fileprices' );

			if ( method === 'fixedprices' ) {
				if ( ! $fixedCheckbox.is(':checked') ) {
					$fixedCheckbox.prop( 'checked', true ).trigger( 'change' );
				}
				if ( $freeCheckbox.is(':checked') ) {
					$freeCheckbox.prop( 'checked', false ).trigger( 'change' );
				}
			} 
			else if ( method === 'freeshipping' ) {
				if ( ! $freeCheckbox.is(':checked') ) {
					$freeCheckbox.prop( 'checked', true ).trigger( 'change' );
				}
				if ( $fixedCheckbox.is(':checked') ) {
					$fixedCheckbox.prop( 'checked', false ).trigger( 'change' );
				}
			} 
			else if ( method === 'econtcalculator' || method === 'nadbavka' || method === 'fileprices' ) {
				if ( $fixedCheckbox.is(':checked') ) {
					$fixedCheckbox.prop( 'checked', false ).trigger( 'change' );
				}
				if ( $freeCheckbox.is(':checked') ) {
					$freeCheckbox.prop( 'checked', false ).trigger( 'change' );
				}
			}
		}

		if ( $pricingSelect.length ) {
			$pricingSelect.change( updatePricingMethod );
			var initialMethod = $pricingSelect.val();
			toggleRow( 'suma_nadbavka', initialMethod === 'nadbavka' );
			toggleRow( 'fileceni', initialMethod === 'fileprices' );
		}

		setupDependency( 'sender_officeyesno', 'sender_office', 'YES' );
		setupDependency( 'obqvena', 'chuplivost', 'YES' );
		setupDependency( 'vaucher', 'vaucherpayer', 'YES' );
		setupDependency( 'vaucher', 'vaucherpayerdays', 'YES' );
		setupDependency( 'test_before_pay', 'testplatec', ['OPEN', 'TEST'] );
		setupDependency( 'test_before_pay', 'autoclose', ['OPEN', 'TEST'] );

		// --- Init Special UI ---
		setupFileUpload( 'econt-file-input-wrapper' );

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
