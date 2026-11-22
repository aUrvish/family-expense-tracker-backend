<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->decimal('settled_amount', 12, 2)->default(0.00)->after('amount');
            $table->softDeletes()->after('settlement_transaction_id');
        });

        DB::table('debts')->where('status', 'settled')->update([
            'settled_amount' => DB::raw('amount'),
        ]);

        Schema::create('debt_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained('debts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['debt_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_settlements');

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['settled_amount', 'deleted_at']);
        });
    }
};
