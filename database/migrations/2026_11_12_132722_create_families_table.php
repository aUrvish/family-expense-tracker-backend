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
        Schema::create('families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('family_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->timestamps();

            $table->unique(['user_id', 'family_id']);
            $table->index('family_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_user');
        Schema::dropIfExists('families');
    }
};
