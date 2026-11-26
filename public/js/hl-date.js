// Affiche la date choisie au format jj/mm/aaaa, quelle que soit la langue du navigateur.
(function () {
    function show(input) {
        var out = input.parentNode.querySelector('.datefmt');
        if (!out) { return; }
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value || '');
        out.textContent = m ? m[3] + '/' + m[2] + '/' + m[1] : out.getAttribute('data-ph');
        out.classList.toggle('ph', !m);
    }
    function all() { document.querySelectorAll('input[data-datefmt]').forEach(show); }
    ['input', 'change'].forEach(function (e) {
        document.addEventListener(e, function (ev) { if (ev.target.matches && ev.target.matches('input[data-datefmt]')) { show(ev.target); } });
    });
    document.addEventListener('DOMContentLoaded', all);
    window.addEventListener('pageshow', all);
})();
