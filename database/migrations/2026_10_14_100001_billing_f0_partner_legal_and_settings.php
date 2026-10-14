<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lot F0 facturation : référentiel légal partenaire et paramètres de facturation. Purement additif, aucune valeur existante modifiée. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('ice', 15)->nullable()->after('registry_number');
            $table->boolean('ice_not_applicable')->default(false)->after('ice');
            $table->string('ice_not_applicable_reason', 190)->nullable()->after('ice_not_applicable');
            $table->string('billing_address', 255)->nullable()->after('ice_not_applicable_reason');
            $table->string('billing_city', 90)->nullable()->after('billing_address');
            $table->string('billing_email', 190)->nullable()->after('billing_city');
        });

        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['ice', 'ice_not_applicable', 'ice_not_applicable_reason', 'billing_address', 'billing_city', 'billing_email']);
        });
    }
};
