(function ($, params) {
    'use strict';

    let isEcontActive = false;
    let originalCityHtml = null;
    let $cityField = null;
    let $postcodeField = null;
    let $stateField = null;

    // Deduplication trackers — survive across updated_cart_totals so we don't
    // re-fetch cities or re-check availability when nothing actually changed.
    let lastStateProcessed = null;
    let lastCityProcessed = null;

    // Guard flag: true while we are waiting for a cart update that WE triggered.
    // Prevents the updated_cart_totals handler from cascading into another cycle.
    let cartUpdatePending = false;

    // Guard flag: true while an AJAX session-update + cart-update sequence is
    // in progress.  Prevents double-clicks / rapid changes from stacking up.
    let isUpdating = false;

    // Wall-clock-based counter so a fresh page load is always newer than any
    // previously-stored value in the WC session.
    let flowVersion = Date.now();

    /**
     * Fetch live Econt shipping price for the current cart selection.
     * Stores the result in WC session under `drushfe_shipping_cost`; the
     * subsequent calculate_shipping (via the cart-update click) reads it.
     *
     * Returns a jQuery deferred so the caller can chain a cart update.
     */
    function recalculatePrice() {
        const deliveryType = $('input[name="econt_cart_type"]:checked').val()
            || params.current_type || 'address';
        const cityId   = params.current_city_id || $('#calc_shipping_city').val() || '';
        // Strip trailing " (NNNN)" postcode suffix — Econt rejects it as mismatched.
        const cityName = ($('#calc_shipping_city option:selected').text() || '')
                            .replace(/\s*\(\d+\)\s*$/, '')
                            .trim();
        const postcode = $('#calc_shipping_postcode').val() || '';
        const state    = $('#calc_shipping_state').val() || '';

        // Only "no city" is genuinely incomplete on the cart page. For
        // office/automat mode we don't have an office picker, but the server
        // picks a joker office (first office / first APS for the city) so
        // the user still gets a representative price preview.
        const incomplete = !cityId;

        flowVersion += 1;
        const myVersion = flowVersion;

        const endpoint = incomplete ? 'drushfe_clear_price' : 'drushfe_calculate_price';
        const payload  = incomplete
            ? { action: endpoint, nonce: params.nonce, flow_version: myVersion }
            : {
                action:        endpoint,
                nonce:         params.nonce,
                flow_version:  myVersion,
                delivery_type: deliveryType,
                city_id:       cityId,
                city_name:     cityName,
                postcode:      postcode,
                state:         state,
                office_code:   '',
                address:       '',
            };

        return $.ajax({
            url:    params.ajax_url,
            method: 'POST',
            data:   payload,
        });
    }

    $(document).ready(function () {
        initCartElements();
        handleEcontCart();

        $(document.body).on('updated_cart_totals', function () {
            // DOM elements are replaced after a cart update — re-grab them.
            initCartElements();

            if (cartUpdatePending) {
                // This update was triggered by us (city change, type change).
                // The session already has the correct data; we just need to
                // re-render the Econt UI on the new DOM — NOT fetch cities or
                // trigger another cart update.
                cartUpdatePending = false;
                isUpdating = false;
                restoreEcontUI();
                return;
            }

            // Genuinely external cart update (quantity change, coupon, etc.).
            // Re-render our UI. Don't reset dedup trackers — state/city haven't
            // changed, so we don't need to re-fetch.
            restoreEcontUI();
        });

        // Listen for standard WooCommerce state changes in the calculator
        $(document).on('change', 'select#calc_shipping_state', function () {
            if (isEcontActive) {
                const state = $(this).val();
                params.current_state = state;
                // State genuinely changed by the user — reset city tracker
                lastCityProcessed = null;
                // Clear postcode — it belongs to the previous city
                $postcodeField = $('#calc_shipping_postcode');
                $postcodeField.val('');
                // Persist state to WC session immediately
                saveSelectionToSession();
                handleCalculatorStateChange(state);
            }
        });

        // Listen for shipping method changes — use mousedown in CAPTURE phase
        // so our DOM cleanup runs before any other plugin's event handlers.
        document.addEventListener('mousedown', function(e) {
            const radio = e.target.closest('input[name^="shipping_method"]');
            if (!radio) return;

            const isEcont = radio.value && radio.value.indexOf(params.method_id) === 0;
            if (!isEcont && isEcontActive) {
                isEcontActive = false;
                $('#econt-cart-selector').remove();
                resetCalculatorUI();
            }
        }, true);

        // Listen for shipping method radio changes — activation (switching TO Econt)
        $(document).on('change', 'input[name^="shipping_method"]', function () {
            const $selected = $(this);
            const isEcont = $selected.val() && $selected.val().indexOf(params.method_id) === 0;

            if (isEcont && !isEcontActive) {
                isEcontActive = true;
                initCartElements();

                renderEcontSelector($selected.closest('li'));
                initStateSelect2();
                $postcodeField = $('#calc_shipping_postcode');
                $postcodeField.prop('readonly', true).css('background-color', '#eee');
                $('button[name="calc_shipping"]').hide();

                // Open the calculator form so the user can pick a state/city
                const $calcForm = $('.shipping-calculator-form');
                if ($calcForm.length && $calcForm.is(':hidden')) {
                    $calcForm.show();
                }

                // If a state is already selected, load cities
                const state = $stateField.val() || params.current_state;
                if (state) {
                    handleCalculatorStateChange(state);
                }

                // Initial price calc — covers the case where the user
                // landed on the cart with WC session-state already pointing
                // to a city, just clicked the Econt radio, and now expects
                // a shipping cost to show up without further input.
                recalculatePriceIfReady();
            }
        });
    });

    /* ─── DOM helpers ─────────────────────────────────────── */

    function initCartElements() {
        $cityField = $('#calc_shipping_city');
        $postcodeField = $('#calc_shipping_postcode');
        $stateField = $('#calc_shipping_state');

        // Save the original city input HTML once (before we replace it)
        if ($cityField.length && originalCityHtml === null && $cityField.is('input')) {
            originalCityHtml = $cityField.parent().html();
        }
    }

    /**
     * After updated_cart_totals the entire shipping HTML is rebuilt by WC.
     * Re-render the Econt selector and, if we already loaded cities for
     * the current state, rebuild the city dropdown without a new AJAX call.
     */
    function getSelectedShippingMethod() {
        return $('input[name^="shipping_method"][type="radio"]:checked, input[name^="shipping_method"][type="hidden"]').first();
    }

    function restoreEcontUI() {
        const $selectedMethod = getSelectedShippingMethod();
        const isEcont = $selectedMethod.val() && $selectedMethod.val().indexOf(params.method_id) === 0;

        isEcontActive = isEcont;

        if (!isEcont) {
            $('#econt-cart-selector').remove();
            resetCalculatorUI();
            return;
        }

        renderEcontSelector($selectedMethod.closest('li'));

        // Re-init state as searchable select2 with transliteration
        initStateSelect2();

        $postcodeField = $('#calc_shipping_postcode');
        $postcodeField.prop('readonly', true).css('background-color', '#eee');

        // Hide the calculator "Update" button — updates are automatic
        $('button[name="calc_shipping"]').hide();

        // Keep the calculator form open when a city is already chosen
        if (params.current_city_id || params.current_state) {
            const $calcForm = $('.shipping-calculator-form');
            if ($calcForm.length && $calcForm.is(':hidden')) {
                $calcForm.show();
            }
        }

        // Read availability from the server-rendered hidden element
        const $avail = $('#econt-availability-data');
        if ($avail.length) {
            cachedHasOffices = $avail.data('has-office') === 1;
            cachedHasAutomats = $avail.data('has-automat') === 1;
        }

        // Re-apply availability so office/automat radios are visible,
        // or hide the entire selector when only address is available.
        updateRadioVisibilityUI(cachedHasOffices, cachedHasAutomats);

        // If we already fetched cities, rebuild the dropdown from cache.
        // WC sometimes replaces the cart fragment a second time after our
        // updated_cart_totals handler runs — without re-firing the event —
        // which reverts our <select> back to the default <input>. We re-check
        // on a short timeout and re-apply when needed.
        ensureCitySelect2();

        // Fire a price calc so the shipping line shows a real cost on the
        // first render — handlers below only re-fire on explicit user changes.
        recalculatePriceIfReady();
    }

    /**
     * Re-apply our select2-ified city dropdown if WC has reverted it to its
     * native <input> (which happens after some update_cart cycles, sometimes
     * a tick after updated_cart_totals fires). Idempotent — bails if the
     * field is already select2 or if we don't have cached cities to work with.
     *
     * Called from restoreEcontUI plus polled briefly after every cart update
     * to defend against late DOM rebuilds.
     */
    function ensureCitySelect2(retries) {
        if (typeof retries !== 'number') retries = 5;
        if (!isEcontActive) return;
        if (!cachedCities || !lastStateProcessed) return;

        const $city = $('#calc_shipping_city');
        if (!$city.length) {
            // Cart calculator not in the DOM yet — try again briefly.
            if (retries > 0) setTimeout(() => ensureCitySelect2(retries - 1), 100);
            return;
        }

        // Already a select2'd select? Nothing to do.
        if ($city.is('select') && $city.hasClass('select2-hidden-accessible')) {
            // Still poll once more in case WC rebuilds it again.
            if (retries > 0) setTimeout(() => ensureCitySelect2(retries - 1), 100);
            return;
        }

        replaceCalculatorCityWithSelect(cachedCities);
        if (retries > 0) setTimeout(() => ensureCitySelect2(retries - 1), 100);
    }

    /**
     * Fire recalculatePrice() iff we have enough state to produce a quote.
     * Used in initial-load paths where the user hasn't *changed* anything
     * but the page restored prior selections from the WC session.
     */
    function recalculatePriceIfReady() {
        const cityId = params.current_city_id || $('#calc_shipping_city').val() || '';
        if (!cityId) return;
        recalculatePrice();
    }

    /* ─── Main entry on first load ────────────────────────── */

    function handleEcontCart() {
        const $selectedMethod = getSelectedShippingMethod();
        const isEcont = $selectedMethod.val() && $selectedMethod.val().indexOf(params.method_id) === 0;

        isEcontActive = isEcont;

        if (isEcont) {
            // WC core cart script hides the calculator on ready.
            // Defer our .show() so it runs after WC initialization.
            setTimeout(function () {
                $('.shipping-calculator-form').show();
            }, 0);
            renderEcontSelector($selectedMethod.closest('li'));

            // Init state as searchable select2 with transliteration
            initStateSelect2();
            $postcodeField.prop('readonly', true).css('background-color', '#eee');

            // Hide the calculator "Update" button — updates are automatic
            $('button[name="calc_shipping"]').hide();

            // If a city is already chosen, keep the calculator form open
            if (params.current_city_id || params.current_state) {
                const $calcForm = $('.shipping-calculator-form');
                if ($calcForm.length && $calcForm.is(':hidden')) {
                    $calcForm.show();
                }
            }

            // Use server-rendered availability data (no AJAX needed)
            if (params.current_city_id) {
                cachedHasOffices = !!params.has_office;
                cachedHasAutomats = !!params.has_automat;
                updateRadioVisibilityUI(cachedHasOffices, cachedHasAutomats);
            }

            // Load cities for the state
            const state = $stateField.val() || params.current_state;
            if (state) {
                handleCalculatorStateChange(state);
            }

            // Initial price calc — covers the page-load case where Econt was
            // already selected (e.g. from a previous visit's WC session) and
            // a city is set, but no change event has fired since the last
            // clear_price.
            recalculatePriceIfReady();
        } else {
            $('#econt-cart-selector').remove();
            resetCalculatorUI();
        }
    }

    /* ─── Persist selections to WC session (for checkout page) ── */

    /**
     * Save current Econt selections to the WC session via AJAX.
     * This ensures the checkout page can read them even if the user
     * navigates to checkout without clicking the cart "Update" button.
     */
    function saveSelectionToSession() {
        const state = $stateField ? $stateField.val() : (params.current_state || '');
        const cityId = params.current_city_id || '';
        const deliveryType = params.current_type || 'address';
        const officeId = 0; // office is only relevant in checkout

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_save_cart_selection',
                nonce: params.nonce,
                state: state,
                city_id: cityId,
                delivery_type: deliveryType,
                office_id: officeId
            }
            // Fire-and-forget — no need to handle response
        });
    }

    /* ─── State → cities ──────────────────────────────────── */

    // Keep a cache of the last-fetched cities so we can rebuild the dropdown
    // after updated_cart_totals without a new AJAX call.
    let cachedCities = null;

    // Cache the last availability result so we can re-apply radio visibility
    // after the DOM is rebuilt by updated_cart_totals.
    let cachedHasOffices = false;
    let cachedHasAutomats = false;

    function handleCalculatorStateChange(stateCode) {
        if (!stateCode || stateCode === lastStateProcessed) return;
        lastStateProcessed = stateCode;
        cachedCities = null; // new state — invalidate city cache

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_get_cities',
                nonce: params.nonce,
                region: stateCode
            },
            success: function (response) {
                if (response.success) {
                    cachedCities = response.data;
                    replaceCalculatorCityWithSelect(response.data);
                }
            }
        });
    }

    /* ─── City dropdown ───────────────────────────────────── */

    function smartCityMatch(cityIdToSelect, city) {
        const id = String(city.id);
        const target = String(cityIdToSelect).toUpperCase();
        const cityName = city.name.toUpperCase();

        if (id === target) return true;
        if (cityName === target) return true;
        return cityName.replace(/^(ГР\.|С\.)\s+/i, '') === target;


    }

    /**
     * Build and insert the city <select> dropdown from the given city list.
     */
    function replaceCalculatorCityWithSelect(cities) {
        $cityField = $('#calc_shipping_city');
        const currentCityVal = $cityField.val();
        const cityIdToSelect = params.current_city_id || currentCityVal;

        let options = `<option value="">${params.i18n.select_city || 'Select city'}</option>`;

        $.each(cities, function (index, city) {
            let selected = '';
            if (cityIdToSelect && smartCityMatch(cityIdToSelect, city)) {
                selected = 'selected';
                params.current_city_id = city.id;
            }
            options += `<option value="${city.id}" data-postcode="${city.postcode || ''}" ${selected}>${city.name} ${city.postcode ? '(' + city.postcode + ')' : ''}</option>`;
        });

        const $wrapper = $cityField.parent();
        if ($wrapper.find('select').length) {
            try { $wrapper.find('select').select2('destroy'); } catch (e) { /* ok */ }
        }
        $wrapper.html(`<select name="calc_shipping_city" id="calc_shipping_city" class="econt-city-select">${options}</select>`);

        $cityField = $('#calc_shipping_city');

        $cityField.select2({
            width: '100%',
            matcher: modelMatcher
        });

        $cityField.on('change', function () {
            handleCityChange($(this).val());
        });

        // If a city is already selected, set the postcode.
        // Availability is handled by server-rendered data, not a separate call.
        const selectedVal = $cityField.val();
        if (selectedVal) {
            const $selected = $cityField.find(':selected');
            const postcode = $selected.data('postcode');
            if (postcode) {
                $postcodeField.val(postcode);
            }
            lastCityProcessed = selectedVal;
        }
    }

    /* ─── City change (user-initiated) ────────────────────── */

    function handleCityChange(cityId) {
        if (!cityId || isUpdating || cityId === lastCityProcessed) return;
        lastCityProcessed = cityId;

        const $selected = $cityField.find(':selected');
        const postcode = $selected.data('postcode');

        if (postcode) {
            $postcodeField.val(postcode);
        }

        params.current_city_id = cityId;

        // Update hidden fields so the cart form submission carries the data
        $('#econt_cart_city_id').val(cityId);
        const currentType = $('input[name="econt_cart_type"]:checked').val() || params.current_type || 'address';
        $('#econt_cart_delivery_type').val(currentType);

        // Persist to WC session immediately (for checkout page)
        saveSelectionToSession();

        // Fetch live Econt price into session, then trigger WC cart update.
        // calculate_shipping reads `drushfe_shipping_cost` from session.
        isUpdating = true;
        cartUpdatePending = true;
        recalculatePrice().always(function () {
            $("[name='update_cart']").prop('disabled', false).trigger('click');
        });
    }

    /* ─── Radio visibility ────────────────────────────────── */

    function updateRadioVisibilityUI(hasOffices, hasAutomats) {
        const $selector = $('#econt-cart-selector');
        const $officeOpt = $('.econt-cart-option[data-type="office"]');
        const $automatOpt = $('.econt-cart-option[data-type="automat"]');

        if (hasOffices) $officeOpt.show(); else $officeOpt.hide();
        if (hasAutomats) $automatOpt.show(); else $automatOpt.hide();

        // Hide the entire selector when address is the only option
        if (!hasOffices && !hasAutomats) {
            $selector.hide();
            // Ensure address is selected
            params.current_type = 'address';
            $('input[name="econt_cart_type"][value="address"]').prop('checked', true);
        } else {
            $selector.show();
        }
    }

    /* ─── Reset (when user switches away from Econt) ─────── */

    function resetCalculatorUI() {
        // Restore the calculator "Update" button
        $('button[name="calc_shipping"]').show();

        if (originalCityHtml !== null && $('#calc_shipping_city').is('select')) {
            try { $cityField.select2('destroy'); } catch (e) { /* ok */ }
            $cityField.parent().html(originalCityHtml);
            $cityField = $('#calc_shipping_city');
            $postcodeField.prop('readonly', false).css('background-color', '');
            // Don't reset lastStateProcessed/lastCityProcessed here —
            // if the user switches back to Econt the data is still valid.
        }

        // Re-apply WC's native selectWoo on the state field so the searchable
        // dropdown is restored after we destroyed our custom Select2.
        var $state = $('select#calc_shipping_state');
        if ($state.length && $.fn.selectWoo) {
            if ($state.hasClass('select2-hidden-accessible')) {
                $state.select2('destroy');
            }
            $state.selectWoo({ width: '100%' });
        }

        // Also re-apply selectWoo on the country field in case it was stripped.
        var $country = $('select#calc_shipping_country');
        if ($country.length && $.fn.selectWoo) {
            if (!$country.hasClass('select2-hidden-accessible')) {
                $country.selectWoo({ width: '100%' });
            }
        }
    }

    /* ─── Delivery-type selector ──────────────────────────── */

    function renderEcontSelector($container) {
        if ($('#econt-cart-selector').length) {
            if (params.current_type) {
                $(`input[name="econt_cart_type"][value="${params.current_type}"]`).prop('checked', true);
            }
            return;
        }

        const html = `
            <div id="econt-cart-selector">
                <p class="econt-cart-heading">${params.i18n.select_service || 'Select delivery type:'}</p>
                <div id="econt-cart-options">
                    <div class="econt-cart-option" data-type="address">
                        <label>
                            <input type="radio" name="econt_cart_type" value="address">
                            <span>${params.i18n.to_address}</span>
                        </label>
                    </div>
                    <div class="econt-cart-option" data-type="office" style="display: none;">
                        <label>
                            <input type="radio" name="econt_cart_type" value="office">
                            <span>${params.i18n.to_office}</span>
                        </label>
                    </div>
                    <div class="econt-cart-option" data-type="automat" style="display: none;">
                        <label>
                            <input type="radio" name="econt_cart_type" value="automat">
                            <span>${params.i18n.to_automat}</span>
                        </label>
                    </div>
                </div>
            </div>
        `;

        $container.append(html);

        if (params.current_type) {
            $(`input[name="econt_cart_type"][value="${params.current_type}"]`).prop('checked', true);
        }

        $(document).off('change', 'input[name="econt_cart_type"]').on('change', 'input[name="econt_cart_type"]', function () {
            if (isUpdating) return;
            const type = $(this).val();
            params.current_type = type;

            // Update hidden fields and trigger cart update directly
            $('#econt_cart_delivery_type').val(type);
            $('#econt_cart_city_id').val(params.current_city_id || '');

            // Persist to WC session immediately (for checkout page)
            saveSelectionToSession();

            isUpdating = true;
            cartUpdatePending = true;
            $('#econt-cart-selector').addClass('econt-updating');
            // Fetch live Econt price first; calculate_shipping reads from session.
            recalculatePrice().always(function () {
                $("[name='update_cart']").prop('disabled', false).trigger('click');
            });
        });
    }

    /* ─── Select2 helpers (from econt-common.js) ────────── */

    var modelMatcher  = EcontModern.modelMatcher;

    /* ─── State select2 with transliteration + Sofia first ── */

    function initStateSelect2() {
        EcontModern.initStateSelect2($('select#calc_shipping_state'), params.current_state);

        // Re-apply selectWoo on the country field — our select2 destroy/re-init
        // on the state field can strip selectWoo from sibling selects.
        var $country = $('select#calc_shipping_country');
        if ($country.length && $.fn.selectWoo) {
            if (!$country.hasClass('select2-hidden-accessible')) {
                $country.selectWoo({ width: '100%' });
            }
        }
    }

})(jQuery, window.drushfe_params);
