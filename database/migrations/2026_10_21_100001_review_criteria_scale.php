<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Échelle des critères détaillés mémorisée par avis : 3 pour les avis déjà notés, 5 pour les nouveaux (aucune valeur convertie). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('criteria_scale')->default(5)->after('criteria');
        });

        DB::table('reviews')->whereNotNull('criteria')->update(['criteria_scale' => 3]);
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('criteria_scale');
        });
    }
};
