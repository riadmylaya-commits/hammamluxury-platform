<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion d'un établissement par HammamLuxury (reprise temporaire) et suspension de l'accès d'un propriétaire.
 * spas.managed_by : partner (défaut) | admin — en mode admin, les demandes sont adressées au gestionnaire (manager_user_id)
 * et le partenaire ne décide plus. spas.owner_access_suspended_at : accès du propriétaire à CET établissement refusé tant que
 * renseigné (ses autres établissements restent accessibles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->string('managed_by', 10)->default('partner')->after('partner_id');
            $table->foreignId('manager_user_id')->nullable()->after('managed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('owner_invited_at')->nullable()->after('manager_user_id');
            $table->timestamp('owner_access_suspended_at')->nullable()->after('owner_invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('spas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropColumn(['managed_by', 'owner_invited_at', 'owner_access_suspended_at']);
        });
    }
};
