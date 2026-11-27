{{-- Tableaux de facturation en cartes sous 768 px : libellé au-dessus de la valeur, deux colonnes, champs longs en pleine largeur. --}}
<style>
    .hl-rt td button, .hl-rt td a { white-space: nowrap; }
    .hl-rt td.text-right { white-space: nowrap; }
    .hl-rt td.text-right .block { white-space: normal; }
    @media (max-width: 767px) {
        .hl-rt thead { display: none; }
        .hl-rt, .hl-rt tbody { display: block; width: 100%; }
        .hl-rt tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1rem; row-gap: .5rem; padding: .85rem 0; }
        .hl-rt td { display: block; padding: 0 !important; text-align: left !important; overflow-wrap: break-word; line-height: 1.45; }
        .hl-rt td::before { content: attr(data-label); display: block; font-size: .75rem; line-height: 1.3; color: #6b7280; font-family: inherit; font-weight: 400; white-space: normal; }
        .hl-rt td.hl-wide { grid-column: 1 / -1; }
        .hl-rt td:empty { display: none; }
    }
</style>
