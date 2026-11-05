/* Protected Directories: a folder tree of the account's public_html, each folder
   loaded when it is opened (code/folders.php). Names are inserted as text, never HTML. */
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

    function load(path, done) {
        fetch(url + '?p=' + encodeURIComponent(path), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }); })
            .then(function (d) { done(null, d); })
            .catch(function (e) { done(e); });
    }

    function select(row, folder) {
        if (selectedRow) { selectedRow.classList.remove('pd-selected'); }
        selectedRow = row; row.classList.add('pd-selected');
        chosen.textContent = 'public_html/' + folder.path;
        chosen.classList.remove('text-muted');
        pathInput.value = folder.path;
        note.style.display = folder.protected ? '' : 'none';
        submit.disabled = !!folder.protected;
    }

    function node(folder) {
        var li = document.createElement('li');
        var row = document.createElement('span'); row.className = 'pd-row';
        var toggle = document.createElement('span'); toggle.className = 'pd-toggle';
        toggle.textContent = folder.children ? '\u25B8' : '';
        var icon = document.createElement('span'); icon.className = 'pd-folder icon-folder';
        var name = document.createElement('span'); name.textContent = folder.name;
        row.appendChild(toggle); row.appendChild(icon); row.appendChild(name);
        if (folder.protected) { var lock = document.createElement('span'); lock.className = 'pd-lock icon-lock-alt'; lock.title = 'already protected'; row.appendChild(lock); }
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
                d.folders.forEach(function (f) { sub.appendChild(node(f)); });
                if (!d.folders.length) { var n = document.createElement('li'); n.className = 'pd-empty'; n.textContent = 'no subfolders'; sub.appendChild(n); }
            });
        }
        toggle.addEventListener('click', function (ev) { ev.stopPropagation(); expand(); });
        row.addEventListener('click', function () { select(row, folder); });
        row.addEventListener('dblclick', function () { expand(); });
        return li;
    }

    load('', function (err, d) {
        tree.innerHTML = '';
        if (err) { var e = document.createElement('div'); e.className = 'pd-empty'; e.textContent = err.message; tree.appendChild(e); return; }
        var ul = document.createElement('ul');
        var root = node({ name: 'public_html', path: '', children: d.folders.length > 0, protected: d.protected });
        ul.appendChild(root); tree.appendChild(ul);
        // open the top level straight away
        root.querySelector('.pd-toggle').click();
    });
    document.getElementById('pd-form').addEventListener('submit', function (ev) {
        if (!pathInput.value && pathInput.value !== '') { ev.preventDefault(); }
    });
})();
