{{-- Tableaux de facturation en cartes libellé / valeur sous 768 px. --}}
<style>
    @media (max-width: 767px) {
        .hl-rt thead { display: none; }
        .hl-rt, .hl-rt tbody { display: block; width: 100%; }
        .hl-rt tr { display: block; padding: .5rem 0; }
        .hl-rt td { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; padding: .2rem 0 !important; text-align: right; overflow-wrap: anywhere; }
        .hl-rt td::before { content: attr(data-label); flex: none; max-width: 45%; text-align: left; color: #6b7280; font-family: inherit; font-weight: 400; }
        .hl-rt td:empty { display: none; }
    }
</style>
