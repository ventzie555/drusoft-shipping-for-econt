/* global jQuery, L */
/**
 * Drusoft Shipping for Econt — office/automat map module.
 *
 * Exposes window.DrushfeMap with one method: open(points, onSelect, opts).
 *
 *   points: Array<OfficePoint> — full list to plot; filters are applied
 *           client-side based on user toggles.
 *   onSelect: function(point): void — called when the user clicks "Select"
 *             inside a marker popup.
 *   opts: {
 *     title?: string,
 *     hint?: string,
 *     pickLabel?: string,
 *     errorLabel?: string,
 *     i18n?: { offices?: string, automats?: string, both?: string,
 *              search_placeholder?: string, search_no_results?: string },
 *     defaultFilter?: 'office' | 'automat' | 'both',   // default 'both'
 *   }
 *
 * @typedef {Object} OfficePoint
 * @property {string|number} id
 * @property {string}        name
 * @property {string}        address
 * @property {string}        [city_name]
 * @property {string}        office_type   // 'APS' (automat) or 'OFFICE'
 * @property {number}        lat
 * @property {number}        lng
 */

(function ($) {
    'use strict';

    var LEAFLET_VERSION = '1.9.4';
    var LEAFLET_CSS = 'https://unpkg.com/leaflet@' + LEAFLET_VERSION + '/dist/leaflet.css';
    var LEAFLET_JS  = 'https://unpkg.com/leaflet@' + LEAFLET_VERSION + '/dist/leaflet.js';

    var leafletPromise = null;
    var $modal = null;
    var mapInstance = null;
    var markerLayer = null;
    // Index from point.id → Leaflet marker, populated each renderMarkers().
    // Used by the suggestions list to pan+open the popup for a picked row
    // without re-creating the marker.
    var markersById = {};

    // Module-scoped state used by the filter handlers — populated in open().
    var allPoints = [];
    var currentFilterType = 'both';
    var currentSearch = '';
    var currentOnSelect = null;
    var currentOpts = {};

    /**
     * Load Leaflet CSS + JS once, return a Promise that resolves when window.L
     * is available.
     */
    function loadLeaflet() {
        if (leafletPromise) return leafletPromise;
        leafletPromise = new Promise(function (resolve, reject) {
            if (!document.querySelector('link[data-drushfe-leaflet]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = LEAFLET_CSS;
                link.setAttribute('data-drushfe-leaflet', '1');
                document.head.appendChild(link);
            }
            if (window.L) { resolve(window.L); return; }
            var existing = document.querySelector('script[data-drushfe-leaflet]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(window.L); });
                existing.addEventListener('error', function () { reject(new Error('Leaflet failed to load')); });
                return;
            }
            var script = document.createElement('script');
            script.src = LEAFLET_JS;
            script.async = true;
            script.setAttribute('data-drushfe-leaflet', '1');
            script.addEventListener('load', function () { resolve(window.L); });
            script.addEventListener('error', function () { reject(new Error('Leaflet failed to load')); });
            document.head.appendChild(script);
        });
        return leafletPromise;
    }

    function ensureModal() {
        if ($modal && $modal.length && document.body.contains($modal[0])) return $modal;

        $modal = $(
            '<div class="drushfe-map-modal" id="drushfe-map-modal" aria-hidden="true">' +
                '<div class="drushfe-map-modal__content">' +
                    '<button type="button" class="drushfe-map-modal__close" aria-label="Close">&times;</button>' +
                    '<h3 class="drushfe-map-modal__title"></h3>' +
                    '<div class="drushfe-map-modal__filters">' +
                        '<div class="drushfe-map-modal__radios">' +
                            '<label><input type="radio" name="drushfe-map-filter" value="office"> <span class="drushfe-map-modal__radio-label drushfe-map-modal__radio-label--office"></span></label>' +
                            '<label><input type="radio" name="drushfe-map-filter" value="automat"> <span class="drushfe-map-modal__radio-label drushfe-map-modal__radio-label--automat"></span></label>' +
                            '<label><input type="radio" name="drushfe-map-filter" value="both" checked> <span class="drushfe-map-modal__radio-label drushfe-map-modal__radio-label--both"></span></label>' +
                        '</div>' +
                        '<div class="drushfe-map-modal__search-wrap">' +
                            '<input type="search" class="drushfe-map-modal__search" autocomplete="off" />' +
                            '<ul class="drushfe-map-modal__suggestions" hidden></ul>' +
                        '</div>' +
                        '<span class="drushfe-map-modal__count"></span>' +
                    '</div>' +
                    '<div class="drushfe-map-modal__map" id="drushfe-map-canvas"></div>' +
                    '<p class="drushfe-map-modal__hint"></p>' +
                '</div>' +
            '</div>'
        );
        $('body').append($modal);

        $modal.on('click', '.drushfe-map-modal__close', closeModal);
        $modal.on('click', function (e) {
            if (e.target === $modal[0]) closeModal();
        });
        $(document).on('keydown.drushfeMap', function (e) {
            if (e.key === 'Escape') closeModal();
        });

        // Filter wiring (delegated on modal because content swaps).
        $modal.on('change', 'input[name="drushfe-map-filter"]', function () {
            currentFilterType = $(this).val();
            renderMarkers();
            renderSuggestions();
        });
        var searchDebounce = null;
        $modal.on('input', '.drushfe-map-modal__search', function () {
            var v = $(this).val();
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(function () {
                currentSearch = (v || '').toString().trim().toLowerCase();
                renderMarkers();
                renderSuggestions();
            }, 150);
        });

        // Click a suggestion → pan/zoom the map to the matching marker and
        // open its popup. The user then confirms via the popup's "Select"
        // button (same flow as clicking a marker directly). This keeps a
        // single confirmation path and gives visual context before committing.
        $modal.on('mousedown', '.drushfe-map-modal__suggestions li[data-pick-idx]', function (e) {
            // mousedown (not click) — Safari fires blur on search before click,
            // which would hide the list before the click registers.
            e.preventDefault();
            var idx = parseInt($(this).attr('data-pick-idx'), 10);
            if (isNaN(idx)) return;
            var p = lastSuggested[idx];
            if (!p || !mapInstance || !isFinite(p.lat) || !isFinite(p.lng)) return;

            // Hide the dropdown so the user can see the map clearly.
            $modal.find('.drushfe-map-modal__suggestions').attr('hidden', true);
            $modal.find('.drushfe-map-modal__search').trigger('blur');

            // Pan + zoom; openPopup once the move animation completes (Leaflet
            // doesn't open popups reliably mid-animation).
            mapInstance.flyTo([p.lat, p.lng], Math.max(mapInstance.getZoom(), 15), { duration: 0.4 });
            var marker = markersById[String(p.id)];
            // Defer the popup open until after the fly animation.
            setTimeout(function () {
                if (marker) marker.openPopup();
            }, 450);
        });

        // Hide suggestions on blur (with a tiny delay so the mousedown above can fire first).
        $modal.on('blur', '.drushfe-map-modal__search', function () {
            setTimeout(function () { $modal.find('.drushfe-map-modal__suggestions').attr('hidden', true); }, 150);
        });
        $modal.on('focus', '.drushfe-map-modal__search', function () {
            if (currentSearch) renderSuggestions();
        });

        return $modal;
    }

    // Holds the last list rendered into the suggestions <ul> so click handlers
    // can pick the right object by index without serialising the whole point
    // into the data-attribute.
    var lastSuggested = [];

    function renderSuggestions() {
        var $sug = $modal.find('.drushfe-map-modal__suggestions');
        if (!currentSearch) {
            lastSuggested = [];
            $sug.empty().attr('hidden', true);
            return;
        }
        var pts = applyFilters().slice(0, 12);
        lastSuggested = pts;
        if (!pts.length) {
            $sug.html('<li class="drushfe-map-modal__suggestion-empty">' +
                escapeHtml((currentOpts.i18n && currentOpts.i18n.search_no_results) || 'No matches') +
                '</li>').removeAttr('hidden');
            return;
        }
        var html = pts.map(function (p, i) {
            var label = (p.office_type === 'APS') ? '📦 ' : '🏢 ';
            return '<li data-pick-idx="' + i + '">' +
                '<span class="drushfe-map-modal__sug-name">' + label + escapeHtml(p.name || '') + '</span>' +
                (p.city_name ? ' <span class="drushfe-map-modal__sug-city">' + escapeHtml(p.city_name) + '</span>' : '') +
                (p.address ? '<br/><span class="drushfe-map-modal__sug-addr">' + escapeHtml(p.address) + '</span>' : '') +
                '</li>';
        }).join('');
        $sug.html(html).removeAttr('hidden');
    }

    function closeModal() {
        if (!$modal) return;
        $modal.removeClass('is-open').attr('aria-hidden', 'true');
        if (mapInstance) { mapInstance.remove(); mapInstance = null; markerLayer = null; }
    }

    function applyFilters() {
        // Build a transliterated version of the search term so a user typing
        // Latin (e.g. "Sofia") matches Cyrillic content (e.g. "София").
        // EcontModern.transliterate is provided by econt-common.js — fall back
        // to identity if for some reason that's missing (defensive only).
        var transliterate = (window.EcontModern && typeof window.EcontModern.transliterate === 'function')
            ? window.EcontModern.transliterate
            : function (s) { return s; };
        var altSearch = currentSearch ? transliterate(currentSearch).toLowerCase() : '';

        return allPoints.filter(function (p) {
            if (currentFilterType === 'office'  && p.office_type === 'APS') return false;
            if (currentFilterType === 'automat' && p.office_type !== 'APS') return false;
            if (currentSearch) {
                var hay = ((p.name || '') + ' ' + (p.city_name || '') + ' ' + (p.address || '')).toLowerCase();
                if (hay.indexOf(currentSearch) === -1 && (altSearch === currentSearch || hay.indexOf(altSearch) === -1)) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Wipe the marker layer and rebuild it from the currently-filtered point
     * set. Also re-fits the map bounds and updates the result-count label.
     * Safe to call before Leaflet has loaded — it bails until mapInstance exists.
     */
    function renderMarkers() {
        if (!mapInstance || !markerLayer || !window.L) return;
        markerLayer.clearLayers();
        markersById = {};

        var pts = applyFilters();
        var bounds = null;
        pts.forEach(function (p) {
            if (!isFinite(p.lat) || !isFinite(p.lng) || (p.lat === 0 && p.lng === 0)) return;
            var marker = window.L.marker([p.lat, p.lng]).addTo(markerLayer);
            markersById[String(p.id)] = marker;
            var popup = window.L.DomUtil.create('div', 'drushfe-map-popup');
            popup.innerHTML =
                '<strong>' + escapeHtml(p.name || '') + '</strong>' +
                (p.city_name ? '<br/><span class="drushfe-map-popup__city">' + escapeHtml(p.city_name) + '</span>' : '') +
                (p.address ? '<br/><small>' + escapeHtml(p.address) + '</small>' : '') +
                '<br/><button type="button" class="button button-primary drushfe-map-popup__pick" style="margin-top:6px;">' +
                escapeHtml(currentOpts.pickLabel || 'Select') + '</button>';
            marker.bindPopup(popup);
            marker.on('popupopen', function (e) {
                var btn = e.popup.getElement().querySelector('.drushfe-map-popup__pick');
                if (!btn) return;
                btn.addEventListener('click', function () {
                    try { currentOnSelect && currentOnSelect(p); } catch (err) { console.error('[drushfe-map] onSelect error:', err); }
                    closeModal();
                }, { once: true });
            });
            bounds = bounds ? bounds.extend([p.lat, p.lng]) : window.L.latLngBounds([p.lat, p.lng], [p.lat, p.lng]);
        });

        // Update result count label.
        var label = (currentOpts.i18n && currentOpts.i18n.results_count) || '{n} results';
        $modal.find('.drushfe-map-modal__count').text(label.replace('{n}', pts.length));

        if (bounds && bounds.isValid()) {
            mapInstance.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
        } else {
            // No matches → centre on Bulgaria.
            mapInstance.setView([42.7339, 25.4858], 7);
        }
    }

    function open(points, onSelect, opts) {
        opts = opts || {};
        currentOpts = opts;
        currentOnSelect = onSelect;
        allPoints = Array.isArray(points) ? points.slice() : [];
        currentFilterType = opts.defaultFilter || 'both';
        currentSearch = '';

        ensureModal();
        $modal.find('.drushfe-map-modal__title').text(opts.title || '');
        $modal.find('.drushfe-map-modal__hint').text(opts.hint || '');

        // Localise the filter labels.
        var i18n = opts.i18n || {};
        $modal.find('.drushfe-map-modal__radio-label--office').text(i18n.offices  || 'Offices');
        $modal.find('.drushfe-map-modal__radio-label--automat').text(i18n.automats || 'Automats');
        $modal.find('.drushfe-map-modal__radio-label--both').text(i18n.both || 'Both');
        $modal.find('.drushfe-map-modal__search').attr('placeholder', i18n.search_placeholder || 'Search city, name…');

        $modal.find('input[name="drushfe-map-filter"][value="' + currentFilterType + '"]').prop('checked', true);
        $modal.find('.drushfe-map-modal__search').val('');
        $modal.find('.drushfe-map-modal__suggestions').empty().attr('hidden', true);
        lastSuggested = [];

        $modal.addClass('is-open').attr('aria-hidden', 'false');

        loadLeaflet().then(function (L) {
            if (mapInstance) { mapInstance.remove(); mapInstance = null; }

            mapInstance = L.map('drushfe-map-canvas');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(mapInstance);
            markerLayer = L.layerGroup().addTo(mapInstance);

            renderMarkers();

            // Leaflet needs invalidateSize when the container has just become visible.
            setTimeout(function () { if (mapInstance) mapInstance.invalidateSize(); }, 50);
        }).catch(function (err) {
            $modal.find('.drushfe-map-modal__map').text(
                (opts.errorLabel || 'Map could not be loaded:') + ' ' + (err && err.message ? err.message : err)
            );
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    window.DrushfeMap = { open: open, close: closeModal };
})(jQuery);
