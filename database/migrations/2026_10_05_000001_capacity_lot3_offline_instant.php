<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lot 3 — additif : origine des réservations (en ligne / saisie partenaire) et réservation instantanée par établissement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 12)->default('online')->after('status'); // online | partner
            $table->string('channel', 20)->nullable()->after('source'); // phone | whatsapp | walk_in | email | other
            $table->foreignId('created_by_user_id')->nullable()->after('channel')->constrained('users')->nullOnDelete();
            $table->index(['spa_id', 'source']);
        });
        Schema::table('spas', function (Blueprint $table) {
            $table->boolean('instant_booking')->default(false)->after('status');
            $table->dateTime('instant_booking_at')->nullable()->after('instant_booking');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['spa_id', 'source']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['source', 'channel']);
        });
        Schema::table('spas', fn (Blueprint $table) => $table->dropColumn(['instant_booking', 'instant_booking_at']));
    }
};
