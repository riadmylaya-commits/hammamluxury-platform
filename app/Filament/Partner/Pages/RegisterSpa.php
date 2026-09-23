<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Forms\SpaForm;
use App\Models\Spa;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\RegisterTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegisterSpa extends RegisterTenant
{
    public static function getLabel(): string
    {
        return __('partner.add_spa');
    }

    public function form(Form $form): Form
    {
        return $form->schema(SpaForm::identity());
    }

    protected function handleRegistration(array $data): Model
    {
        $partner = Filament::auth()->user()->partner;
        abort_unless($partner, 403);

        $data['slug'] = self::uniqueSlug($data['name'], $data['city']);
        $data['status'] = 'draft';

        return $partner->spas()->create($data);
    }

    public static function uniqueSlug(string $name, string $city): string
    {
        $base = Str::slug($name.' '.$city);
        $slug = $base;
        for ($i = 2; Spa::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
