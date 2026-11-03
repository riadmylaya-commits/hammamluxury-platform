<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spas.listing_type : partner (défaut) | referenced — un établissement référencé est présenté à partir de ses
 * informations publiques, sans accord signé : demandes décidées par HammamLuxury, aucune réservation
 * instantanée, créneaux d'après les horaires d'ouverture seulement, commission nulle.
 * spas.info_verified_at : date du dernier relevé des informations publiques (affichée sur la fiche référencée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->string('listing_type', 12)->default('partner')->after('managed_by');
            $table->date('info_verified_at')->nullable()->after('listing_type');
        });
    }

    public function down(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->dropColumn(['listing_type', 'info_verified_at']);
        });
    }
};
