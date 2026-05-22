/**
 * Drusoft Shipping for Econt – Admin Shipping Zone Script
 *
 * Automatically reopens the shipping method settings modal after saving
 * credentials for the first time (so unlocked fields appear), or after
 * a failed credential validation (so the error message is visible).
 */
(function( $ ) {
	'use strict';

	if ( typeof drushfe_admin === 'undefined' ) {
		return;
	}

	// Track whether credentials existed before this page load
	var hadCredentials = drushfe_admin.has_credentials === '1';

	// Store error messages from the last failed save so we can inject them into the modal
	var pendingErrors = [];

	/**
	 * Listen for completed AJAX requests. WooCommerce saves shipping method
	 * instance settings via AJAX, so we can intercept the response.
	 */
	$( document ).ajaxComplete( function( event, xhr, settings ) {

		var url = settings.url || '';

		// WooCommerce puts the action in the URL query string, not in POST body
		if ( url.indexOf( 'action=woocommerce_shipping_zone_methods_save_settings' ) === -1 ) {
			return;
		}

		// Parse the response to check if this was for our Econt method
		var response;
		try {
			response = JSON.parse( xhr.responseText || '{}' );
		} catch ( e ) {
			return;
		}

		if ( ! response.success || ! response.data || ! response.data.methods ) {
			return;
		}

		// Check if any of the saved methods is our drushfe_econt method
		var isEcontMethod = false;
		$.each( response.data.methods, function( id, method ) {
			if ( method.id === 'drushfe_econt' ) {
				isEcontMethod = true;
				return false; // break
			}
		});

		if ( ! isEcontMethod ) {
			return;
		}

		// Decide whether to reopen the modal
		var shouldReopen = false;
		var hasErrors    = response.data.errors && response.data.errors.length > 0;

		if ( ! hadCredentials ) {
			// First-time credential save — reopen to show newly unlocked fields
			shouldReopen   = true;
			hadCredentials = true;
		}

		if ( hasErrors ) {
			// Credentials failed — reopen to show the error in the modal
			shouldReopen   = true;
			hadCredentials = false; // Reset so next attempt can also reopen
			pendingErrors  = response.data.errors;

			// Remove the WC error notice from behind the modal — we'll show it inside instead
			$( 'table.wc-shipping-zone-methods' ).parent().find( '#woocommerce_errors' ).remove();
		} else {
			pendingErrors = [];
		}

		if ( ! shouldReopen ) {
			return;
		}

		// After the modal closes, find and re-click the edit link for our method.
		setTimeout( function() {
			var $rows = $( 'table.wc-shipping-zone-methods tbody tr' );

			$rows.each( function() {
				var $row  = $( this );
				var $link = $row.find( 'a.wc-shipping-zone-method-settings' );

				if ( $link.length && $row.text().indexOf( 'Econt' ) !== -1 ) {
					$link.trigger( 'click' );
					return false;
				}
			});
		}, 600 );
	});

	/**
	 * When the WooCommerce backbone modal loads, inject any pending error
	 * messages directly below the password field inside the modal.
	 */
	$( document.body ).on( 'wc_backbone_modal_loaded', function( event, target ) {
		if ( 'wc-modal-shipping-method-settings' !== target ) {
			return;
		}

		if ( ! pendingErrors.length ) {
			return;
		}

		// Build error HTML as a simple div (the modal uses divs, not tables)
		var errorHtml = '<div class="econt-auth-error" style="padding:2px 10px 12px;">';
		$.each( pendingErrors, function( i, msg ) {
			errorHtml += '<p style="color:#d63638;font-weight:bold;margin:4px 0;">' + msg + '</p>';
		});
		errorHtml += '<p style="margin:4px 0;">' + ( drushfe_admin.i18n_correct_credentials || 'Please correct your credentials and save again.' ) + '</p>';
		errorHtml += '</div>';

		// The modal content structure after WC's replaceHTMLTables:
		// <div class="wc-shipping-zone-method-fields">
		//   <tr>...<input id="...econt_private_key">...</tr>
		//   ...
		// In modern WC, fields may be rendered as fieldsets/labels instead of tr/td.
		// Try multiple selectors to find the password field.
		var $modal     = $( '.wc-backbone-modal-content' );
		var $passField = $modal.find( '[id$="econt_private_key"]' );

		var $userField = $modal.find( '[id$="econt_private_key"]' );

		if ( $passField.length ) {
			// Walk up to the nearest field wrapper (fieldset, tr, label, .form-field)
			var $wrapper = $passField.closest( 'fieldset, tr, label, .form-field' );
			if ( $wrapper.length ) {
				$wrapper.after( errorHtml );
			} else {
				$passField.parent().after( errorHtml );
			}
		} else {
			// Fallback: prepend to the modal form area
			$modal.find( '.wc-shipping-zone-method-fields, article' ).first().prepend( errorHtml );
		}

		// Highlight Username and Password inputs with red borders
		var errorBorder = '1px solid #d63638';
		$userField.css( 'border', errorBorder );
		$passField.css( 'border', errorBorder );

		// Highlight Username and Password labels in red
		$modal.find( 'label[for$="econt_private_key"], label[for$="econt_private_key"]' )
			.css( 'color', '#d63638' );

		// Clear pending errors so they don't show again on the next modal open
		pendingErrors = [];
	});

})( jQuery );
