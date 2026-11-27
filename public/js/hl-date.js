// Affiche la date choisie au format jj/mm/aaaa, quelle que soit la langue du navigateur.
(function () {
    function show(input) {
        var out = input.parentNode.querySelector('.datefmt');
        if (!out) { return; }
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value || '');
        out.textContent = m ? m[3] + '/' + m[2] + '/' + m[1] : out.getAttribute('data-ph');
        out.classList.toggle('ph', !m);
    }
    // La date choisie est conservée pendant la navigation (rechargement compris), tant qu'elle n'est pas passée.
    var KEY = 'hl-date';
    function keep(input) {
        try { if (input.value) { sessionStorage.setItem(KEY, input.value); } else { sessionStorage.removeItem(KEY); } } catch (e) {}
    }
    function restore(input) {
        var v = null;
        try { v = sessionStorage.getItem(KEY); } catch (e) {}
        if (input.value || !v || (input.min && v < input.min)) { return; }
        input.value = v;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // Calendrier dans la langue du site (et non celle du téléphone) : remplace le sélecteur natif.
    var lang = (document.documentElement.lang || 'fr').slice(0, 2);
    var open = null;
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function close() {
        if (!open) { return; }
        open.pop.remove(); open.btn.setAttribute('aria-expanded', 'false'); open = null;
    }
    function set(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function draw(input, pop, y, m) {
        var today = new Date();
        var min = input.getAttribute('min') || iso(today.getFullYear(), today.getMonth(), today.getDate());
        var title = new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric' }).format(new Date(y, m, 1));
        pop.textContent = '';
        var head = el('div', { 'class': 'dp-head' });
        var prev = el('button', { type: 'button', 'aria-label': input.getAttribute('data-prev') }, '‹');
        var next = el('button', { type: 'button', 'aria-label': input.getAttribute('data-next') }, '›');
        prev.disabled = iso(y, m, 1) <= min;
        prev.addEventListener('click', function () { draw(input, pop, m ? y : y - 1, m ? m - 1 : 11); });
        next.addEventListener('click', function () { draw(input, pop, m === 11 ? y + 1 : y, m === 11 ? 0 : m + 1); });
        head.appendChild(prev); head.appendChild(el('strong', { 'aria-live': 'polite' }, title)); head.appendChild(next);
        pop.appendChild(head);
        var grid = el('div', { 'class': 'dp-grid' });
        var wd = new Intl.DateTimeFormat(lang, { weekday: 'short' });
        for (var i = 0; i < 7; i++) { grid.appendChild(el('span', { 'class': 'dp-wd' }, wd.format(new Date(2024, 0, 1 + i)))); }
        var lead = (new Date(y, m, 1).getDay() + 6) % 7;
        for (i = 0; i < lead; i++) { grid.appendChild(el('span')); }
        var days = new Date(y, m + 1, 0).getDate();
        var full = new Intl.DateTimeFormat(lang, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        for (var d = 1; d <= days; d++) {
            var v = iso(y, m, d);
            var b = el('button', { type: 'button', 'data-v': v, 'aria-label': full.format(new Date(y, m, d)) }, String(d));
            if (v < min) { b.disabled = true; }
            if (v === input.value) { b.className = 'on'; b.setAttribute('aria-pressed', 'true'); }
            grid.appendChild(b);
        }
        grid.addEventListener('click', function (ev) {
            var v = ev.target.getAttribute && ev.target.getAttribute('data-v');
            if (v) { set(input, v); close(); }
        });
        pop.appendChild(grid);
        if (input.value) {
            var clear = el('button', { type: 'button', 'class': 'dp-clear' }, input.getAttribute('data-clear'));
            clear.addEventListener('click', function () { set(input, ''); close(); });
            pop.appendChild(clear);
        }
    }
    function enhance(input) {
        var wrap = input.parentNode;
        if (wrap.classList.contains('dp') || !window.Intl) { return; }
        wrap.classList.add('dp');
        var label = input.getAttribute('aria-label');
        input.type = 'text'; input.readOnly = true; input.tabIndex = -1; input.setAttribute('aria-hidden', 'true');
        var btn = el('button', { type: 'button', 'class': 'dp-btn', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-label': label });
        wrap.appendChild(btn);
        btn.addEventListener('click', function () {
            if (open && open.btn === btn) { close(); return; }
            close();
            var pop = el('div', { 'class': 'dp-pop', role: 'dialog', 'aria-label': label });
            var m = /^(\d{4})-(\d{2})/.exec(input.value || input.getAttribute('min') || '');
            var now = new Date();
            draw(input, pop, m ? +m[1] : now.getFullYear(), m ? +m[2] - 1 : now.getMonth());
            wrap.appendChild(pop); btn.setAttribute('aria-expanded', 'true');
            open = { pop: pop, btn: btn, wrap: wrap };
        });
    }
    document.addEventListener('click', function (ev) { if (open && !open.wrap.contains(ev.target)) { close(); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && open) { var b = open.btn; close(); b.focus(); } });
    function all() {
        document.querySelectorAll('input[data-datefmt]').forEach(function (i) { enhance(i); restore(i); show(i); });
    }
    ['input', 'change'].forEach(function (e) {
        document.addEventListener(e, function (ev) { if (ev.target.matches && ev.target.matches('input[data-datefmt]')) { show(ev.target); keep(ev.target); } });
    });
    document.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('[data-date-clear]')) { try { sessionStorage.removeItem(KEY); } catch (e) {} }
    });
    document.addEventListener('DOMContentLoaded', all);
    window.addEventListener('pageshow', all);
})();
