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
    @media (min-width: 768px) { .hl-mobile-only { display: none; } }
    @media (max-width: 767px) { .hl-desktop-only { display: none; } }
</style>
