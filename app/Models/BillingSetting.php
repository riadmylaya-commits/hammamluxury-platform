<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Paramètre de facturation saisi dans l'Admin (clé/valeur). Écriture uniquement via BillingSettings::set(), journalisée. */
class BillingSetting extends Model
{
    protected $guarded = [];
}
