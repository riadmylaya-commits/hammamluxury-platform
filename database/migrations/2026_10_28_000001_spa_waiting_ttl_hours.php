<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Délai d'attente des demandes propre à l'établissement (vide = valeur globale hl.waiting_ttl_hours). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->decimal('waiting_ttl_hours', 4, 1)->nullable()->after('cancellation_hours');
        });
    }

    public function down(): void
    {
        Schema::table('spas', fn (Blueprint $table) => $table->dropColumn('waiting_ttl_hours'));
    }
};
