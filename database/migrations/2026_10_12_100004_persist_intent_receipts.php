<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Preuve non réversible du jeton déjà validé : reprise après perte du cache.
            $table->string('intent_token_hash', 64)->nullable();
            $table->dateTime('intent_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['intent_token_hash', 'intent_expires_at']);
        });
    }
};
