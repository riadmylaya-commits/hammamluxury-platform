<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session A — intégrité des annulations : motif structuré + justificatif, proposition de nouvelle date,
 * no-show client avec frais appliqués / abandonnés, no-show partenaire, signalements client, révélation des coordonnées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('status', 20)->default('waiting')->change();
            $table->decimal('no_show_fee', 10, 2)->nullable()->after('payment_status');
            $table->boolean('no_show_fee_waived')->default(false)->after('no_show_fee');
            $table->timestamp('no_show_at')->nullable()->after('no_show_fee_waived');
            $table->timestamp('contact_revealed_at')->nullable()->after('no_show_at');
        });

        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->string('reason_code', 40)->nullable()->after('reason');
            $table->string('evidence_path')->nullable()->after('reason_code');
            $table->timestamp('proposed_start_at')->nullable()->after('decision_note');
            $table->text('proposal_note')->nullable()->after('proposed_start_at');
            $table->timestamp('proposed_at')->nullable()->after('proposal_note');
            $table->string('proposal_response', 12)->nullable()->after('proposed_at'); // accepted | refused
            $table->timestamp('proposal_responded_at')->nullable()->after('proposal_response');
        });

        Schema::create('client_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('spa_id')->constrained()->restrictOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_key', 64)->index();
            $table->string('client_phone', 32)->nullable();
            $table->string('client_email')->nullable();
            $table->string('category', 32);
            $table->text('description');
            $table->string('evidence_path')->nullable();
            $table->string('status', 12)->default('open'); // open | reviewed | dismissed
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['spa_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_incidents');
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropColumn(['reason_code', 'evidence_path', 'proposed_start_at', 'proposal_note', 'proposed_at', 'proposal_response', 'proposal_responded_at']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['no_show_fee', 'no_show_fee_waived', 'no_show_at', 'contact_revealed_at']);
            $table->string('status', 12)->default('waiting')->change();
        });
    }
};
