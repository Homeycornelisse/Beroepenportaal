/**
 * Berichten inbox JS – Beroepen Portaal Core
 * Biedt: automatisch tab openen via URL-param, markeer-gelezen badges bijwerken
 */
(function () {
    'use strict';

    // Open inbox tab automatisch als URL ?bp_tab=inbox heeft
    function openTabFromUrl() {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('bp_tab');
        if (!tab) return;

        // Zoek de bijbehorende tab-button op (portaal.js beheert de tabwisseling)
        var btn = document.querySelector('.bp-tab-button[data-tab="' + tab + '"]');
        if (btn) {
            btn.click();
        }
    }

    // Inbox berichten: klik op "Gelezen" maakt badge weg via AJAX-like navigatie
    // (eenvoudige POST via hidden form – geen echte XHR nodig)
    function initMarkeerGelezen() {
        document.querySelectorAll('[data-markeer-gelezen]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var form = btn.closest('form[data-gelezen-form]');
                if (form) form.submit();
            });
        });
    }

    // Badge in de Inbox-tab bijwerken op basis van .kb-badge-inbox data-attribute
    function updateInboxBadge() {
        var badge = document.querySelector('[data-inbox-badge]');
        if (!badge) return;
        var count = parseInt(badge.getAttribute('data-inbox-badge'), 10) || 0;
        var tabBtn = document.querySelector('.bp-tab-button[data-tab="inbox"]');
        if (!tabBtn) return;

        var existing = tabBtn.querySelector('.bp-inbox-badge');
        if (count > 0) {
            if (!existing) {
                existing = document.createElement('span');
                existing.className = 'bp-inbox-badge';
                existing.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;background:#ef4444;color:#fff;border-radius:999px;font-size:10px;font-weight:800;min-width:16px;height:16px;padding:0 4px;margin-left:4px;vertical-align:middle;';
                tabBtn.appendChild(existing);
            }
            existing.textContent = count;
        } else if (existing) {
            existing.remove();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        openTabFromUrl();
        initMarkeerGelezen();
        updateInboxBadge();
    });
})();
