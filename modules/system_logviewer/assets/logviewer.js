/* System Log Viewer: polls read.php and appends new text (as text, never HTML). */
(function () {
    var body = document.body, id = body.getAttribute('data-id');
    if (body.getAttribute('data-readable') !== '1') { return; }
    var out = document.getElementById('log'), sizeEl = document.getElementById('size');
    var follow = document.getElementById('follow'), pause = document.getElementById('pause');
    var offset = -1, maxChars = 2000000, busy = false;

    function human(n) {
        var u = ['bytes', 'KB', 'MB', 'GB'], i = 0;
        while (n >= 1024 && i < 3) { n /= 1024; i++; }
        return (i ? n.toFixed(1) : n) + ' ' + u[i];
    }
    function add(text) {
        if (!text) { return; }
        out.appendChild(document.createTextNode(text));
        if (out.textContent.length > maxChars) {
            out.textContent = out.textContent.slice(-maxChars / 2);
        }
        if (follow.checked) { out.scrollTop = out.scrollHeight; }
    }
    function poll() {
        if (busy || pause.checked) { return; }
        busy = true;
        fetch('read.php?id=' + encodeURIComponent(id) + '&offset=' + offset, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.error) { add('\n[' + j.error + ']\n'); return; }
                if (j.reset) { add('\n--- log was rotated or truncated, showing the end ---\n'); }
                add(j.data);
                offset = j.next;
                sizeEl.textContent = human(j.size);
                if (j.more) { busy = false; poll(); }
            })
            .catch(function () { /* try again next time */ })
            .then(function () { busy = false; });
    }
    poll();
    setInterval(poll, 2000);
})();
