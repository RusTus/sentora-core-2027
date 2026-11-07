/* Server Monitor: live server dashboard (no outside libraries). Polls stats.php,
   turns the kernel's counters into rates and draws SVG charts. */
(function () {
    'use strict';
    var root = document.getElementById('sm');
    if (!root) { return; }
    var URL = root.getAttribute('data-url');
    var WINDOW = 10 * 60;                         // seconds of history in the charts
    var C = { blue: '#447fb3', red: '#ff6854', green: '#2db87d', amber: '#f5a623', purple: '#8e6bd8', teal: '#1fa2b5' };
    var interval = 2000, timer = null, paused = false, prev = null, info = null, hist = [], sortKey = 'cpu';
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
    // the page's words, translated in the template (data-t-*); each %s / %d is filled in order
    function T(key, fallback) {
        var t = root.getAttribute('data-t-' + key) || fallback, args = Array.prototype.slice.call(arguments, 2);
        args.forEach(function (a) { t = t.replace(/%[sd]/, function () { return String(a); }); });
        return t;
    }

    function fmtBytes(b, d) {
        var u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'], i = 0; b = Math.max(0, b || 0);
        while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
        return (i === 0 ? Math.round(b) : b.toFixed(d === undefined ? (b < 10 ? 1 : 0) : d)) + ' ' + u[i];
    }
    function fmtRate(b) { return fmtBytes(b) + '/s'; }
    function pct(v) { return (Math.round(v * 10) / 10).toFixed(1) + '%'; }
    function fmtUptime(s) {
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
        return (d ? d + 'd ' : '') + h + 'h ' + m + 'm';
    }
    function clock(t) { var d = new Date(t * 1000); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }

    /* ---- charts ---------------------------------------------------------- */
    function svgEl(tag, attrs) {
        var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (var k in attrs) { e.setAttribute(k, attrs[k]); }
        return e;
    }
    // series: [{name, color, values: [..]}], times: [..]; opts: {stack, max, fmt}
    function chart(box, times, series, opts) {
        var w = box.clientWidth || 400, h = box.clientHeight || 190, pl = 52, pr = 8, pt = 8, pb = 20;
        var iw = w - pl - pr, ih = h - pt - pb, n = times.length;
        var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none' });
        var stacked = [], top = 0;
        for (var i = 0; i < n; i++) {
            var acc = 0, col = [];
            for (var s = 0; s < series.length; s++) { var v = series[s].values[i] || 0; acc = opts.stack ? acc + v : v; col.push(acc); top = Math.max(top, acc); }
            stacked.push(col);
        }
        var max = opts.max || niceMax(Math.max(top, opts.min || 0));
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
            tt.textContent = m === 5 ? T('now', 'now') : '-' + Math.round(WINDOW / 60 * (5 - m) / 5) + 'm'; ax.appendChild(tt);
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
        var cursor = svgEl('line', { y1: pt, y2: pt + ih, stroke: '#9aa5b1', 'stroke-dasharray': '3,3', visibility: 'hidden' });
        svg.appendChild(cursor);
        box.innerHTML = '';
        box.appendChild(svg);
        var tip = document.createElement('div'); tip.className = 'sm-tip'; box.appendChild(tip);
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
        var el = $(id); if (!el) { return; }
        var w = el.clientWidth || 160, h = 34, n = values.length; el.innerHTML = '';
        if (n < 2) { return; }
        max = max || Math.max.apply(null, values) || 1;
        var d = '';
        for (var i = 0; i < n; i++) { d += (i ? 'L' : 'M') + (i / (n - 1) * w).toFixed(1) + ',' + (h - 2 - (values[i] / max) * (h - 4)).toFixed(1); }
        var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none', 'class': 'sm-spark' });
        svg.appendChild(svgEl('path', { d: d + 'L' + w + ',' + h + 'L0,' + h + 'Z', fill: color, 'fill-opacity': 0.18 }));
        svg.appendChild(svgEl('path', { d: d, fill: 'none', stroke: color, 'stroke-width': 1.6 }));
        el.appendChild(svg);
    }
    function legend(id, items) {
        $(id).innerHTML = items.map(function (s) { return '<span><i style="background:' + s.color + '"></i>' + esc(s.name) + '</span>'; }).join('');
    }

    /* ---- one sample ------------------------------------------------------ */
    function cpuPct(a, b) {
        var tot = function (v) { return v.reduce(function (x, y) { return x + y; }, 0); };
        var dt = tot(b) - tot(a) || 1;
        return { user: (b[0] + b[1] - a[0] - a[1]) / dt * 100, system: (b[2] + b[5] + b[6] - a[2] - a[5] - a[6]) / dt * 100,
                 iowait: (b[4] - a[4]) / dt * 100, steal: (b[7] - a[7]) / dt * 100,
                 busy: (dt - (b[3] - a[3]) - (b[4] - a[4])) / dt * 100 };
    }
    function sum(obj, idx) { var s = 0; for (var k in obj) { s += obj[k][idx]; } return s; }

    function update(cur) {
        if (cur.info) { info = cur.info; showInfo(); }
        if (!prev) { prev = cur; return; }
        var dt = Math.max(0.2, cur.t - prev.t), cpu = cpuPct(prev.cpu.cpu, cur.cpu.cpu);
        var m = cur.mem, used = m.total - m.available;
        var point = {
            t: cur.t, cpu: cpu, load: cur.load[0], used: used, cache: Math.max(0, m.total - m.free - used), swap: m.swapTotal - m.swapFree,
            rx: Math.max(0, (sum(cur.net, 0) - sum(prev.net, 0)) / dt), tx: Math.max(0, (sum(cur.net, 1) - sum(prev.net, 1)) / dt),
            rd: Math.max(0, (sum(cur.io, 0) - sum(prev.io, 0)) / dt), wr: Math.max(0, (sum(cur.io, 1) - sum(prev.io, 1)) / dt)
        };
        hist.push(point);
        while (hist.length && hist[0].t < cur.t - WINDOW) { hist.shift(); }
        var H = function (f) { return hist.map(f); }, times = H(function (p) { return p.t; });

        // cards
        $('sm-cpu').textContent = pct(cpu.busy);
        $('sm-cpu-d').textContent = T('user', 'user') + ' ' + pct(cpu.user) + ' · ' + T('system', 'system') + ' ' + pct(cpu.system) + ' · ' + T('iowait', 'I/O wait') + ' ' + pct(cpu.iowait);
        $('sm-load').textContent = cur.load[0].toFixed(2);
        $('sm-load-d').textContent = '5m ' + cur.load[1].toFixed(2) + ' · 15m ' + cur.load[2].toFixed(2) + ' · ' + (info ? (info.cores === 1 ? T('core', '1 core') : T('cores', '%d cores', info.cores)) : '');
        $('sm-mem').textContent = pct(used / m.total * 100);
        $('sm-mem-d').textContent = T('of', '%s of %s', fmtBytes(used), fmtBytes(m.total)) + ' · ' + T('cache', 'cache %s', fmtBytes(point.cache));
        $('sm-swap').textContent = m.swapTotal ? pct(point.swap / m.swapTotal * 100) : T('none', 'none');
        $('sm-swap-d').textContent = m.swapTotal ? T('of', '%s of %s', fmtBytes(point.swap), fmtBytes(m.swapTotal)) : T('noswap', 'no swap set up');
        $('sm-net').textContent = fmtRate(point.rx + point.tx);
        $('sm-net-d').textContent = '↓ ' + fmtRate(point.rx) + ' · ↑ ' + fmtRate(point.tx);
        $('sm-io').textContent = fmtRate(point.rd + point.wr);
        $('sm-io-d').textContent = T('read', 'read %s', fmtRate(point.rd)) + ' · ' + T('write', 'write %s', fmtRate(point.wr));
        $('sm-uptime').textContent = T('up', 'up %s', fmtUptime(cur.uptime)) + ' · ' + T('procs', '%d processes', cur.procs.length) + ' · ' + T('running', '%d running', cur.running || 0);
        spark('sm-cpu-s', H(function (p) { return p.cpu.busy; }), C.blue, 100);
        spark('sm-load-s', H(function (p) { return p.load; }), C.purple);
        spark('sm-mem-s', H(function (p) { return p.used; }), C.green, m.total);
        spark('sm-swap-s', H(function (p) { return p.swap; }), C.amber, m.swapTotal || 1);
        spark('sm-net-s', H(function (p) { return p.rx + p.tx; }), C.teal);
        spark('sm-io-s', H(function (p) { return p.rd + p.wr; }), C.red);

        // charts
        var cpuSeries = [
            { name: T('s-user', 'User'), color: C.blue, values: H(function (p) { return p.cpu.user; }) },
            { name: T('s-system', 'System'), color: C.purple, values: H(function (p) { return p.cpu.system; }) },
            { name: T('s-iowait', 'I/O wait'), color: C.amber, values: H(function (p) { return p.cpu.iowait; }) },
            { name: T('s-steal', 'Steal'), color: C.red, values: H(function (p) { return p.cpu.steal; }) }];
        legend('sm-cpu-l', cpuSeries);
        chart($('sm-cpu-c'), times, cpuSeries, { stack: true, max: 100, fmt: function (v) { return Math.round(v) + '%'; } });
        var memSeries = [
            { name: T('s-used', 'Used'), color: C.green, values: H(function (p) { return p.used; }) },
            { name: T('s-cache', 'Cache and buffers'), color: C.teal, values: H(function (p) { return p.cache; }) },
            { name: T('s-swap', 'Swap used'), color: C.amber, values: H(function (p) { return p.swap; }) }];
        legend('sm-mem-l', memSeries);
        // Scale in whole GiB (4, 8, 16, 20 …), so the axis steps are round numbers
        var gib = Math.pow(2, 30), top = Math.ceil(m.total / gib);
        top = top <= 4 ? top : Math.ceil(top / 4) * 4;
        chart($('sm-mem-c'), times, memSeries.slice(0, 2), { stack: true, max: top * gib, fmt: function (v) { return v ? fmtBytes(v, v % gib ? 1 : 0) : '0'; } });
        var netSeries = [
            { name: T('s-received', 'Received'), color: C.teal, values: H(function (p) { return p.rx; }) },
            { name: T('s-sent', 'Sent'), color: C.blue, values: H(function (p) { return p.tx; }) }];
        legend('sm-net-l', netSeries);
        chart($('sm-net-c'), times, netSeries, { fill: true, min: 1024, fmt: function (v) { return v ? fmtRate(v) : '0'; } });
        var ioSeries = [
            { name: T('s-read', 'Read'), color: C.green, values: H(function (p) { return p.rd; }) },
            { name: T('s-write', 'Write'), color: C.red, values: H(function (p) { return p.wr; }) }];
        legend('sm-io-l', ioSeries);
        chart($('sm-io-c'), times, ioSeries, { fill: true, min: 1024, fmt: function (v) { return v ? fmtRate(v) : '0'; } });
        var loadSeries = [{ name: T('s-load', 'Load (1 min)'), color: C.purple, values: H(function (p) { return p.load; }) }];
        legend('sm-load-l', loadSeries);
        chart($('sm-load-c'), times, loadSeries, { max: niceMax(Math.max.apply(null, loadSeries[0].values.concat([info ? info.cores : 1]))), fmt: function (v) { return v.toFixed(1); } });

        // per-core bars
        var cores = '';
        Object.keys(cur.cpu).filter(function (k) { return k !== 'cpu'; }).forEach(function (k) {
            var c = prev.cpu[k] ? cpuPct(prev.cpu[k], cur.cpu[k]).busy : 0;
            cores += '<div class="sm-core">' + esc(T('corename', 'Core %s', k.replace('cpu', ''))) + ' <b>' + Math.round(c) + '%</b><div class="sm-bar" style="--c:' + (c > 85 ? C.red : c > 60 ? C.amber : C.blue)
                + '"><b style="width:' + c.toFixed(1) + '%"></b></div></div>';
        });
        $('sm-cores').innerHTML = cores;

        // disk space
        $('sm-fs').innerHTML = cur.fs.map(function (f) {
            var u = (f.total - f.free) / f.total * 100;
            return '<div class="sm-fs"><div class="sm-fs-row"><b>' + esc(f.mount) + '</b><span>' + esc(T('of', '%s of %s', fmtBytes(f.total - f.free), fmtBytes(f.total)))
                + ' · ' + esc(T('free', '%s free', fmtBytes(f.free))) + '</span></div><div class="sm-bar" style="--c:' + (u > 90 ? C.red : u > 75 ? C.amber : C.green)
                + '"><b style="width:' + u.toFixed(1) + '%"></b></div></div>';
        }).join('') || '<p class="sm-sub">' + esc(T('nodisks', 'No disks found.')) + '</p>';

        // services
        $('sm-svc').innerHTML = Object.keys(cur.services).map(function (k) {
            return '<div class="sm-svc' + (cur.services[k] ? '' : ' down') + '"><i></i>' + esc(k) + (cur.services[k] ? '' : ' · ' + esc(T('stopped', 'stopped'))) + '</div>';
        }).join('');

        // processes
        var hz = info ? info.hz : 100, before = {};
        prev.procs.forEach(function (p) { before[p[0]] = p[3]; });
        var rows = cur.procs.map(function (p) {
            return { pid: p[0], name: p[1], user: p[2], cpu: before[p[0]] !== undefined ? Math.max(0, (p[3] - before[p[0]]) / hz / dt * 100) : 0, mem: p[4] };
        });
        rows.sort(function (a, b) { return sortKey === 'mem' ? b.mem - a.mem : (b.cpu - a.cpu || b.mem - a.mem); });
        $('sm-procs').innerHTML = rows.slice(0, 15).map(function (r) {
            var mp = r.mem / m.total * 100;
            return '<tr><td>' + esc(r.name) + '</td><td>' + esc(r.user) + '</td><td class="num">' + r.pid + '</td><td class="num">' + pct(r.cpu)
                + '<span class="sm-bar" style="--c:' + C.blue + '"><b style="width:' + Math.min(100, r.cpu).toFixed(1) + '%"></b></span></td><td class="num">' + fmtBytes(r.mem)
                + '<span class="sm-bar" style="--c:' + C.green + '"><b style="width:' + Math.min(100, mp * 4).toFixed(1) + '%"></b></span></td></tr>';
        }).join('');

        $('sm-updated').textContent = clock(cur.t);
        prev = cur;
    }
    function showInfo() {
        $('sm-hostname').textContent = info.host;
        $('sm-sysinfo').textContent = info.os + ' · ' + T('kernel', 'kernel %s', info.kernel) + ' · ' + info.cpu + (info.cores > 1 ? ' × ' + info.cores : '');
    }

    /* ---- polling --------------------------------------------------------- */
    function poll() {
        var url = URL + (info ? '' : (URL.indexOf('?') < 0 ? '?' : '&') + 'info=1');
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (d) { $('sm-error').style.display = 'none'; update(d); })
            .catch(function (e) { $('sm-error').textContent = T('error', 'Could not read the server figures (%s). Retrying…', e.message); $('sm-error').style.display = 'block'; })
            .then(schedule);
    }
    function schedule() {
        clearTimeout(timer);
        if (!paused && !document.hidden) { timer = setTimeout(poll, interval); }
    }
    $('sm-interval').addEventListener('change', function () { interval = +this.value; schedule(); });
    $('sm-pause').addEventListener('click', function () {
        paused = !paused; this.textContent = paused ? T('resume', 'Resume') : T('pause', 'Pause');
        $('sm-live').classList.toggle('paused', paused); if (!paused) { poll(); } else { clearTimeout(timer); }
    });
    Array.prototype.forEach.call(document.querySelectorAll('#sm th[data-sort]'), function (th) {
        th.addEventListener('click', function () {
            sortKey = this.getAttribute('data-sort');
            Array.prototype.forEach.call(document.querySelectorAll('#sm th[data-sort]'), function (o) { o.classList.toggle('on', o === th); });
        });
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !paused) { prev = null; poll(); } });
    poll();
    setTimeout(poll, 400);   // a second sample soon, so rates show straight away
})();
