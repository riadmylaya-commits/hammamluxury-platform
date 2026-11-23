<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dernière minute : option choisie par le partenaire, autorisation de l'Admin, repère sur les demandes à délai de réponse raccourci. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $t) {
            $t->boolean('last_minute_enabled')->default(false);
            $t->boolean('last_minute_allowed')->default(true);
        });
        Schema::table('bookings', fn (Blueprint $t) => $t->boolean('last_minute')->default(false));
    }

    public function down(): void
    {
        Schema::table('spas', fn (Blueprint $t) => $t->dropColumn(['last_minute_enabled', 'last_minute_allowed']));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('last_minute'));
    }
};
