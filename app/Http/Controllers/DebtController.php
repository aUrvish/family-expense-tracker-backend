<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtSettlement;
use App\Models\Transaction;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class DebtController extends Controller
{
    /**
     * Display a listing of debts (credit/debit).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $query = Debt::query()
                ->where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['user', 'transaction', 'settlementTransaction', 'settlements.transaction']);

            // Filter by type ('lent' or 'borrowed')
            if ($request->filled('type') && in_array($request->input('type'), ['lent', 'borrowed'])) {
                $query->where('type', $request->input('type'));
            }

            // Filter by status ('pending', 'partially_settled', 'settled')
            if ($request->filled('status') && in_array($request->input('status'), ['pending', 'partially_settled', 'settled'])) {
                $query->where('status', $request->input('status'));
            }

            // Filter by visibility ('shared' or 'personal')
            if ($request->filled('visibility') && in_array($request->input('visibility'), ['shared', 'personal'])) {
                $query->where('visibility', $request->input('visibility'));
            }

            // Search in person_name or note
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('person_name', 'like', '%'.$search.'%')
                        ->orWhere('note', 'like', '%'.$search.'%');
                });
            }

            // Filter by date range
            if ($request->filled('start_date')) {
                $query->whereDate('date', '>=', Carbon::parse($request->input('start_date'))->toDateString());
            }

            if ($request->filled('end_date')) {
                $query->whereDate('date', '<=', Carbon::parse($request->input('end_date'))->toDateString());
            }

            // Filter by due_date
            if ($request->filled('due_date')) {
                $query->whereDate('due_date', Carbon::parse($request->input('due_date'))->toDateString());
            }

            $query->orderByDesc('date')->orderByDesc('id');

            $debts = $query->paginate($request->integer('per_page', 15));
            $debts->through(fn ($debt) => $this->formatDebt($debt));

            return $this->successResponse($debts, 'Debts retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch debts failed');
        }
    }

    /**
     * Create or update a debt (lent or borrowed).
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $validator = Validator::make($request->all(), [
                'id' => ['nullable', 'integer', 'exists:debts,id'],
                'person_name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'string', Rule::in(['lent', 'borrowed'])],
                'amount' => ['required', 'numeric', 'gt:0'],
                'date' => ['nullable'],
                'due_date' => ['nullable'],
                'note' => ['nullable', 'string', 'max:255'],
                'visibility' => ['nullable', 'string', Rule::in(['shared', 'personal'])],
                'status' => ['nullable', 'string', Rule::in(['pending', 'settled'])],
                'category_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('categories', 'id')->where(function ($query) use ($familyId) {
                        $query->whereNull('deleted_at')
                            ->where(function ($q) use ($familyId) {
                                $q->whereNull('family_id')->orWhere('family_id', $familyId);
                            });
                    }),
                ],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $user = $request->user();

            // Find existing debt for update or create a new instance
            if (! empty($validated['id'])) {
                $debt = Debt::where('family_id', $familyId)->find($validated['id']);

                if (! $debt || ($debt->visibility === 'personal' && $debt->user_id !== $user->id)) {
                    return $this->errorResponse('Debt record not found.', 404);
                }
            } else {
                $debt = new Debt([
                    'family_id' => $familyId,
                    'user_id' => $user->id,
                ]);
            }

            return DB::transaction(function () use ($debt, $validated, $familyId, $user, $request) {
                $isNew = ! $debt->exists;
                $type = $validated['type'];
                $personName = $validated['person_name'];
                $rawAmount = (float) $validated['amount'];
                $note = $validated['note'] ?? null;
                $date = $request->filled('date')
                    ? Carbon::parse($request->input('date'))->toDateString()
                    : ($debt->exists ? $debt->date : now()->toDateString());

                $dueDate = array_key_exists('due_date', $validated)
                    ? ($request->filled('due_date') ? Carbon::parse($request->input('due_date'))->toDateString() : null)
                    : ($debt->exists ? $debt->due_date : null);

                $visibility = $validated['visibility'] ?? ($debt->visibility ?? 'shared');
                $status = $validated['status'] ?? ($debt->status ?? 'pending');
                $categoryId = $validated['category_id'] ?? Category::where('slug', 'others')->value('id');

                $currency = tenant('currency') ?: config('app.base_currency', 'USD');
                $currencyService = app(CurrencyService::class);
                $baseAmount = $currencyService->convertToBaseAmount($rawAmount, $currency, Carbon::parse($date));

                // 1. Initial Transaction (Lent => expense, Borrowed => income)
                $initialAction = $type === 'lent' ? 'Lent to' : 'Borrowed from';
                $initialNote = $note ? "{$initialAction} {$personName}: {$note}" : "{$initialAction} {$personName}";

                $initialTx = Transaction::updateOrCreate(
                    ['id' => $debt->transaction_id],
                    [
                        'family_id' => $familyId,
                        'user_id' => $debt->user_id ?? $user->id,
                        'category_id' => $categoryId,
                        'type' => $type === 'lent' ? 'expense' : 'income',
                        'amount' => $baseAmount,
                        'date' => $date,
                        'note' => $initialNote,
                        'visibility' => $visibility,
                    ]
                );

                // 2. Settlement Transaction (Lent => income, Borrowed => expense)
                $settlementTxId = null;
                $settledAt = null;

                if ($status === 'settled') {
                    $settleAction = $type === 'lent' ? 'Received from' : 'Paid to';
                    $settleNote = $note ? "Settled: {$settleAction} {$personName}: {$note}" : "Settled: {$settleAction} {$personName}";
                    $settledDate = $debt->settled_at?->toDateString() ?? now()->toDateString();

                    $settlementTx = Transaction::updateOrCreate(
                        ['id' => $debt->settlement_transaction_id],
                        [
                            'family_id' => $familyId,
                            'user_id' => $debt->user_id ?? $user->id,
                            'category_id' => $categoryId,
                            'type' => $type === 'lent' ? 'income' : 'expense',
                            'amount' => $baseAmount,
                            'date' => $settledDate,
                            'note' => $settleNote,
                            'visibility' => $visibility,
                        ]
                    );

                    $settlementTxId = $settlementTx->id;
                    $settledAt = $debt->settled_at ?? now();
                } elseif ($debt->settlementTransaction) {
                    $debt->settlementTransaction->delete();
                }

                // 3. Save Debt
                $debt->fill([
                    'person_name' => $personName,
                    'type' => $type,
                    'amount' => $baseAmount,
                    'date' => $date,
                    'due_date' => $dueDate,
                    'note' => $note,
                    'visibility' => $visibility,
                    'status' => $status,
                    'settled_at' => $settledAt,
                    'transaction_id' => $initialTx->id,
                    'settlement_transaction_id' => $settlementTxId,
                ])->save();

                $message = ucfirst($type).' record '.($isNew ? 'added' : 'updated').' successfully.';
                $statusCode = $isNew ? 201 : 200;

                return $this->successResponse(
                    $this->formatDebt($debt->fresh(['user', 'transaction', 'settlementTransaction'])),
                    $message,
                    $statusCode
                );
            });
        } catch (Throwable $e) {
            return $this->handleException($e, 'Save debt failed');
        }
    }

    /**
     * Display the specified debt record.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $debt = Debt::where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['user', 'transaction', 'settlementTransaction'])
                ->find($id);

            if (! $debt) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            return $this->successResponse($this->formatDebt($debt), 'Debt record retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Show debt failed');
        }
    }

    /**
     * Settle a debt record (mark as settled and generate reverse transaction).
     */
    public function settle(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'amount' => 'nullable|numeric|min:0.01',
                'date' => 'nullable|date',
                'note' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation error.', 422, $validator->errors());
            }

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);
            $settleDate = $request->filled('date')
                ? Carbon::parse($request->input('date'))
                : now();

            return DB::transaction(function () use ($id, $familyId, $user, $request, $currency, $currencyService, $settleDate) {
                $debt = Debt::where('family_id', $familyId)->lockForUpdate()->find($id);

                if (! $debt) {
                    return $this->errorResponse('Debt record not found.', 404);
                }

                if ($debt->visibility === 'personal' && $debt->user_id !== $user->id) {
                    return $this->errorResponse('Debt record not found.', 404);
                }

                if ($debt->status === 'settled') {
                    return $this->successResponse(
                        $this->formatDebt($debt->load(['user', 'transaction', 'settlementTransaction', 'settlements.transaction'])),
                        'Debt record is already settled.'
                    );
                }

                // Cent-based integer math for financial precision under lock
                $totalCents = (int) round((float) $debt->amount * 100);
                $settledCents = (int) round((float) $debt->settled_amount * 100);
                $remainingCents = max(0, $totalCents - $settledCents);
                $remainingAmount = round($remainingCents / 100, 2);

                if ($remainingCents <= 0) {
                    return $this->errorResponse('This debt is already settled.', 422);
                }

                if ($request->filled('amount')) {
                    $rawAmount = (float) $request->input('amount');
                    $baseSettleAmount = $currencyService->convertToBaseAmount($rawAmount, $currency, $settleDate);
                    $settleCents = (int) round($baseSettleAmount * 100);

                    if ($settleCents > $remainingCents) {
                        return $this->errorResponse("Settlement amount cannot exceed remaining balance of {$remainingAmount}.", 422);
                    }
                } else {
                    $baseSettleAmount = $remainingAmount;
                    $settleCents = $remainingCents;
                }

                $categoryId = $debt->transaction?->category_id ?? Category::where('slug', 'others')->value('id');
                $settleTxType = $debt->type === 'lent' ? 'income' : 'expense';
                $settleAction = $debt->type === 'lent' ? 'Received from' : 'Paid to';

                if ($request->filled('note')) {
                    $settleTxNote = $request->input('note');
                } else {
                    $settleTxNote = "Settled: {$settleAction} {$debt->person_name}";
                    if (! empty($debt->note)) {
                        $settleTxNote .= ": {$debt->note}";
                    }
                }

                $settlementTx = Transaction::create([
                    'family_id' => $familyId,
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'type' => $settleTxType,
                    'amount' => $baseSettleAmount,
                    'date' => $settleDate->toDateString(),
                    'note' => $settleTxNote,
                    'visibility' => $debt->visibility,
                ]);

                DebtSettlement::create([
                    'debt_id' => $debt->id,
                    'transaction_id' => $settlementTx->id,
                    'user_id' => $user->id,
                    'amount' => $baseSettleAmount,
                    'date' => $settleDate->toDateString(),
                    'note' => $settleTxNote,
                ]);

                $newSettledCents = $settledCents + $settleCents;
                $newSettled = round($newSettledCents / 100, 2);
                $isFull = ($newSettledCents >= $totalCents);

                $debt->update([
                    'settled_amount' => $newSettled,
                    'status' => $isFull ? 'settled' : 'partially_settled',
                    'settled_at' => $isFull ? $settleDate : null,
                    'settlement_transaction_id' => $settlementTx->id,
                ]);

                $message = $isFull ? 'Debt settled successfully.' : 'Partial debt settlement recorded successfully.';

                return $this->successResponse(
                    $this->formatDebt($debt->fresh(['user', 'transaction', 'settlementTransaction', 'settlements.transaction'])),
                    $message
                );
            });
        } catch (Throwable $e) {
            return $this->handleException($e, 'Settle debt failed');
        }
    }

    /**
     * Soft-delete the specified debt record and its linked transactions.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $debt = Debt::where('family_id', $familyId)->find($id);

            if (! $debt) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            // Personal debts can only be deleted by their creator
            if ($debt->visibility === 'personal' && $debt->user_id !== $user->id) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            DB::transaction(function () use ($debt) {
                if ($debt->transaction) {
                    $debt->transaction->delete();
                }

                if ($debt->settlementTransaction) {
                    $debt->settlementTransaction->delete();
                }

                foreach ($debt->settlements as $settlement) {
                    $settlement->transaction?->delete();
                    $settlement->delete();
                }

                $debt->delete();
            });

            return $this->successResponse(null, ucfirst($debt->type).' record deleted successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Delete debt failed');
        }
    }

    /**
     * Restore the specified soft-deleted debt record and its linked transactions.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $debt = Debt::onlyTrashed()->where('family_id', $familyId)->find($id);

            if (! $debt) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            if ($debt->visibility === 'personal' && $debt->user_id !== $user->id) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            DB::transaction(function () use ($debt) {
                $debt->restore();
                $debt->transaction()->withTrashed()->restore();
                $debt->settlementTransaction()->withTrashed()->restore();

                foreach (DebtSettlement::onlyTrashed()->where('debt_id', $debt->id)->get() as $settlement) {
                    $settlement->transaction()->withTrashed()->restore();
                    $settlement->restore();
                }
            });

            return $this->successResponse(null, 'Debt restored successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Restore debt failed');
        }
    }

    /**
     * Format a debt model and its linked transactions with currency display metadata.
     *
     * @return array<string, mixed>
     */
    protected function formatDebt(Debt $debt): array
    {
        $currency = tenant('currency') ?: config('app.base_currency', 'USD');
        $currencyService = app(CurrencyService::class);

        // Helper to format any model amount and date into currency display metadata
        $format = function ($amount, $date) use ($currencyService, $currency): array {
            $cents = (int) round(((float) $amount) * 100);

            return $currencyService->formatWithDisplay($cents, $currency, Carbon::parse($date ?? now()));
        };

        $data = $debt->toArray();
        $debtDate = $debt->date ?? now();
        $data['settled_amount'] = (float) $debt->settled_amount;
        $data['remaining_amount'] = (float) $debt->remaining_amount;

        $data['display'] = $format($debt->amount, $debtDate);
        $data['display']['settled'] = $format($debt->settled_amount, $debtDate);
        $data['display']['remaining'] = $format($debt->remaining_amount, $debtDate);

        if ($debt->relationLoaded('transaction') && $debt->transaction) {
            $data['transaction']['display'] = $format($debt->transaction->amount, $debt->transaction->date);
        }

        if ($debt->relationLoaded('settlementTransaction') && $debt->settlementTransaction) {
            $data['settlement_transaction']['display'] = $format($debt->settlementTransaction->amount, $debt->settlementTransaction->date);
        }

        if ($debt->relationLoaded('settlements') && $debt->settlements) {
            $data['settlements'] = $debt->settlements->map(function ($settlement) use ($format) {
                $sData = $settlement->toArray();
                $sData['display'] = $format($settlement->amount, $settlement->date);
                if ($settlement->relationLoaded('transaction') && $settlement->transaction) {
                    $sData['transaction']['display'] = $format($settlement->transaction->amount, $settlement->transaction->date);
                }

                return $sData;
            })->toArray();
        }

        return $data;
    }
}
