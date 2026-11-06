/* System Log Viewer: follows one log, asking read.php for new text every two seconds.
   Lines are added as text (never HTML), coloured by what they report, and can be
   filtered. Used by the module page (a list of logs beside the viewer) and by
   view.php (one log in its own window). */
(function () {
    'use strict';
    var root = document.getElementById('lv');
    if (!root) { return; }
    var $ = function (sel) { return root.querySelector(sel); };
    var out = $('.lv-out'), count = $('.lv-count'), follow = $('.lv-follow'), wrap = $('.lv-wrap');
    var pauseBtn = $('.lv-pause'), filterInput = $('.lv-filter-input'), live = $('.lv-live');
    var READ = root.getAttribute('data-read'), VIEW = root.getAttribute('data-view');
    var MAX_LINES = 20000;
    var current = null, offset = -1, partial = '', busy = false, paused = false, gen = 0, filter = '', total = 0;

    function human(n) {
        var u = ['bytes', 'KB', 'MB', 'GB'], i = 0;
        while (n >= 1024 && i < 3) { n /= 1024; i++; }
        return (i ? n.toFixed(1) : n) + ' ' + u[i];
    }
    function ago(t) {
        var s = Math.max(0, Math.floor(Date.now() / 1000 - t));
        var v = s < 60 ? s + 's' : s < 3600 ? Math.floor(s / 60) + 'm' : s < 86400 ? Math.floor(s / 3600) + 'h' : Math.floor(s / 86400) + 'd';
        return v + ' ' + root.getAttribute('data-ago');
    }
    // what a line reports: errors, warnings, or an access log's 4xx / 5xx status
    var BAD = /\b(emerg|alert|crit(ical)?|fatal|panic|error|err|fail(ed|ure)?|denied|refused|segfault)\b/i;
    var WARN = /\b(warn(ing)?|deprecated|timeout|timed out|reject(ed)?)\b/i;
    var HTTP = /" ([1-5])\d\d (\d+|-)/;
    function level(line) {
        var m = HTTP.exec(line);
        if (m) { return m[1] === '5' ? 'bad' : m[1] === '4' ? 'warn' : ''; }
        return BAD.test(line) ? 'bad' : WARN.test(line) ? 'warn' : '';
    }
    // the theme is dark when the page background is (the new window opens in the same)
    function isDark() {
        var m = /rgba?\((\d+), *(\d+), *(\d+)/.exec(getComputedStyle(document.body).backgroundColor);
        return !!m && (0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3]) < 128;
    }
    function matches(line) { return filter === '' || line.toLowerCase().indexOf(filter) >= 0; }
    function updateCount() {
        var shown = filter === '' ? total : out.querySelectorAll('.lv-line:not([hidden])').length;
        count.textContent = total + ' ' + root.getAttribute('data-lines') + (filter === '' ? '' : ' · ' + shown + ' ' + root.getAttribute('data-shown'));
    }
    function addLines(lines) {
        if (!lines.length) { return; }
        var atEnd = out.scrollHeight - out.scrollTop - out.clientHeight < 40;
        var frag = document.createDocumentFragment();
        lines.forEach(function (line) {
            var d = document.createElement('div');
            d.className = 'lv-line ' + level(line);
            d.textContent = line === '' ? ' ' : line;
            if (!matches(line)) { d.hidden = true; }
            frag.appendChild(d);
        });
        out.appendChild(frag);
        total += lines.length;
        while (total > MAX_LINES && out.firstChild) { out.removeChild(out.firstChild); total--; }
        if (follow.checked && (atEnd || lines.length)) { out.scrollTop = out.scrollHeight; }
        updateCount();
    }
    function addText(text) {
        if (!text) { return; }
        var parts = (partial + text).split(/\r?\n/);
        partial = parts.pop();
        addLines(parts);
    }
    function notice(text, cls) {
        var d = document.createElement('div');
        d.className = 'lv-notice ' + (cls || '');
        d.textContent = text;
        out.appendChild(d);
    }
    function poll() {
        if (!current || busy || paused || current.readable !== '1') { return; }
        busy = true;
        var mine = gen;
        fetch(READ + '?id=' + encodeURIComponent(current.id) + '&offset=' + offset, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (mine !== gen) { return; }   // another log was opened meanwhile
                live.classList.remove('err');
                if (j.error) { notice(j.error, 'bad'); return; }
                if (j.reset) { partial = ''; notice(root.getAttribute('data-rotated')); }
                addText(j.data);
                offset = j.next;
                var meta = $('.lv-meta');
                meta.textContent = (current.group ? current.group + ' · ' : '') + human(j.size);
                if (j.more) { busy = false; poll(); }
            })
            .catch(function () { live.classList.add('err'); })
            .then(function () { busy = false; });
    }
    function open(item) {
        gen++;
        current = { id: item.getAttribute('data-id'), path: item.getAttribute('data-path'), group: item.getAttribute('data-group') || '', readable: item.getAttribute('data-readable') };
        offset = -1; partial = ''; total = 0; busy = false;
        out.textContent = '';
        $('.lv-path').textContent = current.path;
        $('.lv-meta').textContent = current.group;
        Array.prototype.forEach.call(root.querySelectorAll('.lv-item'), function (b) { b.classList.toggle('on', b === item); });
        var pop = $('.lv-popout');
        if (pop && VIEW) {
            pop.href = VIEW + '?id=' + encodeURIComponent(current.id) + '&theme=' + (isDark() ? 'dark' : 'light');
            pop.hidden = false;
        }
        if (current.readable !== '1') {
            notice(root.getAttribute('data-locked') + ' sudo tail -f ' + current.path, 'locked');
            count.textContent = '';
            live.classList.add('off');
            return;
        }
        live.classList.toggle('off', paused);
        try { sessionStorage.setItem('lv-log', current.id); } catch (e) { /* private window */ }
        poll();
    }

    // controls
    pauseBtn.addEventListener('click', function () {
        paused = !paused;
        pauseBtn.textContent = paused ? pauseBtn.getAttribute('data-resume') || 'Resume' : pauseBtn.getAttribute('data-pause') || 'Pause';
        live.classList.toggle('off', paused);
        if (!paused) { poll(); }
    });
    pauseBtn.setAttribute('data-pause', pauseBtn.textContent);
    $('.lv-clear').addEventListener('click', function () { out.textContent = ''; total = 0; updateCount(); });
    wrap.addEventListener('change', function () { out.classList.toggle('wrap', wrap.checked); });
    follow.addEventListener('change', function () { if (follow.checked) { out.scrollTop = out.scrollHeight; } });
    filterInput.addEventListener('input', function () {
        filter = filterInput.value.trim().toLowerCase();
        Array.prototype.forEach.call(out.querySelectorAll('.lv-line'), function (d) { d.hidden = !matches(d.textContent); });
        out.classList.toggle('filtered', filter !== '');
        updateCount();
        if (follow.checked) { out.scrollTop = out.scrollHeight; }
    });
    // scrolling up stops following; back at the end follows again
    out.addEventListener('scroll', function () {
        var atEnd = out.scrollHeight - out.scrollTop - out.clientHeight < 40;
        if (!atEnd && follow.checked && document.activeElement === out) { follow.checked = false; }
    });

    // the list (module page) or the one log (view.php)
    var items = root.querySelectorAll('.lv-item');
    Array.prototype.forEach.call(items, function (b) { b.addEventListener('click', function () { open(b); }); });
    Array.prototype.forEach.call(root.querySelectorAll('.lv-ago'), function (s) { s.textContent = ago(+s.getAttribute('data-mtime')); });
    var find = $('.lv-find-input');
    if (find) {
        find.addEventListener('input', function () {
            var q = find.value.trim().toLowerCase();
            Array.prototype.forEach.call(items, function (b) { b.hidden = q !== '' && b.getAttribute('data-s').indexOf(q) < 0; });
            Array.prototype.forEach.call(root.querySelectorAll('.lv-group'), function (g) { g.hidden = !g.querySelector('.lv-item:not([hidden])'); });
        });
    }
    var single = root.getAttribute('data-id');
    if (single) {
        open(root);
    } else {
        var last = null;
        try { last = sessionStorage.getItem('lv-log'); } catch (e) { /* private window */ }
        var first = last && /^[0-9a-f]{16}$/.test(last) ? root.querySelector('.lv-item[data-id="' + last + '"]') : null;
        if (first) { open(first); first.scrollIntoView({ block: 'nearest' }); } else { notice(root.getAttribute('data-pick')); }
    }
    setInterval(poll, 2000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
