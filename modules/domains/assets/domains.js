/* Domains, Sub Domains and Parked Domains: shared page behaviour. International (xn--) names
   are shown in their own letters, with the xn-- form as a tooltip; a search when there are
   many; the folder choice of the create form. Names are inserted as text, never HTML. */
(function () {
    'use strict';
    var dm = document.getElementById('dm'); if (!dm) { return; }
    var each = function (sel, fn) { Array.prototype.forEach.call(dm.querySelectorAll(sel), fn); };
    if (window.punycode) {
        each('[data-idn]', function (a) {
            var n = a.getAttribute('data-idn');
            if (/(^|\.)xn--/.test(n)) { try { a.textContent = punycode.ToUnicode(n); a.title = n; } catch (e) {} }
        });
    }
    // where the files go: a new folder named after the domain, or one already there
    var name = dm.querySelector('[data-folder-from]'), preview = dm.querySelector('.dm-newdir');
    var sync = function () {
        var existing = dm.querySelector('input[name=inAutoHome][value="2"]');
        var pick = dm.querySelector('.dm-existing');
        if (existing && pick) { pick.hidden = !existing.checked; }
        if (name && preview) {
            var v = name.value.trim().toLowerCase(), sub = dm.querySelector('#inDomain[data-parent]');
            if (sub) { v = v ? v + '.' + sub.value : ''; }
            preview.textContent = 'public_html/' + (v ? v.replace(/\./g, '_') : '…');
        }
    };
    each('input[name=inAutoHome]', function (r) { r.addEventListener('change', sync); });
    if (name) { name.addEventListener('input', sync); }
    var parent = dm.querySelector('#inDomain[data-parent]'); if (parent) { parent.addEventListener('change', sync); }
    sync();
    // with many domains, a search
    var cards = dm.querySelectorAll('.dm-card'), find = dm.querySelector('.dm-find');
    if (find && cards.length > 6) {
        find.hidden = false;
        find.addEventListener('input', function () {
            var q = find.value.trim().toLowerCase(), n = 0;
            Array.prototype.forEach.call(cards, function (c) {
                var link = c.querySelector('.dm-link'), on = q === '' || c.getAttribute('data-s').indexOf(q) >= 0 || link.textContent.toLowerCase().indexOf(q) >= 0;
                c.hidden = !on; if (on) { n++; }
            });
            dm.querySelector('.dm-nomatch').hidden = n > 0;
        });
    }
    each('form', function (f) { f.addEventListener('submit', function () { if (window.Sentora && Sentora.loader) { Sentora.loader.showLoader(); } }); });
})();
