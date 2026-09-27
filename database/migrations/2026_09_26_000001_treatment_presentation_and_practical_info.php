<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_steps', function (Blueprint $table) {
            $table->string('label', 120)->nullable()->after('resource_type_id');
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->json('included')->nullable()->after('conditions_en');
            $table->string('featured_badge', 20)->nullable()->after('included'); // signature | popular
        });

        Schema::table('spas', function (Blueprint $table) {
            $table->json('practical_info')->nullable()->after('description_en');
        });
    }

    public function down(): void
    {
        Schema::table('treatment_steps', fn (Blueprint $t) => $t->dropColumn('label'));
        Schema::table('treatments', fn (Blueprint $t) => $t->dropColumn(['included', 'featured_badge']));
        Schema::table('spas', fn (Blueprint $t) => $t->dropColumn('practical_info'));
    }
};
