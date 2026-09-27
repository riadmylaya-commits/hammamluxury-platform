<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Étapes B et C : demandes d'annulation partenaire (circuit client / admin) et messagerie interne liée à la réservation. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 12)->default('pending'); // pending | accepted | refused | closed
            $table->timestamp('client_notified_at')->nullable();
            $table->string('client_response', 12)->nullable(); // accepted | refused
            $table->timestamp('client_responded_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['spa_id', 'status']);
            $table->index(['booking_id', 'status']);
        });

        Schema::create('booking_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->string('sender', 8); // client | partner | admin
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'created_at']);
            $table->index(['spa_id', 'sender', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_messages');
        Schema::dropIfExists('cancellation_requests');
    }
};
