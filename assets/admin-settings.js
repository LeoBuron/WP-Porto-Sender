/**
 * WP-Porto-Sender — admin settings screen.
 *
 * Tabs are presentation-only: every field lives in one <form>, so switching tabs
 * only shows/hides panels (no field ever leaves the POST). Also wires the native
 * WordPress colour pickers and the one-click colour-scheme preset buttons.
 *
 * Progressive enhancement: this script adds `.porto-js-tabs` to <html>; the CSS
 * only hides inactive panels under that class, so with JS disabled every panel
 * stays visible (a plain long settings page).
 */
(function () {
    'use strict';

    document.documentElement.classList.add('porto-js-tabs');

    var links = Array.prototype.slice.call(
        document.querySelectorAll('.nav-tab-wrapper [data-porto-tab]')
    );
    var panels = Array.prototype.slice.call(
        document.querySelectorAll('.porto-tab-panel')
    );

    function activate(slug) {
        links.forEach(function (link) {
            link.classList.toggle('nav-tab-active', link.getAttribute('data-porto-tab') === slug);
        });
        panels.forEach(function (panel) {
            panel.classList.toggle('porto-tab-active', panel.getAttribute('data-tab') === slug);
        });
    }

    function hasTab(slug) {
        return links.some(function (link) {
            return link.getAttribute('data-porto-tab') === slug;
        });
    }

    if (links.length && panels.length) {
        links.forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var slug = link.getAttribute('data-porto-tab');
                activate(slug);
                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(null, '', '#porto-tab-' + slug);
                } else {
                    window.location.hash = 'porto-tab-' + slug;
                }
            });
        });

        // Restore the last tab from the URL hash (e.g. after "Save" reloads the page).
        var fromHash = window.location.hash.replace(/^#porto-tab-/, '');
        if (fromHash && hasTab(fromHash)) {
            activate(fromHash);
        }
    }

    // Native WP colour pickers.
    var $ = window.jQuery;
    var hasPicker = $ && $.fn && typeof $.fn.wpColorPicker === 'function';
    if (hasPicker) {
        $('.porto-color-field').wpColorPicker();
    }

    function setColor(id, value) {
        if (!value) { return; }
        var input = document.getElementById(id);
        if (!input) { return; }
        if (hasPicker) {
            $(input).wpColorPicker('color', value);
        } else {
            input.value = value;
        }
    }

    // One-click colour-scheme presets fill all three pickers at once.
    Array.prototype.forEach.call(
        document.querySelectorAll('.porto-scheme'),
        function (button) {
            button.addEventListener('click', function () {
                setColor('porto-color-accent', button.getAttribute('data-accent'));
                setColor('porto-color-btn-bg', button.getAttribute('data-btn-bg'));
                setColor('porto-color-btn-text', button.getAttribute('data-btn-text'));
            });
        }
    );
})();
