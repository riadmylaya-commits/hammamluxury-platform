<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion d'un établissement par HammamLuxury (reprise temporaire) et suspension de l'accès d'un propriétaire.
 * spas.managed_by : partner (défaut) | admin — en mode admin, les demandes sont adressées au gestionnaire (manager_user_id)
 * et le partenaire ne décide plus. users.access_suspended_at : connexion à l'espace partenaire refusée tant que renseigné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->string('managed_by', 10)->default('partner')->after('partner_id');
            $table->foreignId('manager_user_id')->nullable()->after('managed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('owner_invited_at')->nullable()->after('manager_user_id');
        });
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('access_suspended_at')->nullable()->after('email_verified_at'));
    }

    public function down(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropColumn(['managed_by', 'owner_invited_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('access_suspended_at'));
    }
};
