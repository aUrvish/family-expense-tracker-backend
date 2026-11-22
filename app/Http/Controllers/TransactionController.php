<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $query = Transaction::query()
                ->where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['category', 'user']);

            // Filter by type ('expense' or 'income')
            if ($request->filled('type') && in_array($request->input('type'), ['expense', 'income'])) {
                $query->where('type', $request->input('type'));
            }

            // Filter by user_id (family member)
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }

            // Filter by category_id
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->input('category_id'));
            }

            // Filter by visibility ('shared' or 'personal')
            if ($request->filled('visibility') && in_array($request->input('visibility'), ['shared', 'personal'])) {
                $query->where('visibility', $request->input('visibility'));
            }

            // Filter by date range
            if ($request->filled('start_date')) {
                $query->whereDate('date', '>=', Carbon::parse($request->input('start_date'))->toDateString());
            }

            if ($request->filled('end_date')) {
                $query->whereDate('date', '<=', Carbon::parse($request->input('end_date'))->toDateString());
            }

            // Filter by month and year
            if ($request->filled('month')) {
                $query->whereMonth('date', $request->input('month'));
            }

            if ($request->filled('year')) {
                $query->whereYear('date', $request->input('year'));
            }

            // Search in note
            if ($request->filled('search')) {
                $query->where('note', 'like', '%'.$request->input('search').'%');
            }

            $query->orderByDesc('date')->orderByDesc('id');

            $transactions = $query->paginate($request->integer('per_page', 15));
            $transactions->through(fn ($tx) => $this->formatTransaction($tx));

            return $this->successResponse($transactions, 'Transactions retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch transactions failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Create or update a transaction (expense or income).
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => ['nullable', 'integer', 'exists:transactions,id'],
                'type' => ['required', 'string', Rule::in(['expense', 'income'])],
                'amount' => ['required', 'numeric', 'gt:0'],
                'category_id' => ['required', 'integer', 'exists:categories,id'],
                'date' => ['nullable'],
                'note' => ['nullable', 'string', 'max:255'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'visibility' => ['nullable', 'string', Rule::in(['shared', 'personal'])],
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

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);

            $date = $request->filled('date')
                ? Carbon::parse($request->input('date'))->toDateString()
                : now()->toDateString();

            $rawAmount = (float) $validated['amount'];
            $baseAmount = $currencyService->convertToBaseAmount($rawAmount, $currency, Carbon::parse($date));

            // Update existing transaction if 'id' is provided
            if (! empty($validated['id'])) {
                $transaction = Transaction::where('family_id', $familyId)->find($validated['id']);

                if (! $transaction) {
                    return $this->errorResponse('Transaction not found.', 404);
                }

                if ($transaction->visibility === 'personal' && $transaction->user_id !== $user->id) {
                    return $this->errorResponse('Transaction not found.', 404);
                }

                $transaction->update([
                    'category_id' => $validated['category_id'],
                    'type' => $validated['type'],
                    'amount' => $baseAmount,
                    'date' => $request->filled('date') ? $date : $transaction->date,
                    'note' => $validated['note'] ?? null,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'visibility' => $validated['visibility'] ?? $transaction->visibility,
                ]);

                return $this->successResponse(
                    $this->formatTransaction($transaction->fresh()->load(['category', 'user'])),
                    ucfirst($validated['type']).' updated successfully.',
                    200
                );
            }

            // Otherwise, create new transaction
            $transaction = Transaction::create([
                'family_id' => $familyId,
                'user_id' => $user->id,
                'category_id' => $validated['category_id'],
                'type' => $validated['type'],
                'amount' => $baseAmount,
                'date' => $date,
                'note' => $validated['note'] ?? null,
                'payment_method' => $validated['payment_method'] ?? null,
                'visibility' => $validated['visibility'] ?? 'shared',
            ]);

            return $this->successResponse(
                $this->formatTransaction($transaction->load(['category', 'user'])),
                ucfirst($validated['type']).' added successfully.',
                201
            );
        } catch (Throwable $e) {
            Log::error('Save transaction failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $transaction = Transaction::where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['category', 'user'])
                ->find($id);

            if (! $transaction) {
                return $this->errorResponse('Transaction not found.', 404);
            }

            return $this->successResponse($this->formatTransaction($transaction), 'Transaction retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Show transaction failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Remove the specified transaction from storage.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $transaction = Transaction::where('family_id', $familyId)->find($id);

            if (! $transaction) {
                return $this->errorResponse('Transaction not found.', 404);
            }

            // Personal transactions can only be deleted by their creator
            if ($transaction->visibility === 'personal' && $transaction->user_id !== $user->id) {
                return $this->errorResponse('Transaction not found.', 404);
            }

            $type = ucfirst($transaction->type);
            $transaction->delete();

            return $this->successResponse(null, $type.' deleted successfully.');
        } catch (Throwable $e) {
            Log::error('Delete transaction failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Restore the specified soft-deleted transaction.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $transaction = Transaction::onlyTrashed()->where('family_id', $familyId)->find($id);

            if (! $transaction) {
                return $this->errorResponse('Transaction not found.', 404);
            }

            // Personal transactions can only be restored by their creator
            if ($transaction->visibility === 'personal' && $transaction->user_id !== $user->id) {
                return $this->errorResponse('Transaction not found.', 404);
            }

            $transaction->restore();

            return $this->successResponse(null, ucfirst($transaction->type).' restored successfully.');
        } catch (Throwable $e) {
            Log::error('Restore transaction failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Format a transaction model into an array with currency display metadata.
     *
     * @return array<string, mixed>
     */
    protected function formatTransaction(Transaction $transaction): array
    {
        $currency = tenant('currency') ?: config('app.base_currency', 'USD');
        $cents = (int) round((float) $transaction->amount * 100);
        $date = Carbon::parse($transaction->date ?? now());

        $data = $transaction->toArray();
        $data['display'] = app(CurrencyService::class)->formatWithDisplay($cents, $currency, $date);

        return $data;
    }
}
