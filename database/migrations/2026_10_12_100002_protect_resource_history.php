<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dateTime('archived_at')->nullable();
            $table->dropForeign(['resource_type_id']);
            $table->foreign('resource_type_id')->references('id')->on('resource_types')->restrictOnDelete();
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropForeign(['resource_id']);
            $table->dropForeign(['resource_type_id']);
            $table->foreign('resource_id')->references('id')->on('resources')->restrictOnDelete();
            $table->foreign('resource_type_id')->references('id')->on('resource_types')->restrictOnDelete();
        });
        Schema::table('treatment_steps', function (Blueprint $table) {
            $table->dropForeign(['resource_type_id']);
            $table->foreign('resource_type_id')->references('id')->on('resource_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_steps', function (Blueprint $table) {
            $table->dropForeign(['resource_type_id']);
            $table->foreign('resource_type_id')->references('id')->on('resource_types')->cascadeOnDelete();
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropForeign(['resource_id']);
            $table->dropForeign(['resource_type_id']);
            $table->foreign('resource_id')->references('id')->on('resources')->cascadeOnDelete();
            $table->foreign('resource_type_id')->references('id')->on('resource_types')->cascadeOnDelete();
        });
        Schema::table('resources', function (Blueprint $table) {
            $table->dropForeign(['resource_type_id']);
            $table->foreign('resource_type_id')->references('id')->on('resource_types')->cascadeOnDelete();
            $table->dropColumn('archived_at');
        });
    }
};
