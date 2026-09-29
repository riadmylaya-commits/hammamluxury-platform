/* Galerie plein écran de la fiche établissement : clavier, boutons, balayage tactile, URL #photo-N. */
(function () {
    function init() {
        var root = document.getElementById('hl-gallery');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';
        var photos = JSON.parse(root.dataset.photos || '[]');
        var t = JSON.parse(root.dataset.i18n || '{}');
        if (!photos.length) return;

        var box = document.createElement('div');
        box.className = 'lightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.hidden = true;
        box.innerHTML =
            '<button type="button" class="lb-close" aria-label="' + (t.close || 'Close') + '">&times;</button>' +
            '<button type="button" class="lb-prev" aria-label="' + (t.prev || 'Previous') + '">&#8249;</button>' +
            '<div class="lb-stage"><img alt=""><div class="lb-cap"></div></div>' +
            '<button type="button" class="lb-next" aria-label="' + (t.next || 'Next') + '">&#8250;</button>' +
            '<div class="lb-count" aria-live="polite"></div>' +
            '<div class="lb-thumbs"></div>';
        document.body.appendChild(box);

        var img = box.querySelector('img'), cap = box.querySelector('.lb-cap'), count = box.querySelector('.lb-count'), thumbs = box.querySelector('.lb-thumbs');
        var idx = 0, lastFocus = null;

        photos.forEach(function (p, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = '<img src="' + p.url + '" alt="" loading="lazy">';
            b.addEventListener('click', function () { show(i); });
            thumbs.appendChild(b);
        });

        function show(i) {
            idx = (i + photos.length) % photos.length;
            img.src = photos[idx].url;
            img.alt = photos[idx].caption || '';
            cap.textContent = photos[idx].caption || '';
            cap.hidden = !photos[idx].caption;
            count.textContent = (t.of || 'Photo :n / :total').replace(':n', idx + 1).replace(':total', photos.length);
            Array.prototype.forEach.call(thumbs.children, function (b, j) {
                b.classList.toggle('on', j === idx);
                if (j === idx && b.scrollIntoView) b.scrollIntoView({ block: 'nearest', inline: 'center' });
            });
            var n = photos[(idx + 1) % photos.length]; if (n) { var pre = new Image(); pre.src = n.url; }
            if (history.replaceState) history.replaceState(null, '', '#photo-' + (idx + 1));
        }

        function open(i) {
            lastFocus = document.activeElement;
            box.hidden = false;
            document.body.classList.add('lb-open');
            show(i);
            box.querySelector('.lb-close').focus();
        }

        function close() {
            box.hidden = true;
            document.body.classList.remove('lb-open');
            if (history.replaceState) history.replaceState(null, '', location.pathname + location.search);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        root.addEventListener('click', function (e) {
            var b = e.target.closest('[data-index]');
            if (b) { e.preventDefault(); open(parseInt(b.dataset.index, 10) || 0); }
        });
        box.querySelector('.lb-close').addEventListener('click', close);
        box.querySelector('.lb-prev').addEventListener('click', function () { show(idx - 1); });
        box.querySelector('.lb-next').addEventListener('click', function () { show(idx + 1); });
        box.addEventListener('click', function (e) { if (e.target === box || e.target.classList.contains('lb-stage')) close(); });
        document.addEventListener('keydown', function (e) {
            if (box.hidden) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') show(idx - 1);
            else if (e.key === 'ArrowRight') show(idx + 1);
        });

        var sx = 0, sy = 0, swiping = false;
        box.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            sx = e.touches[0].clientX; sy = e.touches[0].clientY; swiping = true;
        }, { passive: true });
        box.addEventListener('touchend', function (e) {
            if (!swiping) return;
            swiping = false;
            var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) show(dx < 0 ? idx + 1 : idx - 1);
            else if (dy > 90 && Math.abs(dy) > Math.abs(dx) * 1.5) close();
        }, { passive: true });

        var m = /^#photo-(\d+)$/.exec(location.hash);
        if (m) open(parseInt(m[1], 10) - 1);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    document.addEventListener('livewire:navigated', init);
})();
