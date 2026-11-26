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
    function all() {
        document.querySelectorAll('input[data-datefmt]').forEach(function (i) { restore(i); show(i); });
    }
    ['input', 'change'].forEach(function (e) {
        document.addEventListener(e, function (ev) { if (ev.target.matches && ev.target.matches('input[data-datefmt]')) { show(ev.target); keep(ev.target); } });
    });
    document.addEventListener('DOMContentLoaded', all);
    window.addEventListener('pageshow', all);
})();
