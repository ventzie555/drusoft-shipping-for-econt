/**
 * Drusoft Shipping for Econt — Shared utilities
 *
 * Exposes window.EcontModern with transliteration and Select2 matcher
 * used by both cart.js and checkout.js.
 */
(function ($) {
    'use strict';

    /* ─── Latin → Cyrillic transliteration ────────────────── */

    const charMap = {
        'A': 'А', 'B': 'Б', 'V': 'В', 'G': 'Г', 'D': 'Д', 'E': 'Е', 'Z': 'З', 'I': 'И', 'J': 'Й', 'K': 'К', 'L': 'Л', 'M': 'М', 'N': 'Н', 'O': 'О', 'P': 'П', 'R': 'Р', 'S': 'С', 'T': 'Т', 'U': 'У', 'F': 'Ф', 'H': 'Х', 'C': 'Ц',
        'a': 'а', 'b': 'б', 'v': 'в', 'g': 'г', 'd': 'д', 'e': 'е', 'z': 'з', 'i': 'и', 'j': 'й', 'k': 'к', 'l': 'л', 'm': 'м', 'n': 'н', 'o': 'о', 'p': 'п', 'r': 'р', 's': 'с', 't': 'т', 'u': 'у', 'f': 'ф', 'h': 'х', 'c': 'ц',
        'Sht': 'Щ', 'sht': 'щ', 'Sh': 'Ш', 'sh': 'ш', 'Ch': 'Ч', 'ch': 'ч', 'Yu': 'Ю', 'yu': 'ю', 'Ya': 'Я', 'ya': 'я', 'Zh': 'Ж', 'zh': 'ж', 'Ts': 'Ц', 'ts': 'ц',
        'Y': 'Й', 'y': 'й', 'X': 'Х', 'x': 'х', 'W': 'В', 'w': 'в', 'Q': 'Я', 'q': 'я'
    };

    const multiChars = ['Sht', 'sht', 'Sh', 'sh', 'Ch', 'ch', 'Yu', 'yu', 'Ya', 'ya', 'Zh', 'zh', 'Ts', 'ts'];

    function transliterate(text) {
        for (let i = 0; i < multiChars.length; i++) {
            text = text.replace(new RegExp(multiChars[i], 'g'), charMap[multiChars[i]]);
        }

        let result = '';
        for (let i = 0; i < text.length; i++) {
            result += charMap[text[i]] || text[i];
        }
        return result;
    }

    /* ─── Select2 matcher with transliteration ────────────── */

    function modelMatcher(params, data) {
        if ($.trim(params.term) === '') {
            return data;
        }
        if (typeof data.text === 'undefined') {
            return null;
        }
        const original = data.text.toUpperCase();
        const term = params.term.toUpperCase();
        const transliteratedTerm = transliterate(params.term).toUpperCase();

        if (original.indexOf(term) > -1 || original.indexOf(transliteratedTerm) > -1) {
            return data;
        }
        return null;
    }

    /* ─── Sort state <select> options: Sofia first ────────── */

    function sortStateOptions($stateSelect) {
        if (!$stateSelect.length) return;

        const options = $stateSelect.find('option');
        const placeholder = options.filter('[value=""]');
        const sofia = options.filter('[value="BG-22"]');
        const others = options.filter(function () {
            return this.value !== '' && this.value !== 'BG-22';
        });

        others.sort(function (a, b) {
            return a.text.localeCompare(b.text);
        });

        $stateSelect.empty();
        $stateSelect.append(placeholder);
        if (sofia.length) $stateSelect.append(sofia);
        $stateSelect.append(others);
    }

    /* ─── Init state select2 with transliteration ─────────── */

    function initStateSelect2($stateSelect, currentState) {
        if (!$stateSelect.length || !$stateSelect.is('select')) return;

        const currentVal = currentState || $stateSelect.val();

        sortStateOptions($stateSelect);

        if ($stateSelect.hasClass('select2-hidden-accessible')) {
            $stateSelect.select2('destroy');
        }

        $stateSelect.select2({
            width: '100%',
            matcher: modelMatcher
        });

        if (currentVal) {
            $stateSelect.val(currentVal).trigger('change.select2');
        }
    }

    /* ─── Expose public API ───────────────────────────────── */

    window.EcontModern = {
        transliterate:    transliterate,
        modelMatcher:     modelMatcher,
        sortStateOptions: sortStateOptions,
        initStateSelect2: initStateSelect2
    };

})(jQuery);

