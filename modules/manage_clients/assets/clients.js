/* Manage Clients: the list's search and filter; in the forms, the password field (Show, Make one,
   the rules ticked as you type) and the welcome email part; confirm buttons. */
(function () {
    'use strict';
    var mc = document.getElementById('mc'); if (!mc) { return; }
    var each = function (root, sel, fn) { Array.prototype.forEach.call(root.querySelectorAll(sel), fn); };

    // list: search and All / Active / Switched off
    var rows = mc.querySelectorAll('.mc-row'), find = document.getElementById('mc-find'), filter = 'all';
    var apply = function () {
        var q = find ? find.value.trim().toLowerCase() : '', n = 0;
        each(mc, '.mc-row', function (r) {
            var on = (q === '' || r.getAttribute('data-s').indexOf(q) >= 0) && (filter === 'all' || r.getAttribute('data-state') === filter);
            r.hidden = !on; if (on) { n++; }
        });
        var nm = document.getElementById('mc-nomatch'); if (nm) { nm.hidden = n > 0 || rows.length === 0; }
    };
    if (find) { find.addEventListener('input', apply); }
    each(mc, '.mc-filter', function (b) {
        b.addEventListener('click', function () {
            each(mc, '.mc-filter', function (x) { x.classList.toggle('active', x === b); });
            filter = b.getAttribute('data-f'); apply();
        });
    });

    // password: Show, Make one (the browser's secure random numbers) and the rules
    each(mc, '.mc-pass', function (box) {
        var pw = box.querySelector('input'), show = box.querySelector('.mc-show'), gen = box.querySelector('.mc-gen');
        var rules = box.parentNode.querySelector('.mc-rules');
        var vis = function (on) { pw.type = on ? 'text' : 'password'; show.textContent = show.getAttribute(on ? 'data-hide' : 'data-show'); };
        var tick = function () {
            var v = pw.value, ok = { lower: /[a-z]/.test(v), upper: /[A-Z]/.test(v), digit: /[0-9]/.test(v), len: v.length >= parseInt(rules.getAttribute('data-len'), 10) };
            rules.classList.toggle('idle', v === '');
            each(rules, 'li', function (li) { li.classList.toggle('ok', !!ok[li.getAttribute('data-rule')]); });
            pw.setCustomValidity(v === '' || (ok.lower && ok.upper && ok.digit && ok.len) ? '' : rules.textContent.replace(/\s+/g, ' ').trim());
        };
        show.addEventListener('click', function () { vis(pw.type === 'password'); });
        gen.addEventListener('click', function () {
            var chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', len = Math.max(16, parseInt(gen.getAttribute('data-len'), 10) || 0), out, buf = new Uint32Array(1), lim = Math.floor(4294967296 / chars.length) * chars.length;
            do { out = ''; while (out.length < len) { window.crypto.getRandomValues(buf); if (buf[0] < lim) { out += chars.charAt(buf[0] % chars.length); } } }
            while (!/[a-z]/.test(out) || !/[A-Z]/.test(out) || !/[0-9]/.test(out));
            pw.value = out; vis(true); tick(); pw.select();
        });
        pw.addEventListener('input', tick); tick();
    });

    // welcome email: its subject and text only when it is sent
    var swe = document.getElementById('inSWE'), wel = document.getElementById('mc-welcome');
    if (swe && wel) { var w = function () { wel.hidden = !swe.checked; }; swe.addEventListener('change', w); w(); }

    each(document, '[data-confirm]', function (b) {
        b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); } });
    });
})();
