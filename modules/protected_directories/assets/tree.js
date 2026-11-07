/* Protected Directories: a folder tree of the account's public_html, each folder
   loaded when it is opened (code/folders.php). Clicking a folder chooses it; the
   arrow opens it. Names are inserted as text, never HTML. */
(function () {
    'use strict';
    var tree = document.getElementById('pd-tree');
    if (!tree) { return; }
    var url = tree.getAttribute('data-url');
    var pathInput = document.getElementById('pd-path');
    var chosen = document.getElementById('pd-chosen-path');
    var note = document.getElementById('pd-chosen-note');
    var submit = document.getElementById('pd-submit');
    var selectedRow = null;
    var NS = 'http://www.w3.org/2000/svg';

    function icon(paths, cls) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('class', cls);
        paths.forEach(function (d) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); svg.appendChild(p); });
        return svg;
    }
    var FOLDER = ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'];
    var LOCK = ['M6 11h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1z', 'M8 11V7a4 4 0 0 1 8 0v4'];

    function load(path, done) {
        fetch(url + '?p=' + encodeURIComponent(path), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }); })
            .then(function (d) { done(null, d); })
            .catch(function (e) { done(e); });
    }
    function msg(text, tag) { var e = document.createElement(tag || 'li'); e.className = 'pd-tree-msg'; e.textContent = text; return e; }

    function select(row, folder) {
        if (selectedRow) { selectedRow.classList.remove('is-chosen'); selectedRow.setAttribute('aria-pressed', 'false'); }
        selectedRow = row; row.classList.add('is-chosen'); row.setAttribute('aria-pressed', 'true');
        chosen.textContent = folder.path === '' ? 'public_html' : 'public_html/' + folder.path;
        chosen.classList.remove('is-empty');
        pathInput.value = folder.path;
        note.hidden = !folder.protected;
        submit.disabled = !!folder.protected;
    }

    function node(folder) {
        var li = document.createElement('li');
        var line = document.createElement('div'); line.className = 'pd-line';
        var tog = document.createElement('button'); tog.type = 'button'; tog.className = 'pd-tog';
        tog.textContent = folder.children ? '▸' : ''; tog.disabled = !folder.children; tog.setAttribute('aria-label', folder.name);
        var row = document.createElement('button'); row.type = 'button'; row.className = 'pd-row' + (folder.protected ? ' is-locked' : '');
        row.appendChild(icon(FOLDER, 'pd-ti'));
        var name = document.createElement('span'); name.textContent = folder.name; row.appendChild(name);
        if (folder.protected) { var l = icon(LOCK, 'pd-ti pd-lock'); row.appendChild(l); row.title = tree.getAttribute('data-locked'); }
        line.appendChild(tog); line.appendChild(row); li.appendChild(line);
        var sub = null, open = false;
        function expand() {
            if (!folder.children) { return; }
            if (sub) { open = !open; sub.hidden = !open; tog.textContent = open ? '▾' : '▸'; tog.setAttribute('aria-expanded', open); return; }
            sub = document.createElement('ul');
            var wait = msg(tree.getAttribute('data-loading'));
            sub.appendChild(wait); li.appendChild(sub); open = true; tog.textContent = '▾'; tog.setAttribute('aria-expanded', 'true');
            load(folder.path, function (err, d) {
                sub.removeChild(wait);
                if (err) { sub.appendChild(msg(err.message)); return; }
                d.folders.forEach(function (f) { sub.appendChild(node(f)); });
                if (!d.folders.length) { sub.appendChild(msg(tree.getAttribute('data-none'))); }
            });
        }
        tog.addEventListener('click', expand);
        row.addEventListener('click', function () { select(row, folder); });
        row.addEventListener('dblclick', expand);
        li.expand = expand;
        return li;
    }

    load('', function (err, d) {
        tree.innerHTML = '';
        if (err) { tree.appendChild(msg(err.message, 'div')); return; }
        var ul = document.createElement('ul');
        var root = node({ name: 'public_html', path: '', children: d.folders.length > 0, protected: d.protected });
        ul.appendChild(root); tree.appendChild(ul);
        root.expand(); // open the top level straight away
    });
})();
