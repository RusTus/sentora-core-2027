/* Module Admin: find a module in the table, mark changes until saved, fade modules
   switched off, and show the chosen package's name. No outside libraries. */
(function () {
    'use strict';
    var root = document.getElementById('mo');
    if (!root) { return; }
    var form = root.querySelector('.mo-form');
    if (form) {
        var find = form.querySelector('.mo-find input'), changed = form.querySelector('.mo-changed'), dirty = false;
        find.addEventListener('input', function () {
            var q = find.value.trim().toLowerCase();
            Array.prototype.forEach.call(form.querySelectorAll('tr.mo-row'), function (r) { r.hidden = q !== '' && r.getAttribute('data-s').indexOf(q) < 0; });
            // a section heading only while it has rows to show
            Array.prototype.forEach.call(form.querySelectorAll('tr.mo-section'), function (h) {
                var n = h.nextElementSibling, any = false;
                while (n && !n.classList.contains('mo-section')) { any = any || !n.hidden; n = n.nextElementSibling; }
                h.hidden = !any;
            });
        });
        form.addEventListener('change', function (e) {
            if (e.target === find) { return; }
            dirty = true; changed.hidden = false;
            var sw = e.target.closest('.mo-switch');
            if (sw) { e.target.closest('tr').classList.toggle('off', !e.target.checked); }
        });
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    }
    var file = root.querySelector('.mo-drop input[type=file]');
    if (file) {
        file.addEventListener('change', function () { if (file.files.length) { root.querySelector('.mo-drop b').textContent = file.files[0].name; } });
    }
})();
