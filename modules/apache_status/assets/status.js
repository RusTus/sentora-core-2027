/* Apache Status: live dashboard (no outside libraries). Polls status.php, turns
   Apache's counters into rates between two reads and draws SVG charts. */
(function () {
    'use strict';
    var root = document.getElementById('as');
    if (!root) { return; }
    var URL = root.getAttribute('data-url');
    var WINDOW = 10 * 60;                         // seconds of history in the charts
    var C = { blue: '#447fb3', red: '#ff6854', green: '#2db87d', amber: '#f5a623', purple: '#8e6bd8', teal: '#1fa2b5', grey: '#9aa5b1' };
    // the scoreboard: one character per worker slot
    var STATES = [
        ['_', 'Waiting for a connection', C.green], ['R', 'Reading the request', C.blue], ['W', 'Sending the reply', C.purple],
        ['K', 'Keep-alive', C.amber], ['C', 'Closing the connection', C.red], ['S', 'Starting up', C.teal],
        ['D', 'DNS lookup', '#5b6cc4'], ['L', 'Logging', '#c0699b'], ['G', 'Finishing gracefully', '#8a7a5c'],
        ['I', 'Idle cleanup', '#6f8f6a'], ['.', 'Open slot, no process', null]];
    var STATE = {};
    STATES.forEach(function (s) { STATE[s[0]] = { label: s[1], color: s[2] }; });
    var interval = 5000, timer = null, paused = false, prev = null, cur = null, hist = [];
    var show = 'active', sortKey = 'ss', sortAsc = false, filter = '';
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
    var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
                  set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private window */ } } };

    function fmtBytes(b, d) {
        var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'], i = 0; b = Math.max(0, b || 0);
        while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
        return (i === 0 ? Math.round(b) : b.toFixed(d === undefined ? (b < 10 ? 1 : 0) : d)) + ' ' + u[i];
    }
    function fmtRate(b) { return fmtBytes(b) + '/s'; }
    function fmtNum(v) { return (v === null || v === undefined) ? '–' : Number(v).toLocaleString(); }
    function fmtRps(v) { return v >= 100 ? Math.round(v).toLocaleString() : v >= 10 ? v.toFixed(1) : v.toFixed(2); }
    function fmtMs(v) { return v >= 1000 ? (v / 1000).toFixed(2) + ' s' : v >= 10 || v === 0 ? Math.round(v) + ' ms' : v.toFixed(1) + ' ms'; }
    function fmtSecs(s) {
        if (s < 60) { return s + ' s'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm ' + (s % 60) + 's'; }
        return fmtUptime(s);
    }
    function pct(v) { return (Math.round(v * 10) / 10).toFixed(1) + '%'; }
    function fmtUptime(s) {
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
        return (d ? d + 'd ' : '') + h + 'h ' + m + 'm';
    }
    function clock(t) { var d = new Date(t * 1000); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
    function n0(v) { return v === null || v === undefined ? 0 : +v; }

    /* ---- charts ---------------------------------------------------------- */
    function svgEl(tag, attrs) {
        var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (var k in attrs) { e.setAttribute(k, attrs[k]); }
        return e;
    }
    // series: [{name, color, values: [..]}], times: [..]; opts: {stack, max, min, fill, fmt}
    function chart(box, times, series, opts) {
        if (!box.offsetParent) { return; }         // on a hidden tab: drawn when it is shown
        var w = box.clientWidth || 400, h = box.clientHeight || 180, pl = 52, pr = 8, pt = 8, pb = 20;
        var iw = w - pl - pr, ih = h - pt - pb, n = times.length;
        var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none' });
        var stacked = [], top = 0;
        for (var i = 0; i < n; i++) {
            var acc = 0, col = [];
            for (var s = 0; s < series.length; s++) { var v = series[s].values[i] || 0; acc = opts.stack ? acc + v : v; col.push(acc); top = Math.max(top, acc); }
            stacked.push(col);
        }
        var max = opts.max || niceMax(Math.max(top, opts.min || 0));
        if (opts.whole) {                         // counts: whole steps on the axis (1-5, then 5s)
            var step = Math.max(1, Math.ceil(top / 4));
            max = (step <= 5 ? step : Math.ceil(step / 5) * 5) * 4;
        }
        var tEnd = n ? times[n - 1] : 0, tStart = tEnd - WINDOW;
        var X = function (t) { return pl + (t - tStart) / WINDOW * iw; }, Y = function (v) { return pt + ih - v / max * ih; };
        var g = svgEl('g', { 'class': 'grid' }), ax = svgEl('g', { 'class': 'axis' });
        for (var k = 0; k <= 4; k++) {
            var yv = max * k / 4, y = Y(yv);
            g.appendChild(svgEl('line', { x1: pl, x2: w - pr, y1: y, y2: y }));
            var tx = svgEl('text', { x: pl - 6, y: y + 3, 'text-anchor': 'end' }); tx.textContent = opts.fmt(yv); ax.appendChild(tx);
        }
        for (var m = 0; m <= 5; m++) {
            var tt = svgEl('text', { x: pl + iw * m / 5, y: h - 4, 'text-anchor': m === 0 ? 'start' : (m === 5 ? 'end' : 'middle') });
            tt.textContent = m === 5 ? 'now' : '-' + Math.round(WINDOW / 60 * (5 - m) / 5) + 'm'; ax.appendChild(tt);
        }
        svg.appendChild(g); svg.appendChild(ax);
        for (var s2 = series.length - 1; s2 >= 0; s2--) {
            if (n < 2) { break; }
            var line = '', area = '';
            for (var j = 0; j < n; j++) { line += (j ? 'L' : 'M') + X(times[j]).toFixed(1) + ',' + Y(stacked[j][s2]).toFixed(1); }
            if (opts.stack || series.length === 1 || opts.fill) {
                area = line;
                for (var j2 = n - 1; j2 >= 0; j2--) { area += 'L' + X(times[j2]).toFixed(1) + ',' + Y(opts.stack && s2 > 0 ? stacked[j2][s2 - 1] : 0).toFixed(1); }
                svg.appendChild(svgEl('path', { d: area + 'Z', fill: series[s2].color, 'fill-opacity': 0.22 }));
            }
            svg.appendChild(svgEl('path', { d: line, fill: 'none', stroke: series[s2].color, 'stroke-width': 1.8, 'stroke-linejoin': 'round' }));
        }
        var cursor = svgEl('line', { y1: pt, y2: pt + ih, stroke: C.grey, 'stroke-dasharray': '3,3', visibility: 'hidden' });
        svg.appendChild(cursor);
        box.innerHTML = '';
        box.appendChild(svg);
        var tip = document.createElement('div'); tip.className = 'as-tip'; box.appendChild(tip);
        svg.addEventListener('mousemove', function (ev) {
            if (n < 2) { return; }
            var r = svg.getBoundingClientRect(), x = (ev.clientX - r.left) * w / r.width;
            var t = tStart + (x - pl) / iw * WINDOW, best = 0;
            for (var q = 1; q < n; q++) { if (Math.abs(times[q] - t) < Math.abs(times[best] - t)) { best = q; } }
            var cx = X(times[best]);
            cursor.setAttribute('x1', cx); cursor.setAttribute('x2', cx); cursor.setAttribute('visibility', 'visible');
            var html = '<b>' + clock(times[best]) + '</b>';
            for (var s3 = 0; s3 < series.length; s3++) {
                html += '<br><i style="display:inline-block;width:8px;height:8px;background:' + series[s3].color + ';margin-right:5px"></i>'
                    + esc(series[s3].name) + ': ' + opts.fmt(series[s3].values[best] || 0);
            }
            tip.innerHTML = html; tip.style.display = 'block';
            var left = cx / w * r.width + 12;
            if (left + tip.offsetWidth > r.width) { left = cx / w * r.width - tip.offsetWidth - 12; }
            tip.style.left = left + 'px'; tip.style.top = '6px';
        });
        svg.addEventListener('mouseleave', function () { cursor.setAttribute('visibility', 'hidden'); tip.style.display = 'none'; });
    }
    function niceMax(v) {
        if (v <= 0) { return 1; }
        var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
    }
    function spark(id, values, color, max) {
        var el = $(id); if (!el || !el.offsetParent) { return; }
        var w = el.clientWidth || 160, h = 34, n = values.length; el.innerHTML = '';
        if (n < 2) { return; }
        max = max || Math.max.apply(null, values) || 1;
        var d = '';
        for (var i = 0; i < n; i++) { d += (i ? 'L' : 'M') + (i / (n - 1) * w).toFixed(1) + ',' + (h - 2 - (values[i] / max) * (h - 4)).toFixed(1); }
        var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none', 'class': 'as-spark' });
        svg.appendChild(svgEl('path', { d: d + 'L' + w + ',' + h + 'L0,' + h + 'Z', fill: color, 'fill-opacity': 0.18 }));
        svg.appendChild(svgEl('path', { d: d, fill: 'none', stroke: color, 'stroke-width': 1.6 }));
        el.appendChild(svg);
    }
    function legend(id, items) {
        $(id).innerHTML = items.map(function (s) {
            return '<span><i style="background:' + (s.color || 'var(--as-line)') + '"></i>' + esc(s.name) + (s.value !== undefined ? '<b>' + esc(s.value) + '</b>' : '') + '</span>';
        }).join('');
    }
    function dl(id, rows) {
        $(id).innerHTML = rows.filter(function (r) { return r[1] !== null && r[1] !== undefined && r[1] !== ''; })
            .map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('');
    }

    /* ---- one sample ------------------------------------------------------ */
    function update(d) {
        cur = d;
        var T = d.totals, W = d.workers, Cn = d.connections, S = d.server;
        // rates between this read and the last, without the panel's own reads; the
        // averages since the restart until there are two reads (or after a restart)
        var rps = n0(T.reqPerSec), bps = n0(T.bytesPerSec), ms = n0(T.msPerReq), live = false;
        if (prev && d.t > prev.t && n0(T.accesses) >= n0(prev.totals.accesses)) {
            var dt = d.t - prev.t, own = prev.self || { requests: 0, bytes: 0 };
            var dReq = Math.max(0, n0(T.accesses) - n0(prev.totals.accesses) - own.requests);
            rps = dReq / dt;
            bps = Math.max(0, (n0(T.kbytes) - n0(prev.totals.kbytes)) * 1024 - own.bytes) / dt;
            var dDur = n0(T.durationMs) - n0(prev.totals.durationMs);
            if (T.durationMs !== null && dReq > 0 && dDur >= 0) { ms = dDur / dReq; }
            live = true;
        }
        var busy = n0(W.busy), idle = n0(W.idle), states = W.states || {};
        var async = n0(Cn.writing) + n0(Cn.keepAlive) + n0(Cn.closing);
        if (live) {
            hist.push({ t: d.t, rps: rps, bps: bps, ms: ms, busy: busy, idle: idle, conn: n0(Cn.total), async: async, cpu: n0(T.cpu.load) });
            while (hist.length && hist[0].t < d.t - WINDOW) { hist.shift(); }
        }

        // header
        $('as-version').textContent = S.version || 'Apache';
        $('as-ident').textContent = [S.mpm ? S.mpm + ' MPM' : '', S.built ? 'built ' + S.built : ''].filter(Boolean).join(' · ');
        $('as-uptime').textContent = (S.uptime !== null ? 'up ' + fmtUptime(S.uptime) : '') + (S.restart ? ' · restarted ' + S.restart : '');

        // cards
        $('as-rps').textContent = fmtRps(rps) + '/s';
        $('as-rps-d').textContent = 'average ' + fmtRps(n0(T.reqPerSec)) + '/s · ' + fmtNum(T.accesses) + ' since the restart';
        $('as-bps').textContent = fmtRate(bps);
        $('as-bps-d').textContent = 'average ' + fmtRate(n0(T.bytesPerSec)) + ' · ' + fmtBytes(n0(T.kbytes) * 1024) + ' since the restart';
        $('as-ms').textContent = T.msPerReq === null && !live ? '–' : fmtMs(ms);
        $('as-ms-d').textContent = (T.msPerReq !== null ? 'average ' + fmtMs(n0(T.msPerReq)) : 'not reported') + ' · ' + fmtBytes(n0(T.bytesPerReq)) + ' per request';
        $('as-busy').textContent = busy + ' / ' + (busy + idle);
        $('as-busy-d').textContent = idle + ' idle' + (W.graceful ? ' · ' + W.graceful + ' finishing' : '') + (W.processes !== null ? ' · ' + W.processes + (W.processes === 1 ? ' process' : ' processes') : '');
        $('as-conn').textContent = Cn.total !== null ? fmtNum(Cn.total) : '–';
        $('as-conn-d').textContent = Cn.total !== null ? 'writing ' + n0(Cn.writing) + ' · keep-alive ' + n0(Cn.keepAlive) + ' · closing ' + n0(Cn.closing) : 'reported by the event MPM only';
        $('as-cpu').textContent = T.cpu.load !== null ? pct(T.cpu.load) : '–';
        $('as-cpu-d').textContent = T.cpu.user !== null ? 'user ' + T.cpu.user.toFixed(2) + ' s · system ' + n0(T.cpu.system).toFixed(2) + ' s'
            + (n0(T.cpu.childrenUser) + n0(T.cpu.childrenSystem) ? ' · children ' + (n0(T.cpu.childrenUser) + n0(T.cpu.childrenSystem)).toFixed(2) + ' s' : '') : 'not reported';
        drawOverview();

        // worker slots
        var slots = W.slots || 0, open = n0(states['.']);
        $('as-slots-sub').textContent = slots + ' slots · ' + (slots - open) + ' with a process · ' + open + ' open';
        $('as-statebar').innerHTML = STATES.filter(function (s) { return s[2] && states[s[0]]; }).map(function (s) {
            return '<span style="width:' + (states[s[0]] / slots * 100).toFixed(2) + '%;background:' + s[2] + '" title="' + esc(s[1] + ': ' + states[s[0]]) + '"></span>';
        }).join('');
        var used = STATES.filter(function (s) { return states[s[0]]; }).map(function (s) { return { name: s[1], color: s[2], value: states[s[0]] }; });
        legend('as-statelegend', used);

        // hints
        var hints = [], load = busy + idle ? busy / (busy + idle) * 100 : 0;
        if (load >= 95) { hints.push(['bad', 'Almost every worker is busy (' + pct(load) + '). New requests wait for one to come free: raise MaxRequestWorkers, or find what holds them (Requests tab).']); }
        else if (load >= 85) { hints.push(['warn', 'Most workers are busy (' + pct(load) + '). If this lasts, Apache will soon make requests wait.']); }
        if (slots && open / slots >= 0.85 && busy + idle > 0) { hints.push(['info', pct(open / slots * 100) + ' of the worker slots have no process. That is normal on a quiet server: Apache starts more when traffic grows.']); }
        $('as-hints').innerHTML = hints.map(function (h) { return '<div class="as-hint ' + h[0] + '">' + esc(h[1]) + '</div>'; }).join('');

        drawRequests();
        drawAnalysis();
        drawWorkers();
        drawServer();
        $('as-updated').textContent = clock(d.t);
        prev = d;
    }

    function drawOverview() {
        if (!cur) { return; }
        var H = function (f) { return hist.map(f); }, times = H(function (p) { return p.t; });
        spark('as-rps-s', H(function (p) { return p.rps; }), C.blue);
        spark('as-bps-s', H(function (p) { return p.bps; }), C.teal);
        spark('as-ms-s', H(function (p) { return p.ms; }), C.purple);
        spark('as-busy-s', H(function (p) { return p.busy; }), C.green, Math.max.apply(null, H(function (p) { return p.busy + p.idle; }).concat([1])));
        spark('as-conn-s', H(function (p) { return p.conn; }), C.amber);
        spark('as-cpu-s', H(function (p) { return p.cpu; }), C.red);
        var rpsS = [{ name: 'Requests per second', color: C.blue, values: H(function (p) { return p.rps; }) }];
        legend('as-rps-l', rpsS);
        chart($('as-rps-c'), times, rpsS, { min: 1, fmt: function (v) { return v ? fmtRps(v) : '0'; } });
        var wkS = [{ name: 'Busy', color: C.green, values: H(function (p) { return p.busy; }) },
                   { name: 'Idle', color: C.grey, values: H(function (p) { return p.idle; }) }];
        legend('as-wk-l', wkS);
        chart($('as-wk-c'), times, wkS, { stack: true, whole: true, fmt: function (v) { return String(Math.round(v)); } });
        var bpsS = [{ name: 'Sent', color: C.teal, values: H(function (p) { return p.bps; }) }];
        legend('as-bps-l', bpsS);
        chart($('as-bps-c'), times, bpsS, { min: 1024, fmt: function (v) { return v ? fmtRate(v) : '0'; } });
        var cnS = [{ name: 'Open', color: C.amber, values: H(function (p) { return p.conn; }) },
                   { name: 'Without a thread', color: C.purple, values: H(function (p) { return p.async; }) }];
        legend('as-cn-l', cnS);
        chart($('as-cn-c'), times, cnS, { fill: true, whole: true, fmt: function (v) { return String(Math.round(v)); } });
    }

    /* ---- requests -------------------------------------------------------- */
    function isActive(r) { return r.mode !== '_' && r.mode !== '.' && r.mode !== 'I' && r.request !== ''; }
    function stateCell(m) {
        var s = STATE[m] || { label: m, color: C.grey };
        return '<span class="as-state" title="' + esc(s.label) + '"><i style="background:' + (s.color || 'var(--as-line)') + '"></i>' + esc(s.label) + '</span>';
    }
    function drawRequests() {
        if (!cur) { return; }
        var all = cur.requests || [], active = all.filter(isActive);
        $('as-badge-active').textContent = active.length;
        var rows = show === 'active' ? active : all, f = filter.toLowerCase();
        if (f) {
            rows = rows.filter(function (r) { return (r.client + ' ' + r.vhost + ' ' + r.request + ' ' + r.protocol).toLowerCase().indexOf(f) >= 0; });
        }
        var key = function (r) { return sortKey === 'acc' ? r.acc.conn : r[sortKey]; };
        rows = rows.slice().sort(function (a, b) {
            var x = key(a), y = key(b), c = (typeof x === 'number' && typeof y === 'number') ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true });
            return sortAsc ? c : -c;
        });
        $('as-req-count').textContent = rows.length + (rows.length === 1 ? ' request' : ' requests') + (show === 'active' ? ' running' : '') + ' · ' + all.length + ' slots listed';
        $('as-req-body').innerHTML = rows.map(function (r) {
            var req = r.method ? '<span class="as-method">' + esc(r.method) + '</span>' + esc(r.uri) : esc(r.request);
            return '<tr><td>' + stateCell(r.mode) + '</td><td>' + esc(r.client) + '</td><td>' + esc(r.vhost) + '</td>'
                + '<td class="req" title="' + esc(r.request) + '">' + req + '</td><td>' + esc(r.protocol) + '</td>'
                + '<td class="num">' + fmtSecs(r.ss) + '</td><td class="num">' + (r.req || !isActive(r) ? fmtMs(r.req) : '–') + '</td><td class="num">' + r.cpu.toFixed(2) + '</td>'
                + '<td class="num">' + esc(r.pid) + '</td><td class="num">' + esc(r.srv) + '</td>'
                + '<td class="num">' + r.acc.conn + ' / ' + r.acc.child + ' / ' + r.acc.slot + '</td>'
                + '<td class="num" title="' + esc('connection ' + fmtBytes(r.connKb * 1024) + ' · child process ' + fmtBytes(r.childMb * 1048576) + ' · slot ' + fmtBytes(r.slotMb * 1048576)) + '">'
                + fmtBytes(r.slotMb * 1048576) + '</td></tr>';
        }).join('') || '<tr><td class="empty" colspan="12">' + (cur.requests && cur.requests.length ? (f ? 'Nothing matches the filter.' : 'No request is running right now.')
            : 'Apache did not list its worker slots. They need ExtendedStatus On.') + '</td></tr>';
    }

    /* ---- analysis -------------------------------------------------------- */
    function rank(id, list, color, fmt, none) {
        if (!list.length) { $(id).innerHTML = '<p class="as-note">' + esc(none || 'Nothing to count yet.') + '</p>'; return; }
        var max = list[0][1] || 1;
        $(id).innerHTML = '<table class="as-rank">' + list.map(function (e) {
            return '<tr><td class="n">' + (fmt ? fmt(e[1]) : e[1]) + '</td><td class="lbl" title="' + esc(e[0]) + '">' + esc(e[0]) + '</td>'
                + '<td class="bar"><div class="as-meter" style="--c:' + color + '"><b style="width:' + (e[1] / max * 100).toFixed(1) + '%"></b></div></td></tr>';
        }).join('') + '</table>';
    }
    function top(rows, f, n) {
        var c = {};
        rows.forEach(function (r) { var k = f(r); if (k) { c[k] = (c[k] || 0) + 1; } });
        return Object.keys(c).map(function (k) { return [k, c[k]]; }).sort(function (a, b) { return b[1] - a[1] || a[0].localeCompare(b[0]); }).slice(0, n);
    }
    function drawAnalysis() {
        if (!cur) { return; }
        var rows = (cur.requests || []).filter(function (r) { return r.request !== '' && r.mode !== '.'; });
        rank('as-top-req', top(rows, function (r) { return r.method ? r.method + ' ' + r.uri : r.request; }, 10), C.blue);
        rank('as-top-host', top(rows, function (r) { return r.vhost; }, 10), C.teal);
        rank('as-top-client', top(rows, function (r) { return r.client; }, 10), C.purple);
        rank('as-top-method', top(rows, function (r) { return r.method; }, 10), C.amber);
        var longest = rows.filter(function (r) { return r.req > 0; }).sort(function (a, b) { return b.req - a.req; }).slice(0, 25)
            .map(function (r) { return [(r.vhost ? r.vhost + '  ' : '') + r.request, r.req]; });
        rank('as-longest', longest, C.red, function (v) { return fmtMs(v); }, 'Every request took under a millisecond.');
    }

    /* ---- workers --------------------------------------------------------- */
    function drawWorkers() {
        if (!cur) { return; }
        var sb = cur.workers.scoreboard || '', html = '';
        for (var i = 0; i < sb.length; i++) {
            var s = STATE[sb[i]] || { label: sb[i], color: C.grey };
            html += '<i' + (s.color ? ' style="background:' + s.color + '"' : '') + ' title="Slot ' + i + ': ' + esc(s.label) + '"></i>';
        }
        $('as-scoreboard').innerHTML = html;
        var states = cur.workers.states || {};
        legend('as-scorelegend', STATES.filter(function (s) { return states[s[0]]; }).map(function (s) { return { name: s[1], color: s[2], value: states[s[0]] }; }));
        var procs = cur.processes || [];
        $('as-proc-panel').style.display = procs.length ? '' : 'none';
        $('as-proc-body').innerHTML = procs.map(function (p) {
            return '<tr><td class="num">' + p.slot + '</td><td class="num">' + esc(p.pid) + '</td><td>' + esc(p.stopping) + '</td><td class="num">' + p.connections + '</td>'
                + '<td>' + esc(p.accepting) + '</td><td class="num">' + p.busy + '</td><td class="num">' + p.graceful + '</td><td class="num">' + p.idle + '</td>'
                + '<td class="num">' + p.writing + '</td><td class="num">' + p.keepAlive + '</td><td class="num">' + p.closing + '</td></tr>';
        }).join('');
    }

    /* ---- server ---------------------------------------------------------- */
    function drawServer() {
        var S = cur.server, T = cur.totals, cpu = T.cpu;
        dl('as-dl-server', [['Version', S.version], ['MPM', S.mpm], ['Built', S.built], ['Restarted', S.restart],
            ['Up for', S.uptime !== null ? fmtUptime(S.uptime) : null], ['Config generation', S.configGeneration], ['MPM generation', S.mpmGeneration],
            ['Processes', cur.workers.processes], ['Stopping', cur.workers.stopping]]);
        dl('as-dl-totals', [['Requests', fmtNum(T.accesses)], ['Sent', fmtBytes(n0(T.kbytes) * 1024)],
            ['Requests per second', T.reqPerSec !== null ? fmtRps(T.reqPerSec) : null], ['Sent per second', T.bytesPerSec !== null ? fmtRate(T.bytesPerSec) : null],
            ['Per request', T.bytesPerReq !== null ? fmtBytes(T.bytesPerReq) : null], ['Time per request', T.msPerReq !== null ? fmtMs(T.msPerReq) : null],
            ['Time serving', T.durationMs !== null ? fmtSecs(Math.round(T.durationMs / 1000)) : null],
            ['CPU, user', cpu.user !== null ? cpu.user.toFixed(2) + ' s' : null], ['CPU, system', cpu.system !== null ? cpu.system.toFixed(2) + ' s' : null],
            ['CPU, child processes', cpu.childrenUser !== null ? (n0(cpu.childrenUser) + n0(cpu.childrenSystem)).toFixed(2) + ' s' : null],
            ['CPU load', cpu.load !== null ? pct(cpu.load) : null]]);
        $('as-raw').textContent = cur.raw;
    }

    /* ---- tabs and controls ---------------------------------------------- */
    function tab(name) {
        var found = false;
        Array.prototype.forEach.call(root.querySelectorAll('.as-tabs button'), function (b) {
            var on = b.getAttribute('data-tab') === name; found = found || on;
            b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (!found) { return tab('overview'); }
        Array.prototype.forEach.call(root.querySelectorAll('.as-pane'), function (p) { p.classList.toggle('on', p.getAttribute('data-pane') === name); });
        store.set('as-tab', name);
        if (name === 'overview') { drawOverview(); }
    }
    Array.prototype.forEach.call(root.querySelectorAll('.as-tabs button'), function (b) {
        b.addEventListener('click', function () { tab(this.getAttribute('data-tab')); });
    });
    Array.prototype.forEach.call(root.querySelectorAll('.as-seg button'), function (b) {
        b.addEventListener('click', function () {
            show = this.getAttribute('data-show');
            Array.prototype.forEach.call(root.querySelectorAll('.as-seg button'), function (o) { o.classList.toggle('on', o === b); });
            drawRequests();
        });
    });
    $('as-filter').addEventListener('input', function () { filter = this.value.trim(); drawRequests(); });
    Array.prototype.forEach.call(root.querySelectorAll('#as-req-table th[data-sort]'), function (th) {
        th.addEventListener('click', function () {
            var k = this.getAttribute('data-sort');
            if (k === sortKey) { sortAsc = !sortAsc; } else { sortKey = k; sortAsc = !this.classList.contains('num'); }
            Array.prototype.forEach.call(root.querySelectorAll('#as-req-table th[data-sort]'), function (o) {
                o.classList.toggle('on', o === th); o.classList.toggle('asc', o === th && sortAsc);
            });
            drawRequests();
        });
    });
    var resizeTimer = null;
    window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(drawOverview, 150); });

    /* ---- polling --------------------------------------------------------- */
    function poll() {
        fetch(URL, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    if (!r.ok || j.error) { throw new Error(j.error || 'HTTP ' + r.status); }
                    return j;
                });
            })
            .then(function (d) { $('as-error').style.display = 'none'; update(d); })
            .catch(function (e) { $('as-error').textContent = 'Could not read Apache\'s status: ' + e.message + ' Retrying…'; $('as-error').style.display = 'block'; })
            .then(schedule);
    }
    function schedule() {
        clearTimeout(timer);
        if (!paused && !document.hidden) { timer = setTimeout(poll, interval); }
    }
    $('as-interval').addEventListener('change', function () { interval = +this.value; schedule(); });
    $('as-pause').addEventListener('click', function () {
        paused = !paused; this.textContent = paused ? 'Resume' : 'Pause';
        $('as-live').classList.toggle('paused', paused); if (!paused) { prev = null; poll(); } else { clearTimeout(timer); }
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !paused) { prev = null; poll(); } });
    tab(store.get('as-tab') || 'overview');
    poll();
    setTimeout(poll, 1000);   // a second read soon, so rates show straight away
})();
