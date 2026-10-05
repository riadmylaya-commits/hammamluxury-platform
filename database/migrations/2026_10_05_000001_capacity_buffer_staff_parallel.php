<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('buffer_min')->default(0)->after('kind');
        });
        Schema::table('treatment_steps', function (Blueprint $table) {
            $table->unsignedTinyInteger('staff_per_person')->default(0)->after('offset_min');
            $table->boolean('parallel_with_previous')->default(false)->after('staff_per_person');
        });
    }

    public function down(): void
    {
        Schema::table('resource_types', fn (Blueprint $table) => $table->dropColumn('buffer_min'));
        Schema::table('treatment_steps', fn (Blueprint $table) => $table->dropColumn(['staff_per_person', 'parallel_with_previous']));
    }
};
