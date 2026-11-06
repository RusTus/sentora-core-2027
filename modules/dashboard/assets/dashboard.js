/* Dashboard: widgets stack like bricks (each one goes under the shortest column, so a
   tall widget leaves no gap beside it) and can be dragged into a new order by their
   title bar, with a mouse or a finger. The order is saved when a widget is dropped;
   the up / down buttons stay for keyboards. No outside libraries. */
(function () {
    'use strict';
    var grid = document.querySelector('.dw-grid');
    if (!grid) { return; }
    var ROW = 8;   // grid-auto-rows in dashboard.css

    // --- stacking: each card spans as many 8px rows as it is tall ---------------------
    function gap() { return parseFloat(getComputedStyle(grid).columnGap) || 14; }
    function fit(card) {
        var h = card.firstElementChild ? card.getBoundingClientRect().height : 0;
        card.style.gridRowEnd = 'span ' + Math.max(1, Math.ceil((h + gap()) / ROW));
    }
    function layout() { Array.prototype.forEach.call(grid.querySelectorAll('.dw-card'), fit); }
    grid.classList.add('dw-masonry');
    layout();
    if (window.ResizeObserver) {
        var ro = new ResizeObserver(function (entries) { entries.forEach(function (e) { fit(e.target); }); });
        Array.prototype.forEach.call(grid.querySelectorAll('.dw-card'), function (c) { ro.observe(c); });
    } else {
        window.addEventListener('resize', layout);
    }
    window.addEventListener('load', layout);   // images and fonts can change heights

    // --- dragging -------------------------------------------------------------------
    var dragging = null, startOrder = '', moved = false, startX = 0, startY = 0;
    function order() { return Array.prototype.map.call(grid.querySelectorAll('.dw-card'), function (c) { return c.getAttribute('data-id'); }).join(','); }
    function toast(text, bad) {
        var t = document.querySelector('.dw-toast');
        if (!t) { t = document.createElement('div'); t.className = 'dw-toast'; document.body.appendChild(t); }
        t.textContent = text;
        t.classList.toggle('bad', !!bad);
        t.classList.add('on');
        clearTimeout(t._h);
        t._h = setTimeout(function () { t.classList.remove('on'); }, bad ? 6000 : 1600);
    }
    function save() {
        var body = new URLSearchParams();
        body.set('inOrder', order());
        body.set('csfr_token', grid.getAttribute('data-token'));
        fetch(grid.getAttribute('data-save'), { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body,
            headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { toast(j.ok ? grid.getAttribute('data-saved') : grid.getAttribute('data-failed'), !j.ok); })
            .catch(function () { toast(grid.getAttribute('data-failed'), true); });
    }
    // the up / down buttons only make sense in the order of the page: refresh which show
    function tools() {
        var cards = grid.querySelectorAll('.dw-card'), n = cards.length;
        Array.prototype.forEach.call(cards, function (c, i) {
            var up = c.querySelector('input[name="inDir"][value="up"]'), down = c.querySelector('input[name="inDir"][value="down"]');
            if (up) { up.form.hidden = (i === 0); }
            if (down) { down.form.hidden = (i === n - 1); }
        });
    }
    grid.addEventListener('pointerdown', function (e) {
        var handle = e.target.closest('.dw-handle');
        if (!handle || e.target.closest('.dw-tools') || e.button > 0) { return; }
        dragging = handle.closest('.dw-card');
        startOrder = order(); moved = false; startX = e.clientX; startY = e.clientY;
        handle.setPointerCapture(e.pointerId);
        e.preventDefault();
    });
    grid.addEventListener('pointermove', function (e) {
        if (!dragging) { return; }
        if (!moved) {
            if (Math.abs(e.clientX - startX) + Math.abs(e.clientY - startY) < 6) { return; }   // a click, not a drag
            moved = true;
            dragging.classList.add('dw-dragging');
            document.body.classList.add('dw-drag-on');
        }
        // the card under the pointer (ignoring the one being dragged)
        var over = null;
        Array.prototype.forEach.call(grid.querySelectorAll('.dw-card'), function (c) {
            if (c === dragging) { return; }
            var r = c.getBoundingClientRect();
            if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) { over = c; }
        });
        if (over) {
            var r = over.getBoundingClientRect();
            var before = (e.clientY - r.top) < r.height / 2;
            if (before && over.previousElementSibling !== dragging) { grid.insertBefore(dragging, over); layout(); }
            else if (!before && over.nextElementSibling !== dragging) { grid.insertBefore(dragging, over.nextElementSibling); layout(); }
        }
        // keep the page scrolling while dragging near its top or bottom edge
        if (e.clientY < 60) { window.scrollBy(0, -12); } else if (e.clientY > window.innerHeight - 60) { window.scrollBy(0, 12); }
    });
    function end() {
        if (!dragging) { return; }
        dragging.classList.remove('dw-dragging');
        document.body.classList.remove('dw-drag-on');
        dragging = null;
        if (moved && order() !== startOrder) { tools(); save(); }
    }
    grid.addEventListener('pointerup', end);
    grid.addEventListener('pointercancel', end);
})();
