<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Périmètre de facturation d'une écriture : null = non qualifiée, legacy = antérieure au démarrage (jamais facturée),
 * billable = facturable. Aucun rétro-remplissage ici : la qualification se fait par hl:billing-mark-legacy, journalisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('billing_scope', 10)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['billing_scope']);
            $table->dropColumn('billing_scope');
        });
    }
};
