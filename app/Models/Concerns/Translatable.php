<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\App;

trait Translatable
{
    /** Retourne la valeur traduite d'un champ (`name` → `name_fr` / `name_en`) avec repli sur l'autre langue. */
    public function tr(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?: App::getLocale();
        $value = $this->getAttribute($field.'_'.$locale);
        if ($value !== null && $value !== '') {
            return $value;
        }
        foreach (config('hl.locales') as $fallback) {
            $value = $this->getAttribute($field.'_'.$fallback);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
