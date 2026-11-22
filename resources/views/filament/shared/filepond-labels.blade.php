{{-- Libellés courts du composant d'envoi (FilePond) : le message par défaut listant les types MIME est tronqué sur mobile. --}}
<script>
    document.addEventListener('FilePond:loaded', (event) => {
        event.detail.setOptions({
            labelFileTypeNotAllowed: @js(__('partner.upload_type_refused')),
            fileValidateTypeLabelExpectedTypes: @js(__('partner.upload_expected_types')),
            fileValidateTypeLabelExpectedTypesMap: {
                'image/jpeg': 'JPEG',
                'image/png': 'PNG',
                'image/webp': 'WEBP',
                'application/pdf': 'PDF',
            },
            labelMaxFileSizeExceeded: @js(__('partner.upload_too_large')),
            labelMaxFileSize: @js(__('partner.upload_max_size')),
        });
    });
</script>

{{-- Téléphone et tablette : le menu latéral est fermé à l'arrivée sur une page (par défaut il s'ouvre par-dessus le contenu à la première visite). --}}
<script>
    if (window.matchMedia('(max-width: 1023px)').matches) {
        localStorage.setItem('isOpen', 'false');
    }
</script>

<style>
    /* Actions regroupées en un menu sur mobile (tableau des réservations partenaire) */
    @media (min-width: 1280px) { .hl-pending-card { display: none; } }
    @media (max-width: 1279px) { .hl-pending-table { display: none; } }
    @media (min-width: 768px) { .hl-mobile-only { display: none; } }
    @media (max-width: 767px) { .hl-desktop-only { display: none; } }

    /* Téléphone : une fenêtre (Accepter, Refuser…) ne dépasse jamais la zone visible ; elle défile en entier et ses boutons
       restent collés en bas. --hl-vvh / --hl-vvtop suivent la zone réellement visible (script ci-dessous) : quand le clavier
       s'ouvre, Safari et Chrome ne réduisent pas 100dvh, et les boutons resteraient sous le clavier. */
    @media (max-width: 639px) {
        .fi-modal .fixed.overflow-y-auto:has(> div > .fi-modal-window) { top: var(--hl-vvtop, 0px); bottom: auto; height: var(--hl-vvh, 100dvh); }
        .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(var(--hl-vvh, 100dvh) - 2rem); overflow-y: auto; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-footer { position: sticky; bottom: 0; z-index: 1; background: inherit; padding-top: .75rem; border-end-start-radius: inherit; border-end-end-radius: inherit; }
        /* Clavier ouvert : la place est comptée, les marges se resserrent. */
        .hl-keyboard-open .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(var(--hl-vvh, 100dvh) - .5rem); }
        .hl-keyboard-open .fi-modal-window .fi-modal-footer { padding-bottom: .75rem; }
        .hl-keyboard-open .fi-modal .fixed.overflow-y-auto:has(> div > .fi-modal-window) > div { padding: .25rem .5rem; }
        /* Clavier ouvert ou écran peu haut : les deux boutons passent côte à côte pour laisser la place au formulaire. */
        .hl-keyboard-open .fi-modal-window .fi-modal-footer-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); }
    }
    @media (max-width: 639px) and (max-height: 600px) {
        .fi-modal-window .fi-modal-footer-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); }
    }
    /* Téléphone de hauteur courante ou réduite (iPhone SE compris) : la fenêtre se resserre (pictogramme masqué, marges réduites) pour que le formulaire
       entier tienne au-dessus des boutons, y compris avec le conseil du motif « Plus de place ». */
    @media (max-width: 639px) and (max-height: 800px) {
        .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(var(--hl-vvh, 100dvh) - 1rem); }
        .fi-modal .fixed.overflow-y-auto:has(> div > .fi-modal-window) > div { padding: .5rem; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-header > div:has(.fi-modal-icon):not(:has(.fi-modal-heading)) { display: none; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-header { padding-top: 1rem; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-content { padding-block: 1rem; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-content .fi-fo-component-ctn { gap: .75rem; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-footer { padding-bottom: 1rem; }
    }
    /* Zone tactile d'au moins 44 × 44 px pour les boutons, sur téléphone, tablette et écran tactile. */
    @media (max-width: 1023px), (pointer: coarse) {
        .fi-btn, .fi-dropdown-list-item, .fi-modal-window select.fi-select-input { min-height: 44px; }
        .fi-btn { min-width: 44px; }
        .fi-modal-close-btn, a.hl-pending-card { min-height: 44px; min-width: 44px; }
    }
    /* Petit téléphone : les actions passent sous le texte de la carte au lieu de l'écraser. */
    @media (max-width: 479px) {
        tr.fi-ta-row:has(.hl-pending-card) { display: flex; flex-direction: column; }
        tr.fi-ta-row:has(.hl-pending-card) > td { width: 100%; }
        tr.fi-ta-row:has(.hl-pending-card) .fi-ta-actions { justify-content: flex-start; padding-bottom: .75rem; }
    }
</style>

{{-- Zone visible du navigateur (clavier du téléphone déduit) pour dimensionner les fenêtres : voir le style ci-dessus. --}}
<script>
    (() => {
        const viewport = window.visualViewport;
        if (! viewport) {
            return;
        }
        const root = document.documentElement;
        const update = () => {
            root.style.setProperty('--hl-vvh', viewport.height + 'px');
            root.style.setProperty('--hl-vvtop', viewport.offsetTop + 'px');
            root.classList.toggle('hl-keyboard-open', window.innerHeight - viewport.height > 120);
        };
        // Téléphone : le champ que l'on vient de toucher reste visible dans la fenêtre, au-dessus des boutons, dès que le clavier s'ouvre.
        const reveal = () => {
            const field = document.activeElement;
            const modal = field?.closest?.('.fi-modal-window');
            if (! modal || ! field.matches('input, textarea, select') || ! window.matchMedia('(max-width: 639px)').matches) {
                return;
            }
            const footer = modal.querySelector('.fi-modal-footer');
            const top = modal.getBoundingClientRect().top + 8;
            const bottom = (footer ?? modal).getBoundingClientRect()[footer ? 'top' : 'bottom'] - 8;
            const box = field.getBoundingClientRect();
            if (box.bottom > bottom) {
                modal.scrollTop += Math.min(box.bottom - bottom, box.top - top);
            } else if (box.top < top) {
                modal.scrollTop -= top - box.top;
            }
        };
        const refresh = () => {
            update();
            requestAnimationFrame(reveal);
        };
        viewport.addEventListener('resize', refresh);
        viewport.addEventListener('scroll', update);
        document.addEventListener('focusin', () => setTimeout(reveal, 50));
        update();
    })();
</script>
