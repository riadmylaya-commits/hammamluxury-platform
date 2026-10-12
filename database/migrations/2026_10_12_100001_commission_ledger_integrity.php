<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contrôle avant toute DDL : l'audit des doublons exige une décision comptable explicite.
        $duplicates = DB::table('ledger_entries')->where('type', 'commission')->whereNotNull('booking_id')
            ->select('booking_id')->groupBy('booking_id')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('booking_id');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate legacy commission entries for booking IDs: '.$duplicates->implode(', ')
                .'. Stop deployment and reconcile manually; this migration never deletes financial history.');
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('corrects_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('actor', 12)->nullable();
            $table->string('booking_reference', 12)->nullable();
            $table->index(['booking_id', 'type']);
            $table->dropForeign(['partner_id']);
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
        });

        // Seule la clé technique est ajoutée aux écritures existantes ; montants et dates sont conservés.
        DB::table('ledger_entries')->where('type', 'commission')->whereNotNull('booking_id')->orderBy('id')
            ->chunkById(200, function ($entries) {
                foreach ($entries as $entry) {
                    DB::table('ledger_entries')->where('id', $entry->id)
                        ->update([
                            'idempotency_key' => 'commission:booking:'.$entry->booking_id.':after:initial',
                            'booking_reference' => DB::table('bookings')->where('id', $entry->booking_id)->value('reference'),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Revenir au code qui supprime les commissions détruirait le sens des corrections liées.
        if (DB::table('ledger_entries')->whereIn('type', ['commission_reversal', 'commission_adjust'])->exists()) {
            throw new RuntimeException('Cannot roll back an append-only commission ledger containing corrections.');
        }
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['corrects_entry_id']);
            $table->dropForeign(['partner_id']);
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['booking_id', 'type']);
            $table->dropColumn(['idempotency_key', 'corrects_entry_id', 'actor', 'booking_reference']);
        });
    }
};
