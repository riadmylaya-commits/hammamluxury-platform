<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('spa_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
        });

        // Clé technique dérivée de la réservation (sous-totaux par établissement) ; montants, dates et périmètre inchangés.
        DB::table('ledger_entries')->whereNull('spa_id')->whereNotNull('booking_id')->orderBy('id')
            ->chunkById(200, function ($entries) {
                foreach ($entries as $entry) {
                    $spaId = DB::table('bookings')->where('id', $entry->booking_id)->value('spa_id');
                    if ($spaId !== null) {
                        DB::table('ledger_entries')->where('id', $entry->id)->update(['spa_id' => $spaId]);
                    }
                }
            });

        Schema::create('commission_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('spa_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->constrained('ledger_entries')->restrictOnDelete();
            $table->string('period', 7); // période d'origine AAAA-MM
            $table->string('kind', 20); // dispute (avant émission, bloque la ligne) | correction_request (hors fenêtre, n'exclut rien)
            $table->string('reason_code', 40);
            $table->text('comment');
            $table->string('evidence_path', 255)->nullable();
            $table->string('status', 12)->default('open'); // open | accepted | rejected
            // = booking_id tant que la contestation est ouverte, NULL après décision : une seule contestation ouverte par réservation.
            $table->unsignedBigInteger('open_booking_id')->nullable()->unique();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'status']);
            $table->index(['period', 'status']);
        });

        Schema::create('billing_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 120)->unique();
            $table->string('notifiable_type', 60);
            $table->unsignedBigInteger('notifiable_id');
            $table->string('event', 40);
            $table->string('audience', 20);
            $table->string('recipient', 190);
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('planned'); // planned | queued | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notifications');
        Schema::dropIfExists('commission_disputes');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['spa_id']);
            $table->dropColumn('spa_id');
        });
    }
};
