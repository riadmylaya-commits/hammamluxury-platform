<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F2 — brouillons et émission de factures. Migration purement additive : aucune table existante n'est modifiée en valeur ;
 * le grand livre (`ledger_entries`) n'est pas touché, l'unicité « une écriture = une ligne de facture » est portée par
 * `invoice_lines.ledger_entry_id` (unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 20); // invoice (F2) | credit_note (F4) | receipt (F3)
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['year', 'kind']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique(); // attribué à l'émission uniquement
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->string('period', 7); // mois facturé AAAA-MM
            $table->string('status', 20)->default('draft'); // draft | issued (F3 : partially_paid | paid | overdue ; F4 : cancelled)
            $table->string('currency', 3);
            $table->decimal('subtotal_ht', 12, 2)->default(0);
            $table->string('vat_mode', 20)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->timestamp('drafted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('issuer_snapshot')->nullable(); // identité HammamLuxury figée à l'émission
            $table->json('partner_snapshot')->nullable(); // identité légale du partenaire figée à l'émission
            $table->string('pdf_status', 12)->nullable(); // pending | ready | failed (null en brouillon)
            $table->string('pdf_path', 255)->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->unsignedSmallInteger('pdf_attempts')->default(0);
            $table->string('pdf_error', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'period']);
            $table->index(['status', 'due_at']);
            $table->index(['period', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->foreignId('spa_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('booking_reference', 20)->nullable();
            $table->timestamp('service_at')->nullable();
            $table->string('entry_type', 30);
            $table->string('description', 190);
            $table->decimal('base_amount', 12, 2)->nullable();
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('origin_period', 7);
            $table->boolean('carried')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['invoice_id', 'spa_id']);
        });

        Schema::table('commission_disputes', function (Blueprint $table) {
            // Suivi d'une contestation acceptée : pending = correction financière à régulariser, settled = régularisée.
            $table->string('correction_status', 12)->nullable()->after('decision_note');
            $table->foreignId('correction_entry_id')->nullable()->after('correction_status')->constrained('ledger_entries')->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->after('correction_entry_id')->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by');
            $table->text('settlement_note')->nullable()->after('settled_at');
            $table->index(['correction_status', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_disputes', function (Blueprint $table) {
            $table->dropIndex(['correction_status', 'partner_id']);
            $table->dropConstrainedForeignId('correction_entry_id');
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['correction_status', 'settled_at', 'settlement_note']);
        });
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
