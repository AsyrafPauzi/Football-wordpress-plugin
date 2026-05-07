(function () {
    'use strict';

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return (root || document).querySelectorAll(sel);
    }

    function openModal(modal) {
        if (!modal) return;
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function bindPayTriggers() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.flms-friendly-pay-trigger');
            if (!btn) return;
            e.preventDefault();
            var modal = qs('#flms-friendly-consent-modal');
            if (!modal) return;
            var fid = btn.getAttribute('data-friendly-id') || '';
            var tid = btn.getAttribute('data-team-id') || '';
            var hid = qs('#flms-friendly-consent-fid', modal);
            var htid = qs('#flms-friendly-consent-tid', modal);
            var chk = qs('#flms-friendly-consent-agree', modal);
            if (hid) hid.value = fid;
            if (htid) htid.value = tid;
            if (chk) chk.checked = false;
            var termsScroll = qs('#flms-friendly-terms-scroll', modal);
            if (termsScroll) termsScroll.style.display = 'none';
            openModal(modal);
        });
    }

    function bindModalChrome() {
        var main = qs('#flms-friendly-consent-modal');
        var termsOnly = qs('#flms-friendly-terms-only-modal');
        if (main) {
            qsa('.flms-friendly-modal-overlay, .flms-friendly-modal-cancel, .flms-friendly-modal-close', main).forEach(function (el) {
                el.addEventListener('click', function () {
                    closeModal(main);
                });
            });
        }
        document.addEventListener('click', function (e) {
            if (e.target.closest('.flms-friendly-open-terms')) {
                e.preventDefault();
                if (termsOnly) openModal(termsOnly);
            }
        });
        if (termsOnly) {
            qsa('.flms-friendly-modal-overlay, .flms-friendly-modal-close-terms, .flms-friendly-close-terms-btn', termsOnly).forEach(function (el) {
                el.addEventListener('click', function () {
                    closeModal(termsOnly);
                });
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (main && main.style.display === 'flex') closeModal(main);
                if (termsOnly && termsOnly.style.display === 'flex') closeModal(termsOnly);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindPayTriggers();
            bindModalChrome();
        });
    } else {
        bindPayTriggers();
        bindModalChrome();
    }
})();
