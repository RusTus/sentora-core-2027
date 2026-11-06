/* Backup: confirm deletes; while a backup is being made, count the time and ask for its progress every
   few seconds; when it is ready (or failed) reload the page, which then shows it. */
(function () {
    'use strict';
    // deleting a backup asks first; buttons that start work show the panel's "working" overlay
    Array.prototype.forEach.call(document.querySelectorAll('#bk form'), function (f) {
        f.addEventListener('submit', function (e) {
            var q = f.getAttribute('data-confirm');
            if (q && !window.confirm(q)) { e.preventDefault(); return; }
            if (window.Sentora && Sentora.loader) { Sentora.loader.showLoader(); }
        });
    });
    var box = document.getElementById('bk-progress');
    if (!box) { return; }
    var url = box.getAttribute('data-status');
    var started = parseInt(box.getAttribute('data-started'), 10) * 1000;
    var elapsed = document.getElementById('bk-elapsed');
    function tick() {
        var s = Math.max(0, Math.round((Date.now() - started) / 1000));
        elapsed.textContent = s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's';
    }
    tick();
    setInterval(tick, 1000);
    var delay = 3000;
    function ask() {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (p) {
                if (p.state === 'done' || p.state === 'failed') {
                    window.location.href = './?module=backupmgr&done=1';
                    return;
                }
                delay = Math.min(delay + 1000, 10000);   // ask less often for long backups
                setTimeout(ask, delay);
            })
            .catch(function () { setTimeout(ask, 10000); });
    }
    setTimeout(ask, delay);
})();
