<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                ->with(['user', 'transaction', 'settlementTransaction']);

            // Filter by type ('lent' or 'borrowed')
            if ($request->filled('type') && in_array($request->input('type'), ['lent', 'borrowed'])) {
                $query->where('type', $request->input('type'));
            }

            // Filter by status ('pending' or 'settled')
            if ($request->filled('status') && in_array($request->input('status'), ['pending', 'settled'])) {
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

            return $this->successResponse($debts, 'Debts retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch debts failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Create or update a debt (lent or borrowed).
     */
    public function save(Request $request): JsonResponse
    {
        try {
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
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $user = $request->user();
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

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
                $amount = $validated['amount'];
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
                        'amount' => $amount,
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

                    $settlementTx = Transaction::updateOrCreate(
                        ['id' => $debt->settlement_transaction_id],
                        [
                            'family_id' => $familyId,
                            'user_id' => $debt->user_id ?? $user->id,
                            'category_id' => $categoryId,
                            'type' => $type === 'lent' ? 'income' : 'expense',
                            'amount' => $amount,
                            'date' => $debt->settled_at?->toDateString() ?? now()->toDateString(),
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
                    'amount' => $amount,
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
                    $debt->fresh(['user', 'transaction', 'settlementTransaction']),
                    $message,
                    $statusCode
                );
            });
        } catch (Throwable $e) {
            Log::error('Save debt failed: '.$e->getMessage());

            return $this->errorResponse();
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

            return $this->successResponse($debt, 'Debt record retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Show debt failed: '.$e->getMessage());

            return $this->errorResponse();
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

            $debt = Debt::where('family_id', $familyId)->find($id);

            if (! $debt) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            if ($debt->visibility === 'personal' && $debt->user_id !== $user->id) {
                return $this->errorResponse('Debt record not found.', 404);
            }

            if ($debt->status === 'settled') {
                return $this->successResponse(
                    $debt->load(['user', 'transaction', 'settlementTransaction']),
                    'Debt record is already settled.'
                );
            }

            return DB::transaction(function () use ($debt, $familyId, $user) {
                $categoryId = $debt->transaction?->category_id ?? Category::where('slug', 'others')->value('id');
                $settleTxType = $debt->type === 'lent' ? 'income' : 'expense';
                $settleAction = $debt->type === 'lent' ? 'Received from' : 'Paid to';
                $settleTxNote = "Settled: {$settleAction} {$debt->person_name}";
                if (! empty($debt->note)) {
                    $settleTxNote .= ": {$debt->note}";
                }

                $settledAt = now();

                $settlementTx = Transaction::create([
                    'family_id' => $familyId,
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'type' => $settleTxType,
                    'amount' => $debt->amount,
                    'date' => $settledAt->toDateString(),
                    'note' => $settleTxNote,
                    'visibility' => $debt->visibility,
                ]);

                $debt->update([
                    'status' => 'settled',
                    'settled_at' => $settledAt,
                    'settlement_transaction_id' => $settlementTx->id,
                ]);

                return $this->successResponse(
                    $debt->fresh(['user', 'transaction', 'settlementTransaction']),
                    'Debt settled successfully.'
                );
            });
        } catch (Throwable $e) {
            Log::error('Settle debt failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Remove the specified debt record and its linked transactions.
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

                $debt->delete();
            });

            return $this->successResponse(null, ucfirst($debt->type).' record deleted successfully.');
        } catch (Throwable $e) {
            Log::error('Delete debt failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
