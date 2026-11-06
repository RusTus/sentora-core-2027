/* Change Password: show / hide, a strength meter, the match check, and a suggested password
   (made in the browser with crypto.getRandomValues; it is never sent anywhere but in the form).
   The server checks the length and the match again. No outside libraries. */
(function () {
    'use strict';
    var root = document.getElementById('pw');
    if (!root) { return; }
    var $ = function (s) { return root.querySelector(s); };
    var cur = $('#inCurPass'), np = $('#inNewPass'), cp = $('#inConPass'), save = $('.pw-save');
    var meter = $('.pw-meter'), level = $('.pw-level'), why = $('.pw-why'), match = $('.pw-match');
    var MIN = parseInt(root.getAttribute('data-min'), 10) || 8;
    var USER = (root.getAttribute('data-user') || '').toLowerCase();
    var COMMON = ['password', 'passw0rd', '123456', '12345678', 'qwerty', 'letmein', 'welcome', 'sentora', 'admin', 'iloveyou', 'abc123', 'monkey', 'dragon', 'zadmin'];
    var t = function (k) { return root.getAttribute('data-' + k); };

    // show / hide
    Array.prototype.forEach.call(root.querySelectorAll('.pw-eye'), function (b) {
        b.addEventListener('click', function () {
            var i = b.parentNode.querySelector('input'), show = i.type === 'password';
            i.type = show ? 'text' : 'password';
            b.classList.toggle('on', show);
            b.setAttribute('aria-label', show ? t('hide') : t('show'));
            i.focus();
        });
    });

    // how strong: the size of the characters used, times the length, less for known weaknesses
    function strength(p) {
        if (!p) { return { level: 0, why: '' }; }
        if (p.length < MIN) { return { level: 1, why: t('short') }; }
        var low = p.toLowerCase();
        if (USER.length >= 3 && low.indexOf(USER) >= 0) { return { level: 1, why: t('has-user') }; }
        for (var c = 0; c < COMMON.length; c++) { if (low.indexOf(COMMON[c]) >= 0) { return { level: 1, why: t('common') }; } }
        if (/(.)\1\1\1/.test(p) || new Set(p).size <= 3) { return { level: 1, why: t('repeats') }; }
        var pool = (/[a-z]/.test(p) ? 26 : 0) + (/[A-Z]/.test(p) ? 26 : 0) + (/[0-9]/.test(p) ? 10 : 0) + (/[^a-zA-Z0-9]/.test(p) ? 33 : 0);
        var bits = p.length * Math.log2(Math.max(pool, 2));
        return { level: bits < 45 ? 1 : bits < 60 ? 2 : bits < 80 ? 3 : 4, why: '' };
    }
    function check() {
        var s = strength(np.value);
        meter.setAttribute('data-level', s.level);
        level.className = 'pw-level' + (s.level ? ' l' + s.level : '');
        level.textContent = s.level ? t(['', 'weak', 'fair', 'good', 'strong'][s.level]) : '';
        why.textContent = s.why;
        var same = cp.value !== '' && cp.value === np.value;
        match.className = 'pw-match' + (cp.value === '' ? '' : same ? ' ok' : ' bad');
        match.textContent = cp.value === '' ? '' : same ? t('match') : t('nomatch');
        save.disabled = !(cur.value !== '' && np.value.length >= MIN && same);
    }
    [cur, np, cp].forEach(function (i) { i.addEventListener('input', check); });

    // a suggested password: 20 characters, without the ones that look alike (0 O, 1 l I)
    var SET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_!@#%+=?';
    function random(n) {
        var out = '', buf = new Uint32Array(1), limit = Math.floor(4294967296 / SET.length) * SET.length;
        while (out.length < n) {
            crypto.getRandomValues(buf);
            if (buf[0] < limit) { out += SET[buf[0] % SET.length]; }   // no bias toward the first characters
        }
        return out;
    }
    $('.pw-suggest').addEventListener('click', function () {
        var p = random(20);
        np.value = p; cp.value = p;
        $('.pw-suggested code').textContent = p;
        $('.pw-suggested').hidden = false;
        check();
    });
    $('.pw-copy').addEventListener('click', function () {
        var b = this, text = $('.pw-suggested code').textContent;
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject())
            .then(function () { var old = b.textContent; b.textContent = t('copied'); setTimeout(function () { b.textContent = old; }, 1500); })
            .catch(function () {
                var r = document.createRange(); r.selectNodeContents($('.pw-suggested code'));
                var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
            });
    });
    $('.pw-form').addEventListener('submit', function () {
        if (window.Sentora && Sentora.loader) { Sentora.loader.showLoader(); }
    });
    check();
})();
