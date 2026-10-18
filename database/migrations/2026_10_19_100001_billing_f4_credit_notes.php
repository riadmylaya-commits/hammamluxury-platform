<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F4 — avoirs, corrections post-émission, crédits partenaires. Migration purement additive : aucune valeur existante
 * n'est modifiée, le grand livre n'est pas touché. Un avoir émis est immuable (observer) ; une erreur se corrige par un avoir
 * d'annulation lié (`reverses_credit_note_id`), jamais par modification ni suppression. Une écriture de correction ne peut
 * alimenter qu'un seul avoir (`credit_note_lines.ledger_entry_id` unique). Un crédit partenaire ne se consomme qu'une fois
 * (applications liées, clé d'idempotence unique) ; un remboursement est déclaratif (date, mode, référence, note).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique(); // HL-A-AAAA-NNNNN, attribué à l'émission uniquement
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft | issued
            $table->string('currency', 3);
            $table->decimal('subtotal_ht', 12, 2)->default(0);
            $table->string('vat_mode', 20)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0); // > 0 pour un avoir, < 0 pour un avoir d'annulation
            $table->unsignedInteger('lines_count')->default(0);
            $table->string('reason', 500);
            $table->foreignId('dispute_id')->nullable()->constrained('commission_disputes')->restrictOnDelete();
            $table->foreignId('reverses_credit_note_id')->nullable()->unique()->constrained('credit_notes')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('issuer_snapshot')->nullable();
            $table->json('partner_snapshot')->nullable();
            $table->decimal('invoice_balance_after', 12, 2)->nullable(); // solde de la facture après émission (audit)
            $table->string('pdf_status', 12)->nullable(); // pending | ready | failed
            $table->string('pdf_path', 255)->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->unsignedSmallInteger('pdf_attempts')->default(0);
            $table->string('pdf_error', 500)->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'status']);
            $table->index(['partner_id', 'issued_at']);
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_entry_id')->unique()->constrained('ledger_entries')->restrictOnDelete(); // une correction = un seul avoir
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('booking_reference', 20)->nullable();
            $table->foreignId('spa_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entry_type', 30);
            $table->string('description', 190);
            $table->decimal('amount', 12, 2); // montant crédité (positif)
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('partner_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->string('source', 20); // credit_note | overpayment
            $table->foreignId('credit_note_id')->nullable()->unique()->constrained('credit_notes')->restrictOnDelete();
            $table->foreignId('overpayment_id')->nullable()->unique()->constrained('invoice_overpayments')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('currency', 3);
            $table->string('status', 12)->default('open'); // open | applied | refunded | reversed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'status']);
        });

        Schema::create('partner_credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_credit_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12); // apply (imputation sur une facture) | refund (remboursement déclaratif)
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_payment_id')->nullable()->unique()->constrained('invoice_payments')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('idempotency_key', 64)->unique();
            $table->date('refund_on')->nullable();
            $table->string('refund_method', 20)->nullable(); // bank_transfer | cash | other
            $table->string('refund_reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->decimal('credit_remaining_after', 12, 2); // reste du crédit après cette opération (audit)
            $table->timestamps();
            $table->index(['partner_credit_id', 'kind']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('credited_amount', 12, 2)->default(0)->after('paid_amount'); // somme des avoirs émis (annulations déduites)
            $table->timestamp('cancelled_at')->nullable()->after('paid_at');
        });

        Schema::table('invoice_overpayments', function (Blueprint $table) {
            $table->foreignId('partner_credit_id')->nullable()->after('decision_note')->constrained('partner_credits')->nullOnDelete();
        });

        Schema::table('commission_disputes', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('ledger_entry_id')->constrained()->nullOnDelete();
            $table->foreignId('credit_note_id')->nullable()->after('settlement_note')->constrained('credit_notes')->nullOnDelete();
            $table->timestamp('correction_reminded_at')->nullable()->after('credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('commission_disputes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('credit_note_id');
            $table->dropColumn('correction_reminded_at');
        });
        Schema::table('invoice_overpayments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_credit_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['credited_amount', 'cancelled_at']);
        });
        Schema::dropIfExists('partner_credit_applications');
        Schema::dropIfExists('partner_credits');
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
