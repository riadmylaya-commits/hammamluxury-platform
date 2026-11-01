<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fiche Google de l'établissement : lien Google Maps officiel et Place ID, pour un itinéraire sans confusion avec un commerce voisin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->string('google_maps_url', 255)->nullable()->after('lng');
            $table->string('google_place_id', 64)->nullable()->after('google_maps_url');
        });
    }

    public function down(): void
    {
        Schema::table('spas', fn (Blueprint $table) => $table->dropColumn(['google_maps_url', 'google_place_id']));
    }
};
