/* Server Monitor: the Hardening card (score gauge, score over time, by area, what to fix).
   Reads code/hardening.php; no outside libraries; every text from the report is put in as text. */
(function () {
    var root = document.getElementById('sm-hard');
    if (!root) {
        return;
    }
    var NS = 'http://www.w3.org/2000/svg';
    var $ = function (id) { return document.getElementById(id); };
    function T(key) {
        var t = root.getAttribute('data-t-' + key) || key, args = Array.prototype.slice.call(arguments, 1);
        args.forEach(function (a) { t = t.replace(/%[sd]/, String(a)); });
        return t;
    }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function svg(tag, attrs) {
        var e = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        return e;
    }
    function color(score) { return score >= 90 ? '#2db87d' : score >= 75 ? '#1fa2b5' : score >= 60 ? '#f5a623' : '#ff6854'; }
    function grade(score) { return score >= 90 ? 'A' : score >= 80 ? 'B' : score >= 70 ? 'C' : score >= 60 ? 'D' : 'F'; }
    function when(t) { return new Date(t * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
    function area(section) { return String(section).replace(/\s*\(.*\)\s*$/, ''); }

    // the gauge: a 270-degree arc, the score in the middle, its grade below
    function gauge(box, score, counts) {
        box.textContent = '';
        var r = 70, cx = 90, cy = 90, sweep = 270, start = 135;
        var pt = function (deg) { var a = deg * Math.PI / 180; return [cx + r * Math.cos(a), cy + r * Math.sin(a)]; };
        var arc = function (from, to) {
            var a = pt(from), b = pt(to), large = (to - from) > 180 ? 1 : 0;
            return 'M' + a[0].toFixed(2) + ' ' + a[1].toFixed(2) + ' A' + r + ' ' + r + ' 0 ' + large + ' 1 ' + b[0].toFixed(2) + ' ' + b[1].toFixed(2);
        };
        var s = svg('svg', { viewBox: '0 0 180 170', role: 'img', 'aria-label': score + ' / 100' });
        s.appendChild(svg('path', { d: arc(start, start + sweep), fill: 'none', stroke: 'var(--sm-line)', 'stroke-width': 14, 'stroke-linecap': 'round' }));
        if (score > 0) {
            var p = svg('path', { d: arc(start, start + sweep * Math.min(score, 100) / 100), fill: 'none', stroke: color(score), 'stroke-width': 14, 'stroke-linecap': 'round', 'class': 'smh-arc' });
            s.appendChild(p);
        }
        var t1 = svg('text', { x: cx, y: cy + 8, 'text-anchor': 'middle', 'class': 'smh-score' }); t1.textContent = score;
        var t2 = svg('text', { x: cx, y: cy + 30, 'text-anchor': 'middle', 'class': 'smh-of' }); t2.textContent = '/ 100';
        var t3 = svg('text', { x: cx, y: cy + 70, 'text-anchor': 'middle', 'class': 'smh-grade', fill: color(score) }); t3.textContent = grade(score);
        s.appendChild(t1); s.appendChild(t2); s.appendChild(t3);
        box.appendChild(s);
        var c = el('div', 'smh-counts');
        [['ok', counts.ok], ['warn', counts.warn], ['fail', counts.fail]].forEach(function (x) {
            var b = el('span', 'smh-pill smh-' + x[0]); b.appendChild(el('b', '', String(x[1]))); b.appendChild(document.createTextNode(' ' + T(x[0]))); c.appendChild(b);
        });
        box.appendChild(c);
    }

    // the score over time: a line with its area, a dot per check, a tooltip
    function history(box, hist) {
        box.textContent = '';
        if (hist.length < 2) {
            box.appendChild(el('div', 'sm-sub smh-empty', T('histnone')));
            if (hist.length === 0) { return; }
        }
        var W = 420, H = 150, pl = 30, pr = 10, pt = 10, pb = 22, iw = W - pl - pr, ih = H - pt - pb;
        var t0 = hist[0].time, t1 = hist[hist.length - 1].time, span = Math.max(t1 - t0, 1);
        var X = function (t) { return pl + (hist.length < 2 ? iw / 2 : (t - t0) / span * iw); }, Y = function (v) { return pt + ih - v / 100 * ih; };
        var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', 'class': 'smh-svg' });
        var defs = svg('defs'), g = svg('linearGradient', { id: 'smh-fill', x1: 0, y1: 0, x2: 0, y2: 1 });
        var last = hist[hist.length - 1].score;
        g.appendChild(svg('stop', { offset: '0%', 'stop-color': color(last), 'stop-opacity': 0.35 }));
        g.appendChild(svg('stop', { offset: '100%', 'stop-color': color(last), 'stop-opacity': 0.02 }));
        defs.appendChild(g); s.appendChild(defs);
        [0, 50, 75, 90, 100].forEach(function (v) {
            s.appendChild(svg('line', { x1: pl, x2: W - pr, y1: Y(v), y2: Y(v), stroke: 'var(--sm-line)', 'stroke-dasharray': v === 0 || v === 100 ? '' : '3 3' }));
            var lt = svg('text', { x: pl - 6, y: Y(v) + 3, 'text-anchor': 'end', 'class': 'smh-axis' }); lt.textContent = v; s.appendChild(lt);
        });
        var line = hist.map(function (h, i) { return (i ? 'L' : 'M') + X(h.time).toFixed(1) + ' ' + Y(h.score).toFixed(1); }).join(' ');
        if (hist.length > 1) {
            s.appendChild(svg('path', { d: line + ' L' + X(t1).toFixed(1) + ' ' + Y(0) + ' L' + X(t0).toFixed(1) + ' ' + Y(0) + ' Z', fill: 'url(#smh-fill)' }));
            s.appendChild(svg('path', { d: line, fill: 'none', stroke: color(last), 'stroke-width': 2.5, 'stroke-linejoin': 'round' }));
        }
        var tip = el('div', 'sm-tip');
        hist.forEach(function (h) {
            var dot = svg('circle', { cx: X(h.time), cy: Y(h.score), r: 4, fill: color(h.score), stroke: 'var(--sm-card)', 'stroke-width': 1.5, 'class': 'smh-dot' });
            dot.addEventListener('mouseenter', function () {
                tip.textContent = when(h.time) + ' · ' + h.score + ' / 100 · ' + h.ok + ' ' + T('ok') + ', ' + h.warn + ' ' + T('warn') + ', ' + h.fail + ' ' + T('fail');
                tip.style.display = 'block';
                var bw = box.clientWidth;
                tip.style.left = Math.min(Math.max(X(h.time) / W * bw - 80, 0), bw - 200) + 'px';
                tip.style.top = (Y(h.score) / H * box.clientHeight - 34) + 'px';
            });
            dot.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
            s.appendChild(dot);
        });
        var a = svg('text', { x: pl, y: H - 4, 'class': 'smh-axis' }); a.textContent = when(t0); s.appendChild(a);
        if (hist.length > 1) { var b = svg('text', { x: W - pr, y: H - 4, 'text-anchor': 'end', 'class': 'smh-axis' }); b.textContent = when(t1); s.appendChild(b); }
        box.appendChild(s); box.appendChild(tip);
    }

    // by area: a bar per section, its own score, worst first
    function areas(box, checks) {
        box.textContent = '';
        var by = {}, order = [];
        checks.forEach(function (c) {
            var k = area(c.section);
            if (!by[k]) { by[k] = { ok: 0, warn: 0, fail: 0 }; order.push(k); }
            by[k][c.status]++;
        });
        order.map(function (k) {
            var n = by[k], t = n.ok + n.warn + n.fail;
            return { name: k, score: Math.round(100 * (n.ok + n.warn / 2) / t), n: n };
        }).sort(function (a, b) { return a.score - b.score; }).forEach(function (r) {
            var row = el('div', 'smh-area');
            var top = el('div', 'smh-area-top');
            top.appendChild(el('span', '', r.name));
            top.appendChild(el('b', '', r.score + ''));
            row.appendChild(top);
            var bar = el('div', 'sm-bar'), fill = el('b');
            fill.style.width = r.score + '%'; fill.style.background = color(r.score);
            bar.appendChild(fill); row.appendChild(bar);
            row.title = r.n.ok + ' ' + T('ok') + ', ' + r.n.warn + ' ' + T('warn') + ', ' + r.n.fail + ' ' + T('fail');
            box.appendChild(row);
        });
    }

    // what to fix: failures first, then warnings, grouped by area; each line says what and how
    function issues(box, checks) {
        box.textContent = '';
        var bad = checks.filter(function (c) { return c.status !== 'ok'; });
        var fails = bad.filter(function (c) { return c.status === 'fail'; }).length, warns = bad.length - fails;
        var head = el('div', 'smh-issues-head');
        if (!bad.length) {
            head.appendChild(el('span', 'smh-pill smh-ok', T('allgood')));
            box.appendChild(head);
            return;
        }
        if (fails) { head.appendChild(el('span', 'smh-pill smh-fail', T('tofix', fails))); }
        if (warns) { head.appendChild(el('span', 'smh-pill smh-warn', T('towatch', warns))); }
        box.appendChild(head);
        ['fail', 'warn'].forEach(function (st) {
            var groups = {}, order = [];
            bad.filter(function (c) { return c.status === st; }).forEach(function (c) {
                var k = area(c.section);
                if (!groups[k]) { groups[k] = []; order.push(k); }
                groups[k].push(c.text);
            });
            order.forEach(function (k) {
                var g = el('div', 'smh-group smh-group-' + st);
                g.appendChild(el('div', 'smh-group-name', k));
                groups[k].forEach(function (text) {
                    var it = el('div', 'smh-item');
                    it.appendChild(el('span', 'smh-badge smh-' + st, T(st)));
                    // "what: how to fix" or "what (how to fix)": the fix in its own line
                    var m = String(text).match(/^(.*?)\s*\(([^()]*)\)\s*$/) || String(text).match(/^(.*?):\s+([^:]*(?:=|systemctl|setso|sentora-|bash |restart|chmod|chown|DROP |Sencrypt|Admin >)[^:]*)$/);
                    var body = el('div', 'smh-text');
                    body.appendChild(el('div', '', m ? m[1] : text));
                    if (m) { body.appendChild(el('div', 'smh-fix', m[2])); }
                    it.appendChild(body);
                    g.appendChild(it);
                });
                box.appendChild(g);
            });
        });
    }

    function passed(box, checks) {
        box.textContent = '';
        var ok = checks.filter(function (c) { return c.status === 'ok'; });
        if (!ok.length) { return; }
        var btn = el('button', 'smh-toggle', T('passed', ok.length)), list = el('div', 'smh-passed-list');
        list.hidden = true;
        var groups = {}, order = [];
        ok.forEach(function (c) { var k = area(c.section); if (!groups[k]) { groups[k] = []; order.push(k); } groups[k].push(c.text); });
        order.forEach(function (k) {
            var g = el('div', 'smh-group');
            g.appendChild(el('div', 'smh-group-name', k));
            groups[k].forEach(function (text) { var it = el('div', 'smh-item'); it.appendChild(el('span', 'smh-badge smh-ok', T('ok'))); it.appendChild(el('div', 'smh-text', text)); g.appendChild(it); });
            list.appendChild(g);
        });
        btn.type = 'button';
        btn.addEventListener('click', function () { list.hidden = !list.hidden; btn.textContent = list.hidden ? T('passed', ok.length) : T('hide'); });
        box.appendChild(btn); box.appendChild(list);
    }

    var polls = 0;
    function load() {
        fetch(root.getAttribute('data-url'), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            var r = d.report, checking = d.asked > 0 && (!r || r.time < d.asked) && d.now - d.asked < 600;
            $('smh-when').textContent = checking ? T('checking') : (r ? T('checked', when(r.time)) : '');
            if (!r) {
                $('smh-gauge').textContent = ''; $('smh-issues').textContent = '';
                $('smh-issues').appendChild(el('div', 'sm-sub', T('none')));
            } else {
                gauge($('smh-gauge'), r.score, r);
                history($('smh-hist'), d.history || []);
                areas($('smh-areas'), r.checks || []);
                issues($('smh-issues'), r.checks || []);
                passed($('smh-passed'), r.checks || []);
            }
            if (checking && polls++ < 60) { setTimeout(load, 5000); }
        }).catch(function () { /* the rest of Server Monitor keeps working */ });
    }
    load();
})();
