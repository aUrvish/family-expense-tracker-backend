<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignUuid('family_id')->nullable()->after('id')->constrained('families')->cascadeOnDelete();
            $table->softDeletes()->after('color');

            $table->dropUnique(['name']);
            $table->dropUnique(['slug']);
            $table->unique(['family_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['family_id', 'slug']);
            $table->unique(['name']);
            $table->unique(['slug']);
            $table->dropForeign(['family_id']);
            $table->dropColumn(['family_id', 'deleted_at']);
        });
    }
};
