/* Panel Update: while a job (update, rollback, Check now) runs, draw its steps again every 2
   seconds; when the panel does not answer for a moment (PHP and Apache reload during an update),
   say so and keep trying. When it is done, load the page again (the panel may be new by then).
   A console command is copied when clicked. */
(function () {
    'use strict';
    var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };
    var copy = function (root) {
        each(root.querySelectorAll('.pu-cmd[data-copy]'), function (c) {
            if (c.getAttribute('data-bound')) { return; }
            c.setAttribute('data-bound', '1');
            c.setAttribute('title', (pu && pu.getAttribute('data-copy-label')) || 'Copy');
            c.addEventListener('click', function () {
                var t = c.getAttribute('data-copy');
                var done = function () { c.classList.add('copied'); setTimeout(function () { c.classList.remove('copied'); }, 1200); };
                if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done, function () {}); return; }
                var r = document.createRange(); r.selectNodeContents(c); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
                try { document.execCommand('copy'); done(); } catch (e) { /* selected: the admin copies it */ }
            });
        });
    };
    var pu = document.getElementById('pu');
    if (!pu) { return; }
    copy(pu);
    if (pu.getAttribute('data-watch') !== '1') { return; }
    var run = document.getElementById('pu-run'), url = pu.getAttribute('data-status'), lost = null, wasLive = run && run.getAttribute('data-live') === '1';
    var idle = 0, firstChecked = parseInt(pu.getAttribute('data-checked'), 10) || 0;
    var again = function (ms) { setTimeout(poll, ms); };
    var reload = function () { window.location.href = './?module=panel_update'; };
    var poll = function () {
        var x = new XMLHttpRequest();
        x.open('GET', url + '?t=' + Date.now());
        x.timeout = 8000;
        x.onload = function () {
            var d = null;
            try { d = JSON.parse(x.responseText); } catch (e) { d = null; }
            if (x.status !== 200 || !d) { return trouble(); }
            if (lost) { lost.parentNode.removeChild(lost); lost = null; }
            if (run && typeof d.run === 'string') { run.innerHTML = d.run; copy(run); }
            if (wasLive && !d.live) { return setTimeout(reload, 1500); }
            if (!d.live && d.checked !== firstChecked) { return reload(); }
            if (!d.live && ++idle > 30) { return reload(); } // a Check now that never answered: show the page as it is
            wasLive = wasLive || d.live;
            again(2000);
        };
        var trouble = function () {
            if (!lost && run) {
                lost = document.createElement('div');
                lost.className = 'pu-lost';
                lost.setAttribute('role', 'status');
                lost.textContent = pu.getAttribute('data-lost');
                run.insertBefore(lost, run.firstChild);
            }
            again(3000);
        };
        x.onerror = trouble;
        x.ontimeout = trouble;
        x.send();
    };
    again(1500);
})();
