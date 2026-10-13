<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('arbitration_required_at')->nullable()->after('expiration_notified_at')->index();
        });
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('client_notified_at');
            $table->timestamp('escalated_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('arbitration_required_at'));
        Schema::table('cancellation_requests', fn (Blueprint $table) => $table->dropColumn(['reminder_sent_at', 'escalated_at']));
    }
};
