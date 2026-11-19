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

<style>
    /* Actions regroupées en un menu sur mobile (tableau des réservations partenaire) */
    @media (min-width: 1280px) { .hl-pending-card { display: none; } }
    @media (max-width: 1279px) { .hl-pending-table { display: none; } }
    @media (min-width: 768px) { .hl-mobile-only { display: none; } }
    @media (max-width: 767px) { .hl-desktop-only { display: none; } }

    /* Téléphone : une fenêtre (Accepter, Refuser…) ne dépasse jamais l'écran ; son contenu défile, ses boutons restent visibles. */
    @media (max-width: 639px) {
        .fi-modal-window:not(.fi-modal-slide-over-window) { max-height: calc(100dvh - 2rem); }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-content { min-height: 0; overflow-y: auto; }
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-header,
        .fi-modal-window:not(.fi-modal-slide-over-window) .fi-modal-footer { flex-shrink: 0; }
    }
    /* Zone tactile d'au moins 44 × 44 px pour les boutons, sur téléphone, tablette et écran tactile. */
    @media (max-width: 1023px), (pointer: coarse) {
        .fi-btn, .fi-dropdown-list-item { min-height: 44px; }
        .fi-btn { min-width: 44px; }
        .fi-modal-close-btn, .hl-pending-card .fi-icon-btn { min-height: 44px; min-width: 44px; }
    }
</style>
