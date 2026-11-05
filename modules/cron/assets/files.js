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

    function load(path, done) {
        fetch(url + '?p=' + encodeURIComponent(path), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }); })
            .then(function (d) { done(null, d); })
            .catch(function (e) { done(e); });
    }

    function choose(row, file) {
        if (selectedRow) { selectedRow.classList.remove('pd-selected'); }
        selectedRow = row; row.classList.add('pd-selected');
        chosen.textContent = 'public_html/' + file.path;
        chosen.classList.remove('text-muted');
        pathInput.value = file.path;
        submit.disabled = false;
    }

    function fileNode(file) {
        var li = document.createElement('li');
        var row = document.createElement('span'); row.className = 'pd-row';
        var pad = document.createElement('span'); pad.className = 'pd-toggle';
        var icon = document.createElement('span'); icon.className = 'icon-article-alt'; icon.style.color = '#5b7fa6';
        var name = document.createElement('span'); name.textContent = file.name;
        row.appendChild(pad); row.appendChild(icon); row.appendChild(name);
        if (file.ok) {
            row.addEventListener('click', function () { choose(row, file); });
        } else {
            row.style.opacity = '0.5'; row.style.cursor = 'not-allowed';
            row.title = 'Can not be scheduled: use only letters, digits, dots, dashes and underscores in its folder and file names';
        }
        li.appendChild(row);
        return li;
    }

    function folderNode(folder) {
        var li = document.createElement('li');
        var row = document.createElement('span'); row.className = 'pd-row';
        var toggle = document.createElement('span'); toggle.className = 'pd-toggle';
        toggle.textContent = folder.children ? '\u25B8' : '';
        var icon = document.createElement('span'); icon.className = 'pd-folder icon-folder';
        var name = document.createElement('span'); name.textContent = folder.name;
        row.appendChild(toggle); row.appendChild(icon); row.appendChild(name);
        li.appendChild(row);
        var sub = null, open = false;
        function expand() {
            if (!folder.children) { return; }
            if (sub) { open = !open; sub.style.display = open ? '' : 'none'; toggle.textContent = open ? '\u25BE' : '\u25B8'; return; }
            sub = document.createElement('ul');
            var wait = document.createElement('li'); wait.className = 'pd-loading'; wait.textContent = 'Loading...';
            sub.appendChild(wait); li.appendChild(sub); open = true; toggle.textContent = '\u25BE';
            load(folder.path, function (err, d) {
                sub.removeChild(wait);
                if (err) { var e = document.createElement('li'); e.className = 'pd-empty'; e.textContent = err.message; sub.appendChild(e); return; }
                d.folders.forEach(function (f) { sub.appendChild(folderNode(f)); });
                d.files.forEach(function (f) { sub.appendChild(fileNode(f)); });
                if (!d.folders.length && !d.files.length) { var n = document.createElement('li'); n.className = 'pd-empty'; n.textContent = 'no PHP files here'; sub.appendChild(n); }
            });
        }
        toggle.addEventListener('click', function (ev) { ev.stopPropagation(); expand(); });
        row.addEventListener('click', expand);
        return li;
    }

    load('', function (err, d) {
        tree.innerHTML = '';
        if (err) { var e = document.createElement('div'); e.className = 'pd-empty'; e.textContent = err.message; tree.appendChild(e); return; }
        var ul = document.createElement('ul');
        var root = folderNode({ name: 'public_html', path: '', children: (d.folders.length + d.files.length) > 0 });
        ul.appendChild(root); tree.appendChild(ul);
        root.querySelector('.pd-toggle').click();
    });
    document.getElementById('cr-form').addEventListener('submit', function (ev) {
        if (!pathInput.value) { ev.preventDefault(); }
    });
})();
