<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lot 4 — refus structurés des demandes par l'établissement (motif, explication, auteur, disponibilité moteur au moment du refus). Additif. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_declines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('spa_id')->constrained()->restrictOnDelete();
            $table->foreignId('declined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor', 12)->default('partner'); // partner | admin
            $table->string('reason', 32); // full | closed | staff_unavailable | treatment_unavailable | client_request | other
            $table->text('note')->nullable(); // explication interne (partenaire → admin), jamais montrée au client
            $table->dateTime('start_at'); // créneau refusé (copie pour le suivi même si la réservation évolue)
            $table->unsignedSmallInteger('party')->default(1);
            $table->boolean('engine_available')->nullable(); // le moteur avait-il encore de la place pour ce créneau au moment du refus ?
            $table->timestamps();
            $table->index(['spa_id', 'reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_declines');
    }
};
