/*
 * The widget rail: a bar on the right of every panel page with the account's dashboard widgets,
 * small and one above the other (filled from modules/dashboard/code/rail.php).
 *
 * A tab on its edge hides it to the right and brings it back; the choice is remembered in this
 * browser. On a wide screen it sits beside the page (the page narrows for it); on a narrow one it
 * starts hidden and slides over the page. It is filled only while open (after the page has
 * loaded, then every two minutes while the page is in view), and never on the dashboard itself,
 * which shows the same widgets.
 *
 * Written by RusTus, 2026-11-12
 */
(function () {
    'use strict';
    var rail = document.getElementById('sn-rail');
    if (!rail || !window.fetch) {
        return;
    }
    // (the dashboard shows the widgets itself)
    if (document.querySelector('.dw-grid') || document.getElementById('dw')) {
        rail.parentNode.removeChild(rail);
        return;
    }
    var KEY = 'sentora-rail';
    var WIDE = window.matchMedia('(min-width: 1200px)');
    var body = document.body;
    var list = rail.querySelector('.sn-rail-list');
    var tab = document.getElementById('sn-rail-tab');
    var loaded = 0;
    var timer = null;

    function saved() {
        try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
    }
    function save(v) {
        try { window.localStorage.setItem(KEY, v); } catch (e) { /* (private window: not remembered) */ }
    }
    // open by default on a wide screen; on a narrow one only when asked, each time
    function wantOpen() {
        return WIDE.matches ? saved() !== 'closed' : false;
    }

    function fill() {
        if (!body.classList.contains('sn-rail-open') || document.hidden) {
            return;
        }
        rail.setAttribute('aria-busy', 'true');
        fetch(rail.getAttribute('data-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'sentora-rail' } })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.text();
            })
            .then(function (html) {
                list.innerHTML = html;
                loaded = Date.now();
            })
            .catch(function () {
                if (!loaded) {
                    list.innerHTML = '';
                    var p = document.createElement('p');
                    p.className = 'dw-rail-empty';
                    p.textContent = rail.getAttribute('data-failed');
                    list.appendChild(p);
                }
            })
            .then(function () { rail.removeAttribute('aria-busy'); });
    }

    // just below the top bar, however tall it is (it wraps on smaller screens)
    function place() {
        var nb = document.querySelector('.navbar-fixed-top');
        var top = nb && window.getComputedStyle(nb).position === 'fixed' ? Math.round(nb.getBoundingClientRect().bottom) : 0;
        rail.style.top = top + 'px';
    }
    window.addEventListener('resize', place);

    function set(open, remember) {
        place();
        body.classList.toggle('sn-rail-open', open);
        rail.setAttribute('aria-hidden', open ? 'false' : 'true');
        tab.setAttribute('aria-expanded', open ? 'true' : 'false');
        var label = rail.getAttribute(open ? 'data-hide' : 'data-show');
        tab.setAttribute('title', label);
        tab.setAttribute('aria-label', label);
        if (remember && WIDE.matches) {
            save(open ? 'open' : 'closed');
        }
        clearInterval(timer);
        if (open) {
            if (!loaded || Date.now() - loaded > 60000) {
                fill();
            }
            timer = setInterval(fill, 120000);
        }
    }

    tab.addEventListener('click', function () {
        set(!body.classList.contains('sn-rail-open'), true);
    });
    // a narrow screen: Esc or a tap beside the rail closes it
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !WIDE.matches && body.classList.contains('sn-rail-open')) {
            set(false, false);
            tab.focus();
        }
    });
    document.addEventListener('click', function (e) {
        if (!WIDE.matches && body.classList.contains('sn-rail-open') && !rail.contains(e.target) && !tab.contains(e.target)) {
            set(false, false);
        }
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && body.classList.contains('sn-rail-open') && Date.now() - loaded > 120000) {
            fill();
        }
    });
    (WIDE.addEventListener ? WIDE.addEventListener.bind(WIDE, 'change') : WIDE.addListener.bind(WIDE))(function () {
        set(wantOpen(), false);
    });

    rail.hidden = false;
    tab.hidden = false;
    set(wantOpen(), false);
})();
