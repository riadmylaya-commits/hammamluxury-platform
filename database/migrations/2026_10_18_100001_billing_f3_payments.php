<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F3 — paiements, reçus d'espèces et trop-perçus. Migration purement additive : aucune valeur existante n'est modifiée,
 * le grand livre n'est pas touché. `invoice_payments` est en ajout seul (observer) ; une erreur de saisie se corrige par une
 * contre-passation liée (`reverses_payment_id`), jamais par suppression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2); // > 0 pour un paiement, < 0 pour une contre-passation
            $table->string('currency', 3);
            $table->date('paid_on');
            $table->string('method', 20); // bank_transfer | cash
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->string('idempotency_key', 64)->unique(); // une opération = une clé, générée à l'ouverture du formulaire
            $table->foreignId('reverses_payment_id')->nullable()->unique()->constrained('invoice_payments')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->decimal('balance_after', 12, 2); // solde de la facture après cette opération (audit)
            $table->string('receipt_number', 30)->nullable()->unique(); // HL-R-AAAA-NNNNN, espèces uniquement
            $table->string('receipt_status', 12)->nullable(); // pending | ready | failed
            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->unsignedSmallInteger('receipt_attempts')->default(0);
            $table->string('receipt_error', 500)->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'paid_on']);
            $table->index(['partner_id', 'paid_on']);
        });

        Schema::create('invoice_overpayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('invoice_payments')->restrictOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 12)->default('open'); // open | applied | refunded (traitement manuel, F4)
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'partner_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->default(0)->after('total_ttc');
            $table->decimal('balance', 12, 2)->default(0)->after('paid_amount'); // = total_ttc − paid_amount, recalculé sous verrou
            $table->timestamp('paid_at')->nullable()->after('due_at');
            $table->json('reminders_sent')->nullable()->after('notes'); // {due_minus_5: ts, due: ts, late_7: ts, late_15: ts}
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'balance', 'paid_at', 'reminders_sent']);
        });
        Schema::dropIfExists('invoice_overpayments');
        Schema::dropIfExists('invoice_payments');
    }
};
