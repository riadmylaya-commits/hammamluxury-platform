<?php

use App\Domain\Policy\CancellationPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session 3 — politique d'annulation choisie par le partenaire (établissement, surcharge par prestation),
 * tarif non remboursable (% de réduction ≥ 10), conditions figées sur chaque réservation, frais d'annulation tardive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellation_hours')->nullable()->after('price_group');
            $table->unsignedTinyInteger('nr_discount_pct')->nullable()->after('cancellation_hours');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('rate_type', 20)->default('standard')->after('total'); // standard | non_refundable
            $table->unsignedSmallInteger('cancellation_hours')->nullable()->after('rate_type');
            $table->decimal('standard_total', 10, 2)->nullable()->after('cancellation_hours');
            $table->unsignedTinyInteger('nr_discount_pct')->nullable()->after('standard_total');
            $table->decimal('cancel_fee', 10, 2)->nullable()->after('nr_discount_pct');
        });

        // Les délais libres existants sont ramenés à la liste fermée (valeur la plus proche).
        foreach (DB::table('spas')->select('id', 'cancellation_hours')->get() as $spa) {
            $h = CancellationPolicy::nearest((int) $spa->cancellation_hours);
            if ($h !== (int) $spa->cancellation_hours) {
                DB::table('spas')->where('id', $spa->id)->update(['cancellation_hours' => $h]);
            }
        }
        DB::table('bookings')->whereNull('cancellation_hours')->update(['cancellation_hours' => DB::raw('(select s.cancellation_hours from spas s where s.id = bookings.spa_id)')]);
        DB::table('bookings')->whereNull('standard_total')->update(['standard_total' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['rate_type', 'cancellation_hours', 'standard_total', 'nr_discount_pct', 'cancel_fee']));
        Schema::table('treatments', fn (Blueprint $table) => $table->dropColumn(['cancellation_hours', 'nr_discount_pct']));
    }
};
