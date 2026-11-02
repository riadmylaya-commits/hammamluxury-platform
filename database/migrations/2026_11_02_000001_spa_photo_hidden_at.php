<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Photo d'établissement isolée (provenance ou droits incertains) : conservée mais jamais servie au public. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spa_photos', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('is_cover');
            $table->string('hidden_reason', 190)->nullable()->after('hidden_at');
        });
    }

    public function down(): void
    {
        Schema::table('spa_photos', fn (Blueprint $table) => $table->dropColumn(['hidden_at', 'hidden_reason']));
    }
};
