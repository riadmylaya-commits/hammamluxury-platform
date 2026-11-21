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

    /* Téléphone : une fenêtre (Accepter, Refuser…) ne dépasse jamais l'écran ; elle défile en entier et ses boutons restent
       collés en bas, y compris quand le clavier réduit la hauteur disponible. */
    @media (max-width: 639px) {
        .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(100dvh - 2rem); overflow-y: auto; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-footer { position: sticky; bottom: 0; z-index: 1; background: inherit; padding-top: .75rem; border-end-start-radius: inherit; border-end-end-radius: inherit; }
    }
    /* Zone tactile d'au moins 44 × 44 px pour les boutons, sur téléphone, tablette et écran tactile. */
    @media (max-width: 1023px), (pointer: coarse) {
        .fi-btn, .fi-dropdown-list-item { min-height: 44px; }
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
