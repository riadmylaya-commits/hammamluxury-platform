<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Intitulé d'étape en anglais ; extra rattaché à une étape (type de ressource) qu'il prolonge au lieu de la dernière. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_steps', fn (Blueprint $table) => $table->string('label_en', 190)->nullable()->after('label'));
        Schema::table('extras', fn (Blueprint $table) => $table->string('applies_to', 20)->nullable()->after('extra_min'));
    }

    public function down(): void
    {
        Schema::table('treatment_steps', fn (Blueprint $table) => $table->dropColumn('label_en'));
        Schema::table('extras', fn (Blueprint $table) => $table->dropColumn('applies_to'));
    }
};
