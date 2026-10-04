<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session 2 — avis vérifiés : invitation par lien unique lié à la réservation, parcours en deux étapes
 * (note + critères, texte + photos), modération motivée, réponse de l'établissement modérée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('booking_id');
            $table->string('title', 150)->nullable()->after('rating');
            $table->text('liked')->nullable()->after('body');
            $table->text('improve')->nullable()->after('liked');
            $table->json('criteria')->nullable()->after('improve');
            $table->json('bonus')->nullable()->after('criteria');
            $table->timestamp('invited_at')->nullable()->after('status');
            $table->timestamp('submitted_at')->nullable()->after('invited_at');
            $table->timestamp('published_at')->nullable()->after('submitted_at');
            $table->string('rejection_reason', 40)->nullable()->after('published_at');
            $table->text('rejection_note')->nullable()->after('rejection_reason');
            $table->foreignId('moderated_by')->nullable()->after('rejection_note')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            $table->text('reply')->nullable()->after('moderated_at');
            $table->string('reply_status', 12)->nullable()->after('reply'); // pending | published | rejected
            $table->timestamp('reply_at')->nullable()->after('reply_status');
            $table->string('reply_rejection_reason', 40)->nullable()->after('reply_at');
            $table->timestamp('reply_moderated_at')->nullable()->after('reply_rejection_reason');
            $table->unique('booking_id');
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 12)->default('local');
            $table->string('path');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['token', 'title', 'liked', 'improve', 'criteria', 'bonus', 'invited_at', 'submitted_at', 'published_at',
                'rejection_reason', 'rejection_note', 'moderated_at', 'reply', 'reply_status', 'reply_at', 'reply_rejection_reason', 'reply_moderated_at']);
        });
    }
};
