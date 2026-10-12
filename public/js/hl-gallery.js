/* Galerie plein écran de la fiche établissement : clavier, boutons, balayage tactile, URL #photo-N. */
(function () {
    function safePhotoUrl(value) {
        if (typeof value !== 'string' || !value || /[\x00-\x20\x7f"'<>\\]/.test(value)) return null;
        if (/^https?:\/\//i.test(value)) {
            try {
                var url = new URL(value);
                return /^(https?:)$/.test(url.protocol) && url.hostname && !url.username && !url.password ? value : null;
            } catch (e) { return null; }
        }
        // Relative paths only: no scheme, network-path reference or traversal.
        return /^(?!\/\/)[A-Za-z0-9_./-]+$/.test(value) && !/(^|\/)\.\.?($|\/)/.test(value) ? value : null;
    }

    function init() {
        var root = document.getElementById('hl-gallery');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';
        var photos, t;
        try {
            photos = JSON.parse(root.dataset.photos || '[]');
            t = JSON.parse(root.dataset.i18n || '{}');
        } catch (e) { return; }
        if (!Array.isArray(photos)) return;
        t = t && typeof t === 'object' ? t : {};
        // Keep original indices so the visible gallery and #photo-N agree.
        photos = photos.map(function (p) {
            return { url: safePhotoUrl(p && p.url), caption: p && typeof p.caption === 'string' ? p.caption : '' };
        });
        if (!photos.length) return;

        var box = document.createElement('div');
        box.className = 'lightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.hidden = true;
        function button(className, label, text) {
            var node = document.createElement('button');
            node.type = 'button'; node.className = className;
            node.setAttribute('aria-label', String(label)); node.textContent = text;
            box.appendChild(node);
            return node;
        }
        button('lb-close', t.close || 'Close', '\u00d7');
        button('lb-prev', t.prev || 'Previous', '\u2039');
        var stage = document.createElement('div'); stage.className = 'lb-stage';
        var img = document.createElement('img'); img.alt = '';
        var cap = document.createElement('div'); cap.className = 'lb-cap';
        stage.appendChild(img); stage.appendChild(cap); box.appendChild(stage);
        button('lb-next', t.next || 'Next', '\u203a');
        var count = document.createElement('div'); count.className = 'lb-count'; count.setAttribute('aria-live', 'polite'); box.appendChild(count);
        var thumbs = document.createElement('div'); thumbs.className = 'lb-thumbs'; box.appendChild(thumbs);
        document.body.appendChild(box);

        var idx = 0, lastFocus = null;

        photos.forEach(function (p, i) {
            var b = document.createElement('button');
            b.type = 'button';
            var thumb = document.createElement('img');
            thumb.alt = ''; thumb.loading = 'lazy';
            if (p.url) thumb.src = p.url;
            b.appendChild(thumb);
            b.addEventListener('click', function () { show(i); });
            thumbs.appendChild(b);
        });

        function show(i) {
            idx = (i + photos.length) % photos.length;
            if (photos[idx].url) img.src = photos[idx].url; else img.removeAttribute('src');
            img.alt = photos[idx].caption || '';
            cap.textContent = photos[idx].caption || '';
            cap.hidden = !photos[idx].caption;
            count.textContent = String(t.of || 'Photo :n / :total').replace(':n', idx + 1).replace(':total', photos.length);
            Array.prototype.forEach.call(thumbs.children, function (b, j) {
                b.classList.toggle('on', j === idx);
                if (j === idx && b.scrollIntoView) b.scrollIntoView({ block: 'nearest', inline: 'center' });
            });
            var n = photos[(idx + 1) % photos.length]; if (n && n.url) { var pre = new Image(); pre.src = n.url; }
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
