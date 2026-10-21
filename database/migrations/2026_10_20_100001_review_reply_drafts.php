<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface partenaire — étape 1 : modification d'une réponse déjà publiée.
 * La nouvelle version est conservée en brouillon (`reply_draft`) jusqu'à sa vérification ;
 * la réponse publiée reste visible entre-temps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('reply_draft')->nullable()->after('reply_moderated_at');
            $table->string('reply_draft_status', 20)->nullable()->after('reply_draft');
            $table->string('reply_draft_rejection_reason', 40)->nullable()->after('reply_draft_status');
            $table->timestamp('reply_draft_at')->nullable()->after('reply_draft_rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['reply_draft', 'reply_draft_status', 'reply_draft_rejection_reason', 'reply_draft_at']);
        });
    }
};
