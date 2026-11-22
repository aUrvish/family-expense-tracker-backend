<?php

namespace App\Console\Commands;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRecurringTransactionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recurring:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process active recurring transaction schedules and generate due transactions with duplicate prevention';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = now()->toDateString();
        $this->info("Processing due recurring transactions for date [{$today}]...");

        $dueRecurringIds = RecurringTransaction::query()
            ->where('status', 'active')
            ->whereDate('next_due_date', '<=', $today)
            ->pluck('id');

        $generatedCount = 0;
        $skippedCount = 0;

        foreach ($dueRecurringIds as $recurringId) {
            try {
                DB::transaction(function () use ($recurringId, $today, &$generatedCount, &$skippedCount) {
                    $recurring = RecurringTransaction::lockForUpdate()->find($recurringId);

                    if (! $recurring || $recurring->status !== 'active') {
                        return;
                    }

                    $dueDate = Carbon::parse($recurring->next_due_date)->toDateString();

                    if ($dueDate > $today) {
                        return;
                    }

                    // Duplicate prevention: check if a transaction for this recurring template on this due date already exists
                    $exists = Transaction::where('recurring_transaction_id', $recurring->id)
                        ->whereDate('date', $dueDate)
                        ->exists();

                    if (! $exists) {
                        try {
                            Transaction::create([
                                'family_id' => $recurring->family_id,
                                'user_id' => $recurring->user_id,
                                'category_id' => $recurring->category_id,
                                'recurring_transaction_id' => $recurring->id,
                                'type' => $recurring->type,
                                'amount' => $recurring->amount,
                                'date' => $dueDate,
                                'note' => $recurring->note ? "{$recurring->note} (Recurring)" : 'Recurring '.ucfirst($recurring->type),
                                'payment_method' => $recurring->payment_method,
                                'visibility' => $recurring->visibility,
                            ]);
                            $generatedCount++;
                        } catch (UniqueConstraintViolationException) {
                            $skippedCount++;
                        }
                    } else {
                        $skippedCount++;
                    }

                    // Calculate next cycle
                    $interval = max(1, (int) $recurring->interval);
                    $nextDue = match ($recurring->frequency) {
                        'daily' => Carbon::parse($dueDate)->addDays($interval),
                        'weekly' => Carbon::parse($dueDate)->addWeeks($interval),
                        'monthly' => Carbon::parse($dueDate)->addMonthsNoOverflow($interval),
                        'yearly' => Carbon::parse($dueDate)->addYearsNoOverflow($interval),
                        default => Carbon::parse($dueDate)->addMonthsNoOverflow(1),
                    };

                    $isCompleted = $recurring->end_date && $nextDue->toDateString() > Carbon::parse($recurring->end_date)->toDateString();

                    $recurring->update([
                        'last_generated_at' => now(),
                        'next_due_date' => $nextDue->toDateString(),
                        'status' => $isCompleted ? 'completed' : 'active',
                    ]);
                });
            } catch (Throwable $e) {
                Log::error("Failed processing recurring transaction [{$recurringId}]: ".$e->getMessage());
                $this->error("Error processing recurring transaction ID {$recurringId}: {$e->getMessage()}");
            }
        }

        $this->info("Completed recurring processing: [{$generatedCount}] generated, [{$skippedCount}] duplicate/skipped.");

        return self::SUCCESS;
    }
}
