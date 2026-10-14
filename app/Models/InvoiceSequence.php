<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Compteur annuel par type de document ; incrémenté uniquement sous verrou de ligne, dans la transaction d'émission. */
class InvoiceSequence extends Model
{
    protected $guarded = [];

    /** Numéro suivant pour l'année et le type donnés (à appeler dans une transaction). */
    public static function next(int $year, string $kind): int
    {
        DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'kind' => $kind, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('invoice_sequences')->where('year', $year)->where('kind', $kind)->lockForUpdate()->first();
        $next = (int) $row->last_number + 1;
        DB::table('invoice_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }
}
