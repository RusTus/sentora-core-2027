/* DNS Manager: record tabs, add / delete / undo, unsaved-change tracking and hints.
   No outside libraries. The field names are the ones the controller's SaveDNS() reads:
   ttl[id], target[id], priority[id] ..., delete[id], and <name>[new_n] for new records. */
(function () {
    'use strict';
    function ready(f) { if (document.readyState !== 'loading') { f(); } else { document.addEventListener('DOMContentLoaded', f); } }
    ready(function () {
        var root = document.getElementById('dn');
        if (!root) { return; }
        var each = function (sel, f, from) { Array.prototype.forEach.call((from || root).querySelectorAll(sel), f); };

        // domain list: filter
        var filter = root.querySelector('.dn-filter');
        if (filter) {
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                each('.dn-domains tbody tr', function (tr) { tr.hidden = q !== '' && tr.getAttribute('data-name').toLowerCase().indexOf(q) < 0; });
            });
        }
        // the panel's "working" overlay on the list's buttons
        each('form.dn-inline, form.dn-foot', function (f) {
            f.addEventListener('submit', function () { if (window.Sentora && Sentora.loader) { Sentora.loader.showLoader(); } });
        });

        var form = root.querySelector('form.dn-form');
        if (!form) { return; }
        var domain = root.getAttribute('data-domain');
        var counter = form.querySelector('.dn-count');
        var dirty = false;

        // tabs (the open one is remembered for this domain, so it stays open after saving)
        var key = 'dn-tab:' + domain;
        function tab(name) {
            var found = false;
            each('.dn-tab', function (b) { var on = b.getAttribute('data-tab') === name; found = found || on; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
            if (!found) { return; }
            each('.dn-pane', function (p) { p.classList.toggle('on', p.getAttribute('data-pane') === name); });
            try { sessionStorage.setItem(key, name); } catch (e) { /* private window */ }
        }
        each('.dn-tab', function (b) { b.addEventListener('click', function () { tab(b.getAttribute('data-tab')); }); });
        try { var last = sessionStorage.getItem(key); if (last) { tab(last); } } catch (e) { /* private window */ }

        function original(input) {
            var o = form.querySelector('input[type="hidden"][name="original_' + input.name + '"]');
            return o ? o.value : null;
        }
        function counts() {
            each('.dn-pane', function (p) {
                var n = p.querySelectorAll('tr.dn-row:not(.deleted)').length, b = root.querySelector('.dn-tab[data-tab="' + p.getAttribute('data-pane') + '"] .dn-badge');
                if (b) { b.textContent = n; }
                p.querySelector('tr.dn-empty').hidden = p.querySelectorAll('tr.dn-row').length > 0;
            });
        }
        function refresh() {
            var changed = form.querySelectorAll('tr.dn-new, tr.changed, tr.deleted').length > 0;
            dirty = changed;
            form.querySelector('.dn-save').disabled = !changed;
            form.querySelector('.dn-undo-all').disabled = !changed;
            form.querySelector('.dn-changes').hidden = !changed;
            each('.dn-tab', function (b) {
                var p = root.querySelector('.dn-pane[data-pane="' + b.getAttribute('data-tab') + '"]');
                b.classList.toggle('changed', p.querySelectorAll('tr.dn-new, tr.changed, tr.deleted').length > 0);
            });
            counts();
        }
        function rowChanged(tr) {
            if (tr.classList.contains('dn-new')) { return; }
            var changed = false;
            each('input.form-control', function (i) { var o = original(i); if (o !== null && i.value !== o) { changed = true; } }, tr);
            tr.classList.toggle('changed', changed);
        }
        function hints(tr) {
            var host = tr.querySelector('input[name^="hostName"]');
            if (!host) { return; }
            var type = tr.querySelector('input[name^="type"]').value, v = host.value.trim().toLowerCase(), warn = tr.querySelector('.dn-warn'), msg = '';
            tr.querySelector('.dn-full').textContent = (v === '' || v === '@') ? domain : v + '.' + domain;
            if (v !== '' && v !== '@' && (v === domain || v.slice(-domain.length - 1) === '.' + domain)) {
                msg = root.getAttribute('data-dup-warn') + ' ' + v + '.' + domain;
            } else if (type === 'MX' && v !== '' && v !== '@') {
                msg = root.getAttribute('data-mx-warn');
            } else if (type === 'CNAME' && (v === '' || v === '@')) {
                msg = root.getAttribute('data-cname-warn');
            }
            warn.textContent = msg;
            warn.hidden = msg === '';
        }

        // edits
        form.addEventListener('input', function (e) {
            var tr = e.target.closest('tr.dn-row');
            if (!tr) { return; }
            rowChanged(tr);
            if (tr.classList.contains('dn-new')) { hints(tr); }
            refresh();
        });
        form.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn || !form.contains(btn)) { return; }
            var tr = btn.closest('tr.dn-row'), pane = btn.closest('.dn-pane');
            if (btn.classList.contains('dn-delete')) {
                tr.classList.add('deleted');
                tr.querySelector('.dn-del').value = 'true';
                each('input.form-control', function (i) { i.disabled = true; }, tr);
            } else if (btn.classList.contains('dn-undo')) {
                tr.classList.remove('deleted', 'changed');
                tr.querySelector('.dn-del').value = 'false';
                each('input.form-control', function (i) { i.disabled = false; var o = original(i); if (o !== null) { i.value = o; } }, tr);
            } else if (btn.classList.contains('dn-remove')) {
                tr.parentNode.removeChild(tr);
            } else if (btn.classList.contains('dn-add')) {
                var n = parseInt(counter.value, 10) + 1;
                counter.value = n;
                var row = pane.querySelector('template.dn-proto').content.firstElementChild.cloneNode(true);
                each('input', function (i) { i.name = i.name.replace(/^proto_(\w+)$/, function (m, f) { return f + '[new_' + n + ']'; }); }, row);
                pane.querySelector('tbody').appendChild(row);
                hints(row);
                row.querySelector('input.form-control').focus();
            } else {
                return;
            }
            refresh();
        });
        form.querySelector('.dn-undo-all').addEventListener('click', function () {
            each('tr.dn-new', function (tr) { tr.parentNode.removeChild(tr); }, form);
            each('tr.deleted, tr.changed', function (tr) { tr.querySelector('.dn-undo').click(); }, form);
            refresh();
        });

        // saving: new rows left empty are dropped; disabled (deleted) fields are sent again
        form.addEventListener('submit', function () {
            each('tr.dn-new', function (tr) {
                var t = tr.querySelector('input[name^="target"]'), h = tr.querySelector('input[name^="hostName"]');
                if (t.value.trim() === '' && (!h || h.value.trim() === '')) { tr.parentNode.removeChild(tr); }
            }, form);
            each('input:disabled', function (i) { i.disabled = false; }, form);
            dirty = false;
            if (window.Sentora && Sentora.loader) { Sentora.loader.showLoader(); }
        });
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) { return; }
            e.preventDefault();
            e.returnValue = root.getAttribute('data-leave');
            return e.returnValue;
        });
        refresh();
    });
})();
