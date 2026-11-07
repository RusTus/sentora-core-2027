/* Client Notice Manager: confirm Remove; in the form, the live preview, the formatting buttons,
   the audience panes with the client search, and how many accounts the notice reaches. */
(function () {
    'use strict';
    var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

    each(document.querySelectorAll('.cn-remove[data-confirm]'), function (b) {
        b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); } });
    });

    var form = document.getElementById('cn-form');
    if (!form) { return; }
    var body = document.getElementById('inBody'), title = document.getElementById('inTitle'), pv = document.getElementById('cn-preview');
    var count = document.getElementById('cn-count'), max = parseInt(count.getAttribute('data-max'), 10) || 2000;

    var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); };
    // the same rules as ui_tpl_notice::Format()
    var format = function (t) {
        var keep = [], hold = function (h) { keep.push(h); return '\u0001' + (keep.length - 1) + '\u0002'; };
        var a = function (url, text) { return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + text + '</a>'; };
        var s = esc(t.replace(/\r\n?/g, '\n').trim());
        s = s.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/gi, function (m, text, url) { return hold(a(url, text)); });
        s = s.replace(/\[([^\]\n]+)\]\((\.\/\?module=[a-z0-9_]+(?:&amp;[a-z0-9_]+=[A-Za-z0-9_]*)*)\)/g, function (m, text, url) { return hold('<a href="' + url + '">' + text + '</a>'); });
        s = s.replace(/\bhttps?:\/\/[^\s<\u0001]+/gi, function (url) {
            var tail = '', m;
            while ((m = url.match(/(&quot;|&#039;|&gt;|[.,;:!?)])$/))) { tail = m[1] + tail; url = url.slice(0, -m[1].length); }
            return hold(a(url, url)) + tail;
        });
        s = s.replace(/\*\*(\S(?:.*?\S)?)\*\*/g, '<b>$1</b>');
        var out = '', list = '', prev = false;
        s.split('\n').forEach(function (line) {
            var m = line.match(/^\s*- (.*)$/);
            if (m) { list += '<li>' + m[1] + '</li>'; return; }
            if (list) { out += '<ul>' + list + '</ul>'; list = ''; prev = false; }
            out += (prev ? '<br>' : '') + line; prev = true;
        });
        if (list) { out += '<ul>' + list + '</ul>'; }
        return out.replace(/\u0001(\d+)\u0002/g, function (m, i) { return keep[+i]; });
    };

    var checked = function (name) { var c = form.querySelector('input[name="' + name + '"]:checked'); return c ? c.value : ''; };
    var draw = function () {
        var type = checked('inType') || 'info', scope = checked('inScope');
        var text = body.value.trim() === '' ? pv.getAttribute('data-empty') : body.value;
        var head = title.value.trim() !== '' ? title.value.trim() : pv.getAttribute('data-' + type);
        pv.innerHTML = '<div class="sn-notice sn-notice-' + type + '" role="status">' + pv.getAttribute('data-ico-' + type)
            + '<div class="sn-notice-main"><div class="sn-notice-head"><b>' + esc(head) + '</b><small>' + esc(pv.getAttribute(scope === 'user' ? 'data-personal' : scope === 'everyone' ? 'data-everyone' : 'data-host')) + '</small></div>'
            + '<div class="sn-notice-body">' + format(text) + '</div></div>'
            + (document.getElementById('inClosable').checked ? '<button type="button" class="sn-notice-close" tabindex="-1" aria-hidden="true">&times;</button>' : '') + '</div>';
        var n = Array.from(body.value).length;
        count.textContent = n + ' / ' + max;
        count.classList.toggle('near', n > max * 0.9);
    };

    // formatting buttons work on the selection
    var wrap = function (before, after, fallback) {
        var s = body.selectionStart, e = body.selectionEnd, sel = body.value.slice(s, e) || fallback;
        body.setRangeText(before + sel + after, s, e, 'end');
        body.setSelectionRange(s + before.length, s + before.length + sel.length);
        body.focus(); draw();
    };
    var linkrow = document.getElementById('cn-linkrow'), linkurl = document.getElementById('cn-linkurl'), range = [0, 0];
    each(form.querySelectorAll('.cn-tools [data-do]'), function (b) {
        b.addEventListener('click', function () {
            var what = b.getAttribute('data-do');
            if (what === 'bold') { wrap('**', '**', 'text'); }
            if (what === 'link') { range = [body.selectionStart, body.selectionEnd]; linkrow.hidden = false; linkurl.value = ''; linkurl.focus(); }
            if (what === 'list') {
                var s = body.value.lastIndexOf('\n', body.selectionStart - 1) + 1, e = body.value.indexOf('\n', body.selectionEnd);
                if (e < 0) { e = body.value.length; }
                var lines = body.value.slice(s, e).split('\n'), on = lines.every(function (l) { return /^- /.test(l); });
                body.setRangeText(lines.map(function (l) { return on ? l.replace(/^- /, '') : (l === '' ? '- ' : '- ' + l); }).join('\n'), s, e, 'select');
                body.focus(); draw();
            }
        });
    });
    var addLink = function () {
        var url = linkurl.value.trim();
        if (!/^https?:\/\/\S+$/i.test(url)) { linkurl.focus(); return; }
        var sel = body.value.slice(range[0], range[1]).replace(/[\[\]\n]/g, ' ').trim();
        body.setRangeText(sel ? '[' + sel + '](' + url + ')' : url, range[0], range[1], 'end');
        linkrow.hidden = true; body.focus(); draw();
    };
    document.getElementById('cn-linkadd').addEventListener('click', addLink);
    document.getElementById('cn-linkno').addEventListener('click', function () { linkrow.hidden = true; body.focus(); });
    linkurl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); addLink(); }
        if (e.key === 'Escape') { linkrow.hidden = true; body.focus(); }
    });

    // audience: the pane of the chosen kind, the client search, and the reach
    var scopes = form.querySelector('.cn-scopes'), reach = document.getElementById('cn-reach');
    var pick = form.querySelector('.cn-pick'), find = form.querySelector('.cn-find'), nomatch = form.querySelector('.cn-nomatch');
    var sum = function (name) { var n = 0; each(form.querySelectorAll('input[name="' + name + '[]"]:checked'), function (c) { n += parseInt(c.getAttribute('data-count'), 10) || 0; }); return n; };
    var audience = function () {
        var scope = checked('inScope');
        each(form.querySelectorAll('.cn-pane'), function (p) { p.hidden = p.getAttribute('data-for') !== scope; });
        if (pick) { pick.disabled = scope !== 'user'; }
        var n = scope === 'everyone' ? +scopes.getAttribute('data-everyone') : scope === 'clients' ? +scopes.getAttribute('data-clients')
            : scope === 'packages' ? sum('inPackages') : scope === 'groups' ? sum('inGroups') : (pick && pick.value ? 1 : 0);
        reach.textContent = n === 1 ? reach.getAttribute('data-one') : reach.getAttribute('data-many').replace('%d', n);
        reach.classList.toggle('zero', n === 0);
        draw();
    };
    each(form.querySelectorAll('input[name="inScope"], input[name="inPackages[]"], input[name="inGroups[]"]'), function (i) { i.addEventListener('change', audience); });
    if (pick) {
        pick.addEventListener('change', audience);
        find.addEventListener('input', function () {
            var q = find.value.trim().toLowerCase(), shown = 0, first = null;
            each(pick.options, function (o) {
                var ok = q === '' || o.getAttribute('data-s').indexOf(q) >= 0;
                o.hidden = !ok; if (ok) { shown++; if (!first) { first = o; } }
            });
            nomatch.hidden = shown > 0;
            if (pick.selectedOptions.length && pick.selectedOptions[0].hidden && first) { first.selected = true; audience(); }
        });
        find.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown') { e.preventDefault(); pick.focus(); } if (e.key === 'Enter') { e.preventDefault(); } });
        if (pick.selectedOptions.length) { pick.selectedOptions[0].scrollIntoView({ block: 'nearest' }); }
    }

    each(form.querySelectorAll('input[name="inType"], #inClosable'), function (i) { i.addEventListener('change', draw); });
    body.addEventListener('input', draw);
    title.addEventListener('input', draw);
    audience();
})();
