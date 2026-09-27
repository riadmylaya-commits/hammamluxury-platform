<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fiche réservation partenaire : statut de paiement, montant commissionnable, extras commissionnables, notes internes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_status', 12)->default('on_site')->after('currency'); // on_site | paid | partial | refunded
            $table->decimal('commissionable_amount', 10, 2)->nullable()->after('total');
        });
        DB::table('bookings')->whereNull('commissionable_amount')->update(['commissionable_amount' => DB::raw('total')]);

        Schema::table('extras', function (Blueprint $table) {
            $table->boolean('commissionable')->default(true)->after('max_qty');
        });

        Schema::create('booking_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_notes');
        Schema::table('extras', fn (Blueprint $table) => $table->dropColumn('commissionable'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['payment_status', 'commissionable_amount']));
    }
};
