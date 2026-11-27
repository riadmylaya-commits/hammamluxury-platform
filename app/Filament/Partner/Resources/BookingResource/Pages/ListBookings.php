<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Filament\Partner\Resources\BookingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('partner.calendar_view'))->icon('heroicon-o-calendar-days')->color('gray')
                ->url(BookingResource::getUrl('calendar')),
        ];
    }

    // « Trier par » et le tri par en-tête de colonne s'excluent : le dernier choisi l'emporte, y compris sur les cartes
    // (téléphone, tablette) où les en-têtes sont masqués et où un tri hérité d'un lien ne pourrait pas être retiré.
    public function updatedTableFilters(): void
    {
        if (filled($this->tableFilters['order']['value'] ?? null) && $this->tableSortColumn) {
            $this->tableSortColumn = $this->tableSortDirection = null;
            $this->updatedTableSortColumn();
        }
        parent::updatedTableFilters();
    }

    public function sortTable(?string $column = null, ?string $direction = null): void
    {
        parent::sortTable($column, $direction);
        if ($this->tableSortColumn && filled($this->tableFilters['order']['value'] ?? null)) {
            $this->tableFilters['order']['value'] = null;
            $this->handleTableFilterUpdates();
        }
    }
}
