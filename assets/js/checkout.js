/* global drushfe_params */
// noinspection CssInvalidHtmlTagReference

/**
 * @global drushfe_params
 * @type {object}
 * @property {string} ajax_url
 * @property {string} method_id
 * @property {Object} i18n
 * @property {string} i18n.to_address
 * @property {string} i18n.to_office
 * @property {string} i18n.to_automat
 * @property {string} i18n.select_office
 * @property {string} i18n.select_automat
 * @property {string} i18n.select_from_map
 * @property {string} i18n.select_city
 * @property {string} i18n.alert_select_city
 */

/**
 * @typedef {Object} EcontAvailabilityData
 * @property {boolean} has_office
 * @property {boolean} has_automat
 * @property {Array} offices
 * @property {Array} automats
 */

(function($, params) {
    'use strict';

    $(document).ready(function() {
        const econtMethodId = params.method_id; // 'drushfe_econt'
        let isEcontActive = false;
        
        // State persistence across AJAX updates.
        // Initialize from server params (session data set by cart page).
        let lastDeliveryType = params.current_type || 'address';
        let lastOfficeId = params.current_office_id ? String(params.current_office_id) : '';

        // Cache to avoid redundant AJAX calls.
        let cachedState = '';
        let cachedCities = null;
        let cachedCityId = '';
        let cachedAvailability = null;

        // Remembered selection across a temporary switch to the sibling courier.
        // Populated on deactivate, consumed once on the next setup so the user
        // gets their province / city / delivery-type / office back when they
        // switch Econt → Speedy → Econt.
        let savedSelection = null;

        // True while we are inside setupEcontUI — prevents update_checkout
        // that our own code triggers from causing a re-entrant loop.
        let settingUp = false;

        // Current address context ('billing' or 'shipping')
        let currentContext = 'billing';

        // Wall-clock timestamp used to detect stale calculate_price responses.
        // The PHP handler ignores any response whose flow_version is < the
        // last-written value. Using Date.now() means a fresh page load always
        // produces newer versions than anything persisted in the WC session.
        let flowVersion = Date.now();

        // True while a recalculate AJAX is in flight.
        let priceInFlight = false;

        /**
         * Recompute the Econt shipping cost via OrdersService.getPrice.json
         * and refresh the WC order review. Called whenever a user selection
         * (city, delivery-type, office, street) changes.
         *
         * Strategy:
         *  - Collect the current form selections + WC payment_method.
         *  - If selection is incomplete (no city, or office-mode w/ no office)
         *    → call drushfe_clear_price (zeroes session price), then update_checkout.
         *  - Otherwise → call drushfe_calculate_price (stores price in session),
         *    then update_checkout.
         *
         * Both branches always end with update_checkout, so the order review
         * always re-renders with whatever calculate_shipping returns from session.
         */
        // Apply WC's standard blockUI overlay so the user gets immediate
        // visual feedback that "something is happening" before any DOM churn
        // or AJAX completes. We mirror WC's own update_checkout_action by
        // blocking the two elements that get fragment-replaced at the end of
        // the cycle: .woocommerce-checkout-review-order-table (the totals)
        // and .woocommerce-checkout-payment (the payment selector). Because
        // those elements are .replaceWith()-swapped, the .blockOverlay child
        // dies with the old node — no stale overlay. Blocking #order_review
        // directly does NOT clear, because WC's fragments are descendants of
        // it, not the wrapper itself.
        function blockOrderReview() {
            const $targets = $('.woocommerce-checkout-review-order-table, .woocommerce-checkout-payment');
            if ($targets.length && typeof $targets.block === 'function') {
                $targets.block({
                    message: null,
                    overlayCSS: { background: '#fff', opacity: 0.6 },
                });
            }
        }

        function recalculatePrice() {
            if (!isEcontActive) return;

            const deliveryType = $('input[name="econt_delivery_type"]:checked').val()
                || lastDeliveryType || 'address';
            const cityId       = $('#' + currentContext + '_city').val() || '';
            // City option text is "гр. Name (NNNN)". Econt's getPrice expects the
            // raw city name without the postcode suffix (it returns "no match"
            // otherwise). Strip trailing ` (1234)`.
            const cityName     = ($('#' + currentContext + '_city option:selected').text() || '')
                                    .replace(/\s*\(\d+\)\s*$/, '')
                                    .trim();
            const postcode     = $('#' + currentContext + '_postcode').val() || '';
            const state        = $('#' + currentContext + '_state').val() || '';
            const officeCode   = $('#econt_office_id').val() || '';
            const address      = $('#' + currentContext + '_address_1').val() || '';
            const paymentMethod = $('input[name="payment_method"]:checked').val() || '';

            // Incomplete selection → clear price and refresh totals.
            // Note: address-mode without a typed street is NOT incomplete —
            // the server-side handler injects a joker street so Econt's
            // pricing (uniform per city) still returns a quote. The real
            // address is used later at waybill creation, not here.
            const incomplete = !cityId
                || ((deliveryType === 'office' || deliveryType === 'automat') && !officeCode);

            flowVersion += 1;
            const myVersion = flowVersion;
            priceInFlight = true;

            blockOrderReview();

            const endpoint = incomplete ? 'drushfe_clear_price' : 'drushfe_calculate_price';
            const payload  = incomplete
                ? { action: endpoint, nonce: params.nonce, flow_version: myVersion }
                : {
                    action:         endpoint,
                    nonce:          params.nonce,
                    flow_version:   myVersion,
                    delivery_type:  deliveryType,
                    city_id:        cityId,
                    city_name:      cityName,
                    postcode:       postcode,
                    state:          state,
                    office_code:    officeCode,
                    address:        address,
                    payment_method: paymentMethod,
                };

            $.ajax({
                url:    params.ajax_url,
                method: 'POST',
                data:   payload,
            }).always(function() {
                // Only the latest call's completion should drive update_checkout.
                // Earlier calls' completions will trigger redundant refreshes;
                // that's harmless but wasted bandwidth — guard with flowVersion.
                priceInFlight = false;
                if (myVersion !== flowVersion) return;
                $(document.body).trigger('update_checkout');
            });
        }

        // ─── Office Map (nationwide) ──────────────────────────────────────
        // Lazy-fetched cache of every Econt office/automat with lat/lng + the
        // city's BG-XX region code. Populated on first openOfficeMap() click.
        let allOfficesCache = null;
        let allOfficesPromise = null;
        function fetchAllOffices() {
            if (allOfficesCache) return Promise.resolve(allOfficesCache);
            if (allOfficesPromise) return allOfficesPromise;
            allOfficesPromise = $.ajax({
                url: params.ajax_url,
                method: 'POST',
                data: { action: 'drushfe_get_all_offices', nonce: params.nonce },
            }).then(function (response) {
                if (response && response.success && Array.isArray(response.data)) {
                    allOfficesCache = response.data;
                }
                allOfficesPromise = null;
                return allOfficesCache || [];
            }, function () {
                allOfficesPromise = null;
                return [];
            });
            return allOfficesPromise;
        }

        // Place / move the "Open Map" button. Idempotent — call from every
        // setup path so the button follows the layout as Econt fields appear.
        // The button is shown whenever Econt is the active shipping method,
        // even before any city/delivery-type is chosen.
        function placeMapButton() {
            $('#econt-map-button-wrapper').remove();
            if (!isEcontActive) return;

            const html =
                '<p class="form-row form-row-wide" id="econt-map-button-wrapper" style="margin-top: 10px;">' +
                '<button type="button" id="econt-open-map" class="button" style="width: 100%;">' +
                params.i18n.select_from_map + '</button></p>';

            // Anchor as low in the Econt stack as possible so it doesn't
            // intercept the address/office fields visually.
            const $anchor = $('#econt-office-field').length
                ? $('#econt-office-field')
                : ($('#econt-delivery-type-field').length
                    ? $('#econt-delivery-type-field')
                    : $('#' + currentContext + '_city_field'));
            $anchor.after(html);

            $('#econt-open-map').off('click.econtMap').on('click.econtMap', openOfficeMap);
        }

        // Open the map with the appropriate point set, given the current
        // delivery-type selection. Falls back to "all offices nationwide"
        // when no delivery type is chosen yet.
        function openOfficeMap() {
            if (!window.DrushfeMap) return;
            const currentType = $('input[name="econt_delivery_type"]:checked').val() || lastDeliveryType || 'address';

            // Default the filter radio inside the modal to whichever delivery
            // type the customer is currently on (or 'both' in address mode).
            let defaultFilter = 'both';
            let title = params.i18n.map_title || params.i18n.map_title_office;
            if (currentType === 'automat') { defaultFilter = 'automat'; title = params.i18n.map_title_automat; }
            else if (currentType === 'office')  { defaultFilter = 'office';  title = params.i18n.map_title_office;  }

            fetchAllOffices().then(function (all) {
                // Always pass the full list — filtering is done inside the
                // modal so the user can change their mind without reopening.
                window.DrushfeMap.open(all, handleMapPick, {
                    title:         title,
                    hint:          params.i18n.map_hint,
                    pickLabel:     params.i18n.map_pick,
                    errorLabel:    params.i18n.map_error,
                    defaultFilter: defaultFilter,
                    i18n: {
                        offices:            params.i18n.map_filter_office,
                        automats:           params.i18n.map_filter_automat,
                        both:               params.i18n.map_filter_both,
                        search_placeholder: params.i18n.map_search_placeholder,
                        results_count:      params.i18n.map_results_count,
                        search_no_results:  params.i18n.map_search_no_results,
                    },
                });
            });
        }

        // Picking a marker may require switching state + city + delivery-type.
        // Chain those changes with short polls for the dependent UI to render
        // (mirrors the Speedy plugin's updateOfficeFromMap pattern).
        function handleMapPick(point) {
            const targetCityId = String(point.city_id);
            const targetRegion = point.region_code;
            const targetType   = (point.office_type === 'APS') ? 'automat' : 'office';

            const $state = $('#' + currentContext + '_state');
            const $city  = $('#' + currentContext + '_city');
            const currentState = $state.val();
            const currentCity  = $city.val();

            function setOfficeId() {
                const $sel = $('#econt_office_id');
                if (!$sel.length) return;
                const targetId = String(point.id);
                let exists = false;
                $sel.find('option').each(function () {
                    if (String($(this).val()) === targetId) { exists = true; return false; }
                });
                if (!exists) {
                    const label = point.id + ' ' + point.name + (point.address ? ' - ' + point.address : '');
                    $sel.append(new Option(label, point.id, true, true));
                }
                $sel.val(targetId).trigger('change.select2');
                $sel.trigger('change');
            }

            function setDeliveryType(done) {
                const cur = $('input[name="econt_delivery_type"]:checked').val();
                if (cur === targetType) { done(); return; }
                $('input[name="econt_delivery_type"][value="' + targetType + '"]').prop('checked', true).trigger('change');
                // handleDeliveryTypeChange will rebuild showPointsDropdown → wait.
                waitFor(function () { return !!$('#econt_office_id').length; }, done);
            }

            // Simple promise-less poll, 200ms × up to 50 (=10s).
            function waitFor(predicate, done, attempts) {
                attempts = (attempts === undefined) ? 50 : attempts;
                if (predicate()) { setTimeout(done, 100); return; }
                if (attempts <= 0) { return; }
                setTimeout(function () { waitFor(predicate, done, attempts - 1); }, 200);
            }

            const sameState = !targetRegion || currentState === targetRegion;
            const sameCity  = currentCity === targetCityId;

            if (sameState && sameCity) {
                setDeliveryType(setOfficeId);
                return;
            }

            if (!sameState) {
                $state.val(targetRegion).trigger('change');
                // bindStateChangeHandler will reload cities + replace city select.
                waitFor(function () {
                    return $('#' + currentContext + '_city option[value="' + targetCityId + '"]').length > 0;
                }, function () {
                    $('#' + currentContext + '_city').val(targetCityId).trigger('change', { force: true });
                    waitFor(function () { return $('#econt-delivery-type-field').length > 0; }, function () {
                        setDeliveryType(setOfficeId);
                    });
                });
                return;
            }

            // Same state, different city.
            $city.val(targetCityId).trigger('change');
            waitFor(function () { return $('#econt-delivery-type-field').length > 0; }, function () {
                setDeliveryType(setOfficeId);
            });
        }
        // ──────────────────────────────────────────────────────────────────

        // Store original HTML for both contexts to restore later
        const originals = {
            billing: {},
            shipping: {}
        };

        // Helper to capture originals
        function captureOriginals(context) {
            // Never snapshot a courier-built city select (ours or the sibling
            // Speedy plugin's) as the "stock" field — that would corrupt the
            // restore. A courier city is always a select2 <select>; the stock
            // BG city field is a plain text input. Keep the clean snapshot taken
            // on page load instead.
            const $city = $('#' + context + '_city');
            if ($city.is('select') && $city.hasClass('select2-hidden-accessible')) {
                return;
            }
            if ($('#' + context + '_city_field').length) {
                originals[context].cityHtml = $('#' + context + '_city_field').html();
                originals[context].address1Html = $('#' + context + '_address_1_field').html();
                originals[context].address2Html = $('#' + context + '_address_2_field').html();
                originals[context].stateHtml = $('#' + context + '_state_field').html();
                
                // Capture priorities
                originals[context].priorities = {
                    state: $('#' + context + '_state_field').attr('data-priority'),
                    city: $('#' + context + '_city_field').attr('data-priority'),
                    address1: $('#' + context + '_address_1_field').attr('data-priority'),
                    address2: $('#' + context + '_address_2_field').attr('data-priority')
                };
            }
        }

        // Initial capture
        captureOriginals('billing');
        captureOriginals('shipping');

        // Listen for shipping method changes — use mousedown in CAPTURE phase
        // so our DOM cleanup runs before any other plugin's event handlers.
        document.addEventListener('mousedown', function(e) {
            const radio = e.target.closest('input[name^="shipping_method"]');
            if (!radio) return;

            // The radio hasn't changed value yet on mousedown, but we can
            // check whether the clicked radio is NOT Econt.
            const isEcont = radio.value && radio.value.indexOf(econtMethodId) !== -1;
            if (!isEcont && isEcontActive) {
                deactivateEcont();
            }
        }, true);

        // Capture state BEFORE WC destroys the DOM
        $(document.body).on('update_checkout', function() {
            if (isEcontActive && !settingUp) {
                const type = $('input[name="econt_delivery_type"]:checked').val();
                if (type) lastDeliveryType = type;
                
                // Only capture office if the user explicitly selected one
                // (the change handler sets lastOfficeId directly, so we just
                // preserve whatever value it already has — don't read from DOM
                // because select2 may report a stale or auto-selected value).

                // Save current city id from the dropdown
                const cityVal = $('#' + currentContext + '_city').val();
                if (cityVal) cachedCityId = cityVal;
            }
            // (No body-level change.econt to unbind — the handler is bound
            // directly on #billing_state / #shipping_state by bindStateChangeHandler.)
        });

        // ===== SINGLE ENTRY POINT: updated_checkout =====
        // WooCommerce fires this after every checkout AJAX refresh,
        // including the initial one on page load. This is where we
        // set up or restore the Econt UI — never from document.ready.
        $(document.body).on('updated_checkout', function() {
            updateContext();

            const econtSelected = isEcontSelected();

            // ALWAYS rebind the state-change handler when Econt is the chosen
            // method, regardless of `settingUp` or DOM-rebuild state. The
            // `update_checkout` handler above unbinds it on every cycle to
            // prevent stray fires during WC's DOM teardown, so this is the
            // single point of truth for the binding's lifecycle.
            if ( econtSelected && isEcontActive ) {
                bindStateChangeHandler();
            }

            if (settingUp) return; // guard against re-entrance

            if (!econtSelected) {
                if (!isEcontActive) {
                    captureOriginals('billing');
                    captureOriginals('shipping');
                }
                if (isEcontActive) {
                    deactivateEcont();
                }
                return;
            }

            // Econt is selected — set up / restore UI.
            // Only skip the rebuild if Econt is ALREADY active AND our own city
            // select is in the DOM (WC refreshed order-review without rebuilding
            // billing). The isEcontActive guard is essential: after switching away
            // to Speedy and back, our old drushfe-city select can linger in the
            // DOM — without the guard we'd mistake it for a live setup, skip
            // setupEcontUI, and never restore the remembered selection.
            if (isEcontActive && $('#' + currentContext + '_city').hasClass('drushfe-city')) {
                placeMapButton();
                return;
            }

            // WC rebuilt the DOM. Capture clean originals, then set up Econt.
            captureOriginals('billing');
            captureOriginals('shipping');

            setupEcontUI();
        });

        // Listen for Country Change (WC re-sorts fields on this event)
        $(document.body).on('country_to_state_changed', function() {
            if (isEcontActive) {
                setTimeout(function() {
                    reorderFieldsForEcont();
                    initStateSelect2WithTransliteration();
                    makeRegionRequired();
                }, 100);
            }
        });

        // Listen for "Ship to different address" toggle
        // Payment-method change → re-quote, because Econt's getPrice includes
        // a `cod` flag in the payload and may add a COD-handling fee. Delegated
        // on document.body so it survives WC's payment-block re-renders.
        $(document.body).on('change', 'input[name="payment_method"]', function() {
            if (isEcontActive) {
                recalculatePrice();
            }
        });

        $('form.checkout').on('change', '#ship-to-different-address-checkbox', function() {
            // If Econt is active, we need to switch contexts
            if (isEcontActive) {
                // Deactivate on current context (restore fields)
                deactivateEcont();
                
                // Update context
                updateContext();
                
                // Re-activate on new context
                setupEcontUI();
            } else {
                updateContext();
            }
        });

        function updateContext() {
            if ($('#ship-to-different-address-checkbox').is(':checked')) {
                currentContext = 'shipping';
            } else {
                currentContext = 'billing';
            }
        }

        function isEcontSelected() {
            // When only one method exists, WC renders a hidden input (no :checked).
            const selectedMethod = $('input[name^="shipping_method"][type="radio"]:checked, input[name^="shipping_method"][type="hidden"]').first().val();
            return !!(selectedMethod && selectedMethod.indexOf(econtMethodId) !== -1);
        }

        // Initial check on page load
        updateContext();

        // Map widget: removed — Econt does not expose a standalone office-locator
        // widget URL like Speedy's. A future iteration can add an inline map (e.g.
        // Leaflet over the offices nomenclature) but is out of scope for v0.1.

        // Map-driven office selection (updateOfficeFromMap, setOfficeInDropdown,
        // setOfficeValue) removed along with the map widget — no Econt-side
        // standalone widget URL exists. Restore when an inline map is added.

        /**
         * Single setup/restore function for the Econt UI.
         * Called only from updated_checkout — never from document.ready.
         * Uses caches when available to avoid redundant AJAX calls.
         */
        function setupEcontUI() {
            isEcontActive = true;
            settingUp = true;

            // Tell the sibling Speedy plugin that Econt now owns the layout, so
            // its deactivate (which runs after ours when switching) won't restore
            // the stock field order on top of ours.
            window.__drushfActiveCourier = 'econt';

            reorderFieldsForEcont();
            initStateSelect2WithTransliteration();
            makeRegionRequired();

            // Restore THIS courier's own remembered selection. Province and city
            // come ONLY from our own memory (savedSelection) or the server
            // session — NEVER from the current DOM value, which belongs to the
            // sibling courier. Speedy and Econt keep fully separate data: picking
            // a county under Speedy must not pre-fill it under Econt.
            let restoredState = '';
            if (savedSelection) {
                if (savedSelection.deliveryType) lastDeliveryType = savedSelection.deliveryType;
                if (savedSelection.officeId) lastOfficeId = savedSelection.officeId;
                if (savedSelection.cityId) cachedCityId = savedSelection.cityId;
                restoredState = savedSelection.state || '';
                savedSelection = null;
            }

            // WC uses '*' as a "no state" sentinel for guests — treat it as empty.
            const sessionState = (params.current_state && params.current_state !== '*') ? params.current_state : '';
            const effectiveState = restoredState || sessionState;

            // Apply (or CLEAR) the shared state field to match our own data, so we
            // never inherit the sibling courier's leftover province.
            const $stateEl = $('#' + currentContext + '_state');
            if (($stateEl.val() || '') !== effectiveState) {
                $stateEl.val(effectiveState).trigger('change.select2');
            }

            // Postcode is a shared field too — clear it so we don't inherit the
            // sibling courier's. The restore chain below re-fills it from our own
            // pre-selected city/office.
            $('#' + currentContext + '_postcode').val('');

            // City to pre-select comes only from our own cache/session — not the
            // DOM value, which would be the sibling courier's city.
            const preSelectCity = cachedCityId || params.current_city_id;

            if (!effectiveState) {
                $('#' + currentContext + '_city_field').hide();
                settingUp = false;
                bindStateChangeHandler();
                placeMapButton();
                return;
            }

            // Load cities (cached or AJAX) then continue the chain
            loadCities(effectiveState, function(cities) {
                if (!cities) { settingUp = false; bindStateChangeHandler(); placeMapButton(); return; }

                replaceCityInputWithSelect(cities, preSelectCity, true); // skip auto-trigger

                const $citySelect = $('#' + currentContext + '_city');
                const selectedCityId = $citySelect.val();

                if (!selectedCityId) {
                    // No city matched — user will have to pick one
                    settingUp = false;
                    bindStateChangeHandler();
                    placeMapButton();
                    return;
                }

                // Load availability (cached or AJAX) then continue
                loadAvailability(selectedCityId, function(availData) {
                    if (availData) {
                        presentDeliveryOptions(availData);
                    }

                    settingUp = false;
                    bindStateChangeHandler();
                    placeMapButton();

                    // Initial setup done — fetch price for the restored selection.
                    recalculatePrice();
                });
            });
        }

        /**
         * Load cities for a state — uses cache if available.
         * Calls callback(cities) when done.
         */
        function loadCities(stateCode, callback) {
            let deferred = $.Deferred();

            if (stateCode === cachedState && cachedCities) {
                callback(cachedCities);
                deferred.resolve();
                return deferred.promise();
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: { action: 'drushfe_get_cities', nonce: params.nonce, region: stateCode },
                success: function(response) {
                    if (response.success) {
                        cachedState = stateCode;
                        cachedCities = response.data;
                        callback(response.data);
                    } else {
                        callback(null);
                    }
                    deferred.resolve();
                },
                error: function() { callback(null); deferred.resolve(); }
            });

            return deferred.promise();
        }

        /**
         * Load availability for a city — uses cache if available.
         * Calls callback(data) when done.
         */
        function loadAvailability(cityId, callback) {
            if (String(cityId) === String(cachedCityId) && cachedAvailability) {
                callback(cachedAvailability);
                return;
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: { action: 'drushfe_check_availability', nonce: params.nonce, city_id: cityId },
                success: function(response) {
                    if (response.success) {
                        cachedCityId = cityId;
                        cachedAvailability = response.data;
                        callback(response.data);
                    } else {
                        callback(null);
                    }
                },
                error: function() { callback(null); }
            });
        }

        /**
         * Bind the state change handler (user changes state dropdown).
         */
        function bindStateChangeHandler() {
            // Bind directly on the state element (not delegated on body) — this
            // way the handler survives select2 init/destroy cycles and any
            // future body-level event sweeps. updated_checkout calls this on
            // every cycle, so the off-then-on pattern is idempotent.
            const $state = $('#' + currentContext + '_state');
            $state.off('change.econt');
            $state.on('change.econt', function() {
                const state = $(this).val();
                if (!isEcontActive) return;

                // State changed — remove stale delivery options
                $('#econt-delivery-type-field').remove();
                $('#econt-office-field').remove();
                $('#econt-map-button-wrapper').remove();
                $('#econt-service-field').remove();
                lastDeliveryType = 'address';
                lastOfficeId = '';

                // Invalidate caches
                cachedState = '';
                cachedCities = null;
                cachedCityId = '';
                cachedAvailability = null;

                // Clear city field — destroy select2 if present, then reset
                const $cityEl = $('#' + currentContext + '_city');
                if ($cityEl.is('select') && $cityEl.hasClass('select2-hidden-accessible')) {
                    $cityEl.val('').trigger('change.select2');
                    $cityEl.select2('destroy');
                }
                const $cityField = $('#' + currentContext + '_city_field');
                if (originals[currentContext] && originals[currentContext].cityHtml) {
                    $cityField.html(originals[currentContext].cityHtml);
                    $('#' + currentContext + '_city').val('');
                }

                // Clear postcode — it belongs to the previous city
                $('#' + currentContext + '_postcode').val('');

                if (state) {
                    $('#' + currentContext + '_city_field').show();
                    handleStateChange(state);
                } else {
                    $('#' + currentContext + '_city_field').hide();
                }
                // State changed → selection now incomplete; clear price + refresh.
                recalculatePrice();
            });
        }



        function deactivateEcont() {
            isEcontActive = false;

            // Snapshot the current selection BEFORE we tear the fields down, so
            // we can restore it if the user switches back to Econt. Captured
            // separately from the load-bearing resets below — those must still
            // run for a clean teardown.
            // Read from OUR private caches FIRST, not the shared DOM fields. The
            // sibling courier's updated_checkout handler runs before our deactivate
            // and may have already written ITS province/city into #billing_state /
            // #billing_city — reading the DOM here would capture the sibling's data
            // as ours. cachedState / cachedCityId hold only THIS courier's values.
            const $remCity = $('#' + currentContext + '_city');
            const domCity = ($remCity.is('select') && $remCity.hasClass('drushfe-city')) ? $remCity.val() : '';
            const remState = cachedState || $('#' + currentContext + '_state').val();
            const remCity = cachedCityId || domCity;
            if (remState || remCity || lastDeliveryType !== 'address' || lastOfficeId) {
                savedSelection = {
                    state: remState || '',
                    cityId: remCity || '',
                    deliveryType: lastDeliveryType,
                    officeId: lastOfficeId
                };
            }

            setWcAutocompleteProvider(false);

            $('#billing_state, #shipping_state').off('change.econt');

            // If the sibling Speedy plugin is the courier now taking over, it has
            // already (synchronously) re-laid-out the billing fields for itself.
            // Running the full stock-field restore here would clobber Speedy's
            // field order and re-show the address field. In that case only remove
            // OUR injected UI. Otherwise (switching to a non-courier method) do
            // the full restore so the stock checkout returns to normal.
            if (window.__drushfActiveCourier === 'speedy') {
                $('#econt-delivery-type-field, #econt-office-field, #econt-map-button-wrapper, #econt-service-field').remove();
            } else {
                restoreOriginalFields();
                restoreFieldOrder();
            }

            lastDeliveryType = 'address';
            lastOfficeId = '';
            sessionStorage.removeItem('econt_delivery_type');
            sessionStorage.removeItem('econt_office_id');
        }

        function reorderFieldsForEcont() {
            const $stateField = $('#' + currentContext + '_state_field');
            const $countryField = $('#' + currentContext + '_country_field');
            const $cityField = $('#' + currentContext + '_city_field');

            $stateField.insertAfter($countryField);
            $stateField.attr('data-priority', 41);
            
            $cityField.insertAfter($stateField);
            $cityField.attr('data-priority', 42);
            
            $('#econt-delivery-type-field').insertAfter($cityField);
        }

        function restoreFieldOrder() {
            const $stateField = $('#' + currentContext + '_state_field');
            const $countryField = $('#' + currentContext + '_country_field');
            const $cityField = $('#' + currentContext + '_city_field');
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');
            
            const originalPrio = originals[currentContext].priorities;

            $address1Field.insertAfter($countryField);
            $address2Field.insertAfter($address1Field);
            $cityField.insertAfter($address2Field);
            if (originalPrio && originalPrio.city) $cityField.attr('data-priority', originalPrio.city);

            $stateField.insertAfter($cityField);
            if (originalPrio && originalPrio.state) $stateField.attr('data-priority', originalPrio.state);
        }

        /**
         * Re-init the state select2 with our transliteration-aware matcher.
         * WooCommerce initializes it without transliteration support.
         */
        function initStateSelect2WithTransliteration() {
            EcontModern.initStateSelect2(
                $('#' + currentContext + '_state'),
                params.current_state
            );
        }

        function makeRegionRequired() {
            const $field = $('#' + currentContext + '_state_field');
            if (!$field.hasClass('validate-required')) {
                $field.addClass('validate-required');
                $field.find('label .optional').hide();
                if ($field.find('label .required').length === 0) {
                    $field.find('label').append('&nbsp;<abbr class="required" title="required">*</abbr>');
                }
            } else {
                $field.find('label .optional').hide();
                if ($field.find('label .required').length === 0) {
                    $field.find('label').append('&nbsp;<abbr class="required" title="required">*</abbr>');
                }
            }
        }

        function restoreOriginalFields() {
            const $cityInput = $('#' + currentContext + '_city');
            const $cityField = $('#' + currentContext + '_city_field');

            // Only restore the city if WE own it. If the sibling Speedy plugin
            // has already taken over the field (its own select), leave it alone —
            // tearing it down here would blank out the courier the user just
            // switched to.
            if ($cityInput.is('select') && $cityInput.hasClass('drushfe-city')) {
                if ($cityInput.data('select2')) {
                    $cityInput.select2('destroy');
                }
                $cityField.html(originals[currentContext].cityHtml);
                $('#' + currentContext + '_city').prop('disabled', false).val('');
            }

            const $stateField = $('#' + currentContext + '_state_field');
            $stateField.find('label .optional').show();
            $stateField.find('label .required').remove();
            $stateField.removeClass('validate-required');

            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            $address1Field.html(originals[currentContext].address1Html).show();
            $address2Field.html(originals[currentContext].address2Html).show();
            
            const addr1 = $address1Field.find('#' + currentContext + '_address_1').val();
            if (addr1 === params.i18n.to_office || addr1 === params.i18n.to_automat) {
                $address1Field.find('#' + currentContext + '_address_1').val('');
                $address2Field.find('#' + currentContext + '_address_2').val('');
            }
            
            $('#' + currentContext + '_postcode').val('');
            
            $('#econt-delivery-type-field').remove();
            $('#econt-office-field').remove();
                $('#econt-map-button-wrapper').remove();
            $('#econt-service-field').remove();

            $cityField.show();

            // Re-apply WC's native selectWoo on the state field so the searchable
            // dropdown is restored after we destroyed our custom Select2.
            var $stateEl = $('#' + currentContext + '_state');
            if ($stateEl.is('select') && $.fn.selectWoo) {
                if ($stateEl.hasClass('select2-hidden-accessible')) {
                    $stateEl.select2('destroy');
                }
                $stateEl.selectWoo({ width: '100%' });
            }
        }

        function handleStateChange(stateCode, preSelectedCity) {
            if (!isEcontActive || !stateCode) {
                return;
            }

            // Use loadCities which handles caching
            return loadCities(stateCode, function(cities) {
                if (cities) {
                    replaceCityInputWithSelect(cities, preSelectedCity);
                }
            });
        }

        function replaceCityInputWithSelect(cities, preSelectedCity, skipAutoTrigger) {
            const $cityField = $('#' + currentContext + '_city_field');
            const $cityWrapper = $cityField.find('.woocommerce-input-wrapper');
            const currentCity = preSelectedCity || $('#' + currentContext + '_city').val() || params.current_city_id;

            let options = '<option value="">' + params.i18n.select_city + '</option>';
            $.each(cities, function(index, city) {
                let selected = '';
                if (currentCity) {
                    if (String(city.id) === String(currentCity)) {
                        selected = 'selected';
                    } else if (city.name.toUpperCase() === String(currentCity).toUpperCase()) {
                        selected = 'selected';
                    }
                }
                
                options += '<option value="' + city.id + '" data-postcode="' + (city.postcode || '') + '" ' + selected + '>' + city.name + ' ' + (city.postcode ? '(' + city.postcode + ')' : '') + '</option>';
            });

            const selectHtml = '<select name="' + currentContext + '_city" id="' + currentContext + '_city" class="select2-hidden-accessible drushfe-city" data-placeholder="' + params.i18n.select_city + '">' + options + '</select>';
            
            $cityWrapper.html(selectHtml);

            const $newCitySelect = $('#' + currentContext + '_city');
            $newCitySelect.select2({
                width: '100%',
                matcher: modelMatcher
            });

            $newCitySelect.on('change', function() {
                handleCityChange($(this).val());
            });
            
            // When called from setupEcontUI, skip auto-trigger — the caller controls the chain.
            if (!skipAutoTrigger) {
                if ($newCitySelect.val()) {
                     handleCityChange($newCitySelect.val());
                } else {
                     settingUp = false;
                }
            }

            // Set postcode for pre-selected city
            if ($newCitySelect.val()) {
                const $sel = $newCitySelect.find(':selected');
                const pc = $sel.data('postcode');
                if (pc) {
                    $('#' + currentContext + '_postcode').val(pc).trigger('change');
                }
            }
        }

        function handleCityChange(cityId) {
            if (!cityId) return;

            const $selectedOption = $('#' + currentContext + '_city').find(':selected');
            const postcode = $selectedOption.data('postcode');
            if (postcode) {
                $('#' + currentContext + '_postcode').val(postcode).trigger('change');
            }

            loadAvailability(cityId, function(data) {
                if (data) {
                    presentDeliveryOptions(data);
                }
                settingUp = false;
                // City picked → fetch price; recalculatePrice triggers update_checkout itself.
                recalculatePrice();
            });
        }

        function presentDeliveryOptions(data) {
            $('#econt-delivery-type-field').remove();
            $('#econt-office-field').remove();
                $('#econt-map-button-wrapper').remove();
            $('#econt-service-field').remove();

            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (!data.has_office && !data.has_automat) {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
                return;
            }

            let radios = '<span class="woocommerce-input-wrapper" id="econt-delivery-type-wrapper">';
            
            radios += '<input type="radio" name="econt_delivery_type" id="econt_delivery_type_address" value="address" checked="checked" style="margin-left: 0;">' +
                      '<label for="econt_delivery_type_address" style="display: inline-block; margin-right: 15px; margin-left: 5px;">' + params.i18n.to_address + '</label>';

            if (data.has_office) {
                radios += '<input type="radio" name="econt_delivery_type" id="econt_delivery_type_office" value="office">' +
                          '<label for="econt_delivery_type_office" style="display: inline-block; margin-right: 15px; margin-left: 5px;">' + params.i18n.to_office + '</label>';
                $('#' + currentContext + '_city_field').data('offices', data.offices || []);
            }
            if (data.has_automat) {
                radios += '<input type="radio" name="econt_delivery_type" id="econt_delivery_type_automat" value="automat">' +
                          '<label for="econt_delivery_type_automat" style="display: inline-block; margin-left: 5px;">' + params.i18n.to_automat + '</label>';
                $('#' + currentContext + '_city_field').data('automats', data.automats || []);
            }
            radios += '</span>';

            const radioHtml = '<p class="form-row form-row-wide" id="econt-delivery-type-field">' +
                '<label>' + params.i18n.delivery_method + '</label>' + radios + '</p>';

            $('#' + currentContext + '_city_field').after(radioHtml);

            $('input[name="econt_delivery_type"]').on('change', function() {
                // Block FIRST so the throbber appears before the (potentially
                // 100-300ms) DOM/select2 rebuild in handleDeliveryTypeChange.
                blockOrderReview();
                handleDeliveryTypeChange($(this).val());
                // Delivery type changed → fetch price then refresh totals.
                recalculatePrice();
            });
            
            // Trigger initial state
            if (lastDeliveryType !== 'address') {
                $('input[name="econt_delivery_type"][value="' + lastDeliveryType + '"]').prop('checked', true);
            }
            handleDeliveryTypeChange(lastDeliveryType);
            placeMapButton();
        }

        function handleDeliveryTypeChange(type) {
            $('#econt-office-field').remove();
                $('#econt-map-button-wrapper').remove();
            $('#econt-service-field').remove();

            sessionStorage.setItem('econt_delivery_type', type);
            lastDeliveryType = type;
            
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (type === 'address') {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
                // Register so WC skips keydown/change/blur updates on address_1
                setWcAutocompleteProvider(true);
            } else {
                $address1Field.hide();
                $address2Field.hide();
                // Unregister — address fields are hidden, no autocomplete needed
                setWcAutocompleteProvider(false);

                if (type === 'office') {
                    $address1Field.find('input').val(params.i18n.to_office);
                } else if (type === 'automat') {
                    $address1Field.find('input').val(params.i18n.to_automat);
                }

                let points = [];
                if (type === 'office') {
                    points = $('#' + currentContext + '_city_field').data('offices');
                } else if (type === 'automat') {
                    points = $('#' + currentContext + '_city_field').data('automats');
                }

                showPointsDropdown(points, type);
            }
            placeMapButton();
        }

        function showPointsDropdown(points, type) {
            const label = (type === 'office') ? params.i18n.select_office : params.i18n.select_automat;
            let options = '<option value="" selected></option>';

            $.each(points, function(index, point) {
                options += '<option value="' + point.id + '">' + point.label + '</option>';
            });

            const selectHtml = '<p class="form-row form-row-wide" id="econt-office-field">' +
                '<label for="econt_office_id">' + label + '&nbsp;<abbr class="required" title="required">*</abbr></label>' +
                '<span class="woocommerce-input-wrapper">' +
                '<select name="econt_office_id" id="econt_office_id">' + options + '</select>' +
                '</span></p>';

            $('#econt-delivery-type-field').after(selectHtml);
            
            const $officeSelect = $('#econt_office_id');
            $officeSelect.select2({
                width: '100%',
                placeholder: label + '...',
                allowClear: true,
                matcher: modelMatcher
            });

            // Pre-select saved office (if any) BEFORE binding the change handler.
            // Otherwisek, ensure the placeholder is shown (no office selected).
            if (lastOfficeId) {
                $officeSelect.val(lastOfficeId).trigger('change.select2');
            } else {
                $officeSelect.val('').trigger('change.select2');
            }

            // Now bind the change handler for user-initiated changes
            $officeSelect.on('change', function() {
                const officeVal = $(this).val();
                const selectedText = $(this).find('option:selected').text();
                const deliveryType = $('input[name="econt_delivery_type"]:checked').val();
                
                const $address1Field = $('#' + currentContext + '_address_1_field');
                const $address2Field = $('#' + currentContext + '_address_2_field');

                if (deliveryType === 'office') {
                    $address1Field.find('input').val(params.i18n.to_office);
                } else if (deliveryType === 'automat') {
                    $address1Field.find('input').val(params.i18n.to_automat);
                }
                
                $address2Field.find('input').val(officeVal ? selectedText : '');

                lastOfficeId = officeVal || '';
                sessionStorage.setItem('econt_office_id', lastOfficeId);

                // Office/automat selected → fetch price then refresh totals.
                recalculatePrice();
            });

            // Map button is now placed via placeMapButton() below — it lives
            // independent of the dropdown and stays visible across delivery-
            // type changes.
        }

        // Service-selector (refreshServiceSelector, updateEcontPriceInUI) and the
        // map opener (openEcontMap) removed. Econt's getPrice returns a single
        // rate; service-tier UI is Speedy-only.

        // ── Street Autocomplete ──
        // When delivery type is 'address' and a city is selected, suggest
        // streets from the Econt nomenclature as the user types in address_1.
        let streetAutocompleteOpen = false;

        // Register / unregister as a WC address autocomplete provider.
        // WC's queue_update_checkout (keydown) checks this BEFORE setting
        // its 1-second update timer. We must register BEFORE the user types.
        function setWcAutocompleteProvider(active) {
            window.wc = window.wc || {};
            window.wc.addressAutocomplete = window.wc.addressAutocomplete || {};
            window.wc.addressAutocomplete.activeProvider = window.wc.addressAutocomplete.activeProvider || {};
            if (active) {
                window.wc.addressAutocomplete.activeProvider['billing'] = true;
                window.wc.addressAutocomplete.activeProvider['shipping'] = true;
            } else {
                delete window.wc.addressAutocomplete.activeProvider['billing'];
                delete window.wc.addressAutocomplete.activeProvider['shipping'];
            }
        }

        (function initStreetAutocomplete() {
            let streetTimer = null;
            let $streetList = null;
            let streetSelectedIndex = -1;
            let selectedStreetName = ''; // Track selected street to avoid re-querying

            function getStreetList() {
                if (!$streetList) {
                    $streetList = $('<ul id="econt-street-suggestions">')
                        .css({
                            border: '1px solid #ccc',
                            maxHeight: '200px',
                            overflow: 'auto',
                            listStyle: 'none',
                            padding: '5px',
                            marginTop: '0',
                            position: 'absolute',
                            background: '#fff',
                            zIndex: 9999,
                            display: 'none',
                            width: '100%',
                            boxSizing: 'border-box'
                        });
                }
                return $streetList;
            }

            function attachList() {
                const $input = $('#' + currentContext + '_address_1');
                if (!$input.length) return;
                const $wrapper = $input.closest('.woocommerce-input-wrapper, .form-row');
                $wrapper.css('position', 'relative');
                const $list = getStreetList();
                if (!$.contains(document.body, $list[0])) {
                    $wrapper.append($list);
                }
            }

            function highlightItem($items) {
                $items.css({ background: '', color: '' });
                if (streetSelectedIndex >= 0 && streetSelectedIndex < $items.length) {
                    $items.eq(streetSelectedIndex).css({ background: '#007BFF', color: '#fff' });
                }
            }

            function suppressWcUpdates() {
                if (!streetAutocompleteOpen) {
                    streetAutocompleteOpen = true;
                    // Set attribute so WC's should_skip_address_update skips
                    // change events, and should_trigger_address_blur_update
                    // skips blur events while autocomplete is open.
                    $('#' + currentContext + '_address_1')
                        .attr('data-autocomplete-manipulating', 'true');
                }
            }

            function restoreWcUpdates() {
                if (streetAutocompleteOpen) {
                    streetAutocompleteOpen = false;
                    $('#' + currentContext + '_address_1')
                        .removeAttr('data-autocomplete-manipulating');
                }
            }

            function selectStreet(street) {
                const $input = $('#' + currentContext + '_address_1');
                $input.val(street.label);
                selectedStreetName = street.label;
                getStreetList().empty().hide();
                streetSelectedIndex = -1;
                restoreWcUpdates();
                // Street picked → now have a complete address; fetch price.
                recalculatePrice();
            }

            // Listen on input events for the address field — use delegation
            // because WC may rebuild the field during AJAX updates.
            $(document.body).on('input', '#billing_address_1, #shipping_address_1', function() {
                if (!isEcontActive) return;

                const deliveryType = $('input[name="econt_delivery_type"]:checked').val();
                if (deliveryType !== 'address') return;

                const query = $(this).val();
                const $cityInput = $('#' + currentContext + '_city');
                const cityID = $cityInput.val();

                if (!cityID || query.length < 2) {
                    getStreetList().empty().hide();
                    restoreWcUpdates();
                    selectedStreetName = '';
                    return;
                }

                // If a street was already selected and the user is just
                // appending text (street number, block, etc.), don't search again.
                if (selectedStreetName && query.indexOf(selectedStreetName) === 0) {
                    return;
                }
                // If the user deleted back into the street name, reset the selection
                if (selectedStreetName && query.indexOf(selectedStreetName) !== 0) {
                    selectedStreetName = '';
                }

                suppressWcUpdates();

                clearTimeout(streetTimer);
                streetTimer = setTimeout(function() {
                    attachList();
                    $.ajax({
                        url: params.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'drushfe_search_streets',
                            nonce: params.nonce,
                            cityID: cityID,
                            name: query
                        },
                        success: function(response) {
                            const $list = getStreetList();
                            $list.empty();
                            streetSelectedIndex = -1;

                            if (response && response.length) {
                                $.each(response, function(i, street) {
                                    $('<li>')
                                        .text(street.label)
                                        .css({ cursor: 'pointer', padding: '4px 6px' })
                                        .on('mouseenter', function() {
                                            $(this).css({ background: '#f0f0f0' });
                                        })
                                        .on('mouseleave', function() {
                                            $(this).css({ background: '' });
                                        })
                                        .on('click', function() {
                                            selectStreet(street);
                                        })
                                        .appendTo($list);
                                });
                                $list.show();
                            } else {
                                $('<li>')
                                    .text(params.i18n.no_results)
                                    .css({ color: '#999', padding: '4px 6px' })
                                    .appendTo($list);
                                $list.show();
                            }
                        }
                    });
                }, 300); // Debounce 300ms
            });

            // Keyboard navigation
            $(document.body).on('keydown', '#billing_address_1, #shipping_address_1', function(e) {
                const $list = getStreetList();
                const $items = $list.find('li');
                if (!$items.length || $list.css('display') === 'none') return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    streetSelectedIndex = (streetSelectedIndex + 1) % $items.length;
                    highlightItem($items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    streetSelectedIndex = (streetSelectedIndex - 1 + $items.length) % $items.length;
                    highlightItem($items);
                } else if (e.key === 'Enter' && streetSelectedIndex >= 0) {
                    e.preventDefault();
                    let text = $items.eq(streetSelectedIndex).text();
                    if (text !== params.i18n.no_results) {
                        selectStreet({ label: text });
                    }
                } else if (e.key === 'Escape') {
                    $list.empty().hide();
                    streetSelectedIndex = -1;
                    restoreWcUpdates();
                }
            });

            // Close on outside click
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#billing_address_1, #shipping_address_1, #econt-street-suggestions').length) {
                    getStreetList().empty().hide();
                    streetSelectedIndex = -1;
                    restoreWcUpdates();
                }
            });
        })();

        // --- Select2 matcher (from econt-common.js) ---
        var modelMatcher = EcontModern.modelMatcher;

    });
})(jQuery, window.drushfe_params);




