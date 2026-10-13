<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('audience', 20);
            $table->string('recipient', 190);
            $table->string('status', 20)->default('planned'); // planned | queued | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'event', 'audience']);
            $table->index(['status', 'updated_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('manage_token_revoked_at')->nullable()->after('manage_token');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('manage_token_revoked_at');
        });
        Schema::dropIfExists('booking_notifications');
    }
};
