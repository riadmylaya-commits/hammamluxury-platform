<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Périodes commerciales par partenaire (mois gratuit, taux promotionnel…) : ajout seul, aucune donnée existante modifiée. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_commission_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->string('label', 120);
            $table->decimal('commission_pct', 5, 2);
            $table->date('starts_on');
            $table->date('ends_on')->nullable(); // inclus ; null = sans fin
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['partner_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commission_periods');
    }
};
