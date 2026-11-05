/*
 * Frames that size to their content (Apache Status, GoAccess, Matomo): a frame with
 * class "sn-autoheight" grows and shrinks to the height of the page inside it, so the
 * panel page scrolls as a whole instead of the frame having its own scroll bar.
 * Pages from the panel itself are measured directly; a sandboxed page (GoAccess
 * reports) tells its height with postMessage({snFrameHeight: n}), accepted only from
 * that frame and only as a number. data-sn-free="selectors" lifts min-heights a page
 * sets from the window (AdminLTE) so it can be measured. A page that still sizes itself
 * from the frame (and would grow for ever) is noticed and its frame left as it is.
 */
(function () {
    'use strict';
    var MIN = 500, MAX = 60000;

    function measure(doc) {
        var b = doc.body, e = doc.documentElement;
        if (!b || !e) { return 0; }
        return Math.max(b.scrollHeight, b.offsetHeight, e.scrollHeight, e.offsetHeight);
    }

    function attach(frame) {
        var state = { growth: 0, last: 0, stopped: false, ro: null, mo: null, timer: null };

        function fit() {
            if (state.stopped) { return; }
            var doc;
            try { doc = frame.contentDocument; } catch (err) { return; } // another origin: leave it
            if (!doc || !doc.body) { return; }
            var h = Math.min(MAX, Math.max(MIN, measure(doc)));
            var cur = frame.getBoundingClientRect().height;
            if (Math.abs(h - cur) < 3) { state.growth = 0; return; }
            // the page grew by about as much as the frame did, over and over: it sizes
            // itself from the frame (100vh layouts); stop and keep a sensible height
            if (h > cur && Math.abs((h - cur) - state.last) < 4) {
                state.growth++;
                if (state.growth > 6) {
                    state.stopped = true;
                    frame.style.height = Math.max(MIN, Math.min(cur, window.innerHeight * 1.5)) + 'px';
                    frame.removeAttribute('scrolling');
                    return;
                }
            } else {
                state.growth = 0;
            }
            state.last = h - cur;
            frame.style.height = h + 'px';
        }

        function watch() {
            if (state.ro) { state.ro.disconnect(); }
            if (state.mo) { state.mo.disconnect(); }
            state.growth = 0; state.last = 0; state.stopped = false;
            var doc;
            try { doc = frame.contentDocument; } catch (err) { return; }
            if (!doc || !doc.documentElement) { return; }
            var free = frame.getAttribute('data-sn-free');
            if (free && !doc.getElementById('sn-free-height')) {
                var st = doc.createElement('style');
                st.id = 'sn-free-height';
                st.textContent = free + ' { min-height: 0 !important; height: auto !important; }';
                (doc.head || doc.documentElement).appendChild(st);
            }
            if (window.ResizeObserver) {
                state.ro = new ResizeObserver(fit);
                state.ro.observe(doc.documentElement);
                if (doc.body) { state.ro.observe(doc.body); }
            }
            if (window.MutationObserver) {
                state.mo = new MutationObserver(fit);
                state.mo.observe(doc.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
            }
            fit();
        }

        frame.style.overflow = 'hidden';
        frame.setAttribute('scrolling', 'no');
        frame.addEventListener('load', watch);       // also when a link inside opens another page
        clearInterval(state.timer);
        state.timer = setInterval(fit, 1500);         // pages that change without telling anyone
        watch();
    }

    function start() {
        var frames = document.querySelectorAll('iframe.sn-autoheight');
        for (var i = 0; i < frames.length; i++) { attach(frames[i]); }
        // sandboxed frames tell their height themselves
        window.addEventListener('message', function (ev) {
            var h = ev.data && typeof ev.data === 'object' ? ev.data.snFrameHeight : null;
            if (typeof h !== 'number' || !isFinite(h)) { return; }
            for (var i = 0; i < frames.length; i++) {
                if (frames[i].contentWindow === ev.source) {
                    frames[i].style.height = Math.min(MAX, Math.max(MIN, Math.round(h))) + 'px';
                }
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
