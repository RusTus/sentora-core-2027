/* FTP Accounts: Copy, the list's search, the password field (Show, Make one, the rules ticked as
   you type), and in the form the folder choices (a select is used only when its choice is ticked). */
(function () {
    'use strict';
    var ft = document.getElementById('ft'); if (!ft) { return; }
    var each = function (root, sel, fn) { Array.prototype.forEach.call(root.querySelectorAll(sel), fn); };
    each(ft, '.ft-copy', function (b) {
        b.addEventListener('click', function () {
            var text = b.parentNode.querySelector('[data-copy]').getAttribute('data-copy');
            var done = function () { var o = b.textContent; b.textContent = b.getAttribute('data-done'); b.classList.add('done'); setTimeout(function () { b.textContent = o; b.classList.remove('done'); }, 1500); };
            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
            var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(ta);
        });
    });
    var rows = ft.querySelectorAll('.ft-row'), find = document.getElementById('ft-find');
    if (find && rows.length > 6) {
        find.hidden = false;
        find.addEventListener('input', function () {
            var q = find.value.trim().toLowerCase(), n = 0;
            each(ft, '.ft-row', function (r) { var on = q === '' || r.getAttribute('data-s').indexOf(q) >= 0; r.hidden = !on; if (on) { n++; } });
            document.getElementById('ft-nomatch').hidden = n > 0;
        });
    }
    each(ft, '.ft-pass', function (box) {
        var pw = box.querySelector('input'), show = box.querySelector('.ft-show'), gen = box.querySelector('.ft-gen'), rules = box.parentNode.querySelector('.ft-rules');
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
    var homes = ft.querySelectorAll('.ft-home');
    var sync = function () { each(ft, '.ft-home', function (h) { var s = h.querySelector('select'); if (s) { s.disabled = !h.querySelector('input').checked; } }); };
    each(ft, '.ft-home input[type=radio]', function (r) { r.addEventListener('change', sync); });
    each(ft, '.ft-home select', function (s) { s.addEventListener('focus', function () { s.parentNode.parentNode.querySelector('input').checked = true; sync(); }); s.addEventListener('mousedown', function () { if (s.disabled) { s.parentNode.parentNode.querySelector('input').checked = true; sync(); } }); });
    if (homes.length) { sync(); }
    var name = document.getElementById('inFTPUsername'), nd = document.getElementById('ft-newdir');
    if (name && nd) { name.addEventListener('input', function () { nd.textContent = name.value.trim() || '…'; }); }
})();
