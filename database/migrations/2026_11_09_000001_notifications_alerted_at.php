<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Date de l'alerte technique envoyée pour un échec définitif : une seule alerte par incident, pas une toutes les 30 minutes. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_notifications', 'billing_notifications'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->timestamp('alerted_at')->nullable()->after('failed_at'));
        }
    }

    public function down(): void
    {
        foreach (['booking_notifications', 'billing_notifications'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('alerted_at'));
        }
    }
};
