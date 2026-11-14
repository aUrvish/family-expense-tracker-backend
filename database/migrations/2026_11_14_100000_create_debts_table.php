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
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('person_name');
            $table->string('type', 20); // 'lent' or 'borrowed'
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('note')->nullable();
            $table->string('visibility', 20)->default('shared'); // 'shared' or 'personal'
            $table->string('status', 20)->default('pending'); // 'pending' or 'settled'
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('settlement_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'status']);
            $table->index(['family_id', 'type']);
            $table->index(['family_id', 'visibility']);
            $table->index(['family_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
