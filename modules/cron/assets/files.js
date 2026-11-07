/* Cron Manager: a tree of the account's public_html, folders loaded as they are opened
   (code/files.php); clicking a .php file chooses it. Names are inserted as text, never HTML. */
(function () {
    'use strict';
    var tree = document.getElementById('cr-tree');
    if (!tree) { return; }
    var url = tree.getAttribute('data-url');
    var pathInput = document.getElementById('cr-path');
    var chosen = document.getElementById('cr-chosen');
    var submit = document.getElementById('cr-submit');
    var selectedRow = null;
    var NS = 'http://www.w3.org/2000/svg';

    function icon(d) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('class', 'cr-ti');
        d.forEach(function (x) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', x); svg.appendChild(p); });
        return svg;
    }
    var FOLDER = ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'];
    var FILE = ['M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z', 'M14 3v5h5'];

    function load(path, done) {
        fetch(url + '?p=' + encodeURIComponent(path), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }); })
            .then(function (d) { done(null, d); })
            .catch(function (e) { done(e); });
    }

    function msg(text, tag) { var e = document.createElement(tag || 'li'); e.className = 'cr-tree-msg'; e.textContent = text; return e; }

    function choose(row, file) {
        if (selectedRow) { selectedRow.classList.remove('is-chosen'); selectedRow.setAttribute('aria-selected', 'false'); }
        selectedRow = row; row.classList.add('is-chosen'); row.setAttribute('aria-selected', 'true');
        chosen.textContent = 'public_html/' + file.path;
        chosen.classList.remove('is-empty');
        pathInput.value = file.path;
        submit.disabled = false;
    }

    function fileNode(file) {
        var li = document.createElement('li');
        var row = document.createElement('button'); row.type = 'button'; row.className = 'cr-row is-file';
        var pad = document.createElement('span'); pad.className = 'cr-tog';
        var name = document.createElement('span'); name.textContent = file.name;
        row.appendChild(pad); row.appendChild(icon(FILE)); row.appendChild(name);
        if (file.ok) {
            row.addEventListener('click', function () { choose(row, file); });
        } else {
            row.disabled = true; row.title = tree.getAttribute('data-bad');
        }
        li.appendChild(row);
        return li;
    }

    function folderNode(folder) {
        var li = document.createElement('li');
        var row = document.createElement('button'); row.type = 'button'; row.className = 'cr-row is-folder';
        var tog = document.createElement('span'); tog.className = 'cr-tog'; tog.textContent = folder.children ? '▸' : '';
        var name = document.createElement('span'); name.textContent = folder.name;
        row.appendChild(tog); row.appendChild(icon(FOLDER)); row.appendChild(name);
        row.setAttribute('aria-expanded', 'false');
        li.appendChild(row);
        var sub = null, open = false;
        row.addEventListener('click', function () {
            if (!folder.children) { return; }
            if (sub) { open = !open; sub.hidden = !open; tog.textContent = open ? '▾' : '▸'; row.setAttribute('aria-expanded', open); return; }
            sub = document.createElement('ul');
            var wait = msg(tree.getAttribute('data-loading'));
            sub.appendChild(wait); li.appendChild(sub); open = true; tog.textContent = '▾'; row.setAttribute('aria-expanded', 'true');
            load(folder.path, function (err, d) {
                sub.removeChild(wait);
                if (err) { sub.appendChild(msg(err.message)); return; }
                d.folders.forEach(function (f) { sub.appendChild(folderNode(f)); });
                d.files.forEach(function (f) { sub.appendChild(fileNode(f)); });
                if (!d.folders.length && !d.files.length) { sub.appendChild(msg(tree.getAttribute('data-none'))); }
            });
        });
        return li;
    }

    load('', function (err, d) {
        tree.innerHTML = '';
        if (err) { tree.appendChild(msg(err.message, 'div')); return; }
        var ul = document.createElement('ul');
        var root = folderNode({ name: 'public_html', path: '', children: (d.folders.length + d.files.length) > 0 });
        ul.appendChild(root); tree.appendChild(ul);
        root.querySelector('.cr-row').click();
    });
    document.getElementById('cr-form').addEventListener('submit', function (ev) {
        if (!pathInput.value) { ev.preventDefault(); }
    });
})();
