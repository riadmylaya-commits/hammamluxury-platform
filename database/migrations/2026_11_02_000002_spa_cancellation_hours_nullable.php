<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conditions d'annulation d'un établissement non confirmées : `null` (affiché « à confirmer ») au lieu d'un délai par défaut inventé. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', fn (Blueprint $table) => $table->unsignedSmallInteger('cancellation_hours')->nullable()->default(null)->change());
    }

    public function down(): void
    {
        Schema::table('spas', fn (Blueprint $table) => $table->unsignedSmallInteger('cancellation_hours')->nullable(false)->default(24)->change());
    }
};
