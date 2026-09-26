<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Autorisation administrative d'exploitation (numéro déclaré, jamais publié). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->string('license_number', 60)->nullable()->after('practical_info');
            $table->string('license_authority', 20)->nullable()->after('license_number'); // commune | arrondissement | other
            $table->string('license_authority_other', 120)->nullable()->after('license_authority');
        });
    }

    public function down(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->dropColumn(['license_number', 'license_authority', 'license_authority_other']);
        });
    }
};
