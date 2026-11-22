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
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('type', 20); // 'expense' or 'income'
            $table->decimal('amount', 12, 2); // Base USD amount
            $table->string('frequency', 20); // 'daily', 'weekly', 'monthly', 'yearly'
            $table->unsignedInteger('interval')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_due_date');
            $table->timestamp('last_generated_at')->nullable();
            $table->string('status', 20)->default('active'); // 'active', 'paused', 'completed'
            $table->string('note')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('visibility', 20)->default('shared'); // 'shared' or 'personal'
            $table->timestamps();
            $table->softDeletes();

            $table->index(['family_id', 'status']);
            $table->index(['status', 'next_due_date']);
            $table->index(['family_id', 'visibility']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_transaction_id')->nullable()->after('category_id')->constrained('recurring_transactions')->nullOnDelete();
            $table->softDeletes()->after('visibility');
            $table->unique(['recurring_transaction_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['recurring_transaction_id', 'date']);
            $table->dropForeign(['recurring_transaction_id']);
            $table->dropColumn(['recurring_transaction_id', 'deleted_at']);
        });

        Schema::dropIfExists('recurring_transactions');
    }
};
