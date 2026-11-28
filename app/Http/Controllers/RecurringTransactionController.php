<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class RecurringTransactionController extends Controller
{
    /**
     * Display a listing of recurring transactions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $query = RecurringTransaction::query()
                ->where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['category', 'user']);

            // Filter by status ('active', 'paused', 'completed')
            if ($request->filled('status') && in_array($request->input('status'), ['active', 'paused', 'completed'])) {
                $query->where('status', $request->input('status'));
            }

            // Filter by type ('expense' or 'income')
            if ($request->filled('type') && in_array($request->input('type'), ['expense', 'income'])) {
                $query->where('type', $request->input('type'));
            }

            // Filter by scope ('household' or 'personal')
            if ($request->filled('scope')) {
                if ($request->input('scope') === 'personal') {
                    $query->where('visibility', 'personal')->where('user_id', $user->id);
                } elseif ($request->input('scope') === 'household') {
                    $query->where('visibility', 'shared');
                }
            }

            $query->orderBy('next_due_date')->orderBy('id');

            $recurring = $query->paginate($request->integer('per_page', 15));
            $recurring->through(fn (RecurringTransaction $item) => $this->formatRecurring($item));

            return $this->successResponse($recurring, 'Recurring transactions retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch recurring transactions failed');
        }
    }

    /**
     * Create or update a recurring transaction template.
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $validator = Validator::make($request->all(), [
                'id' => 'nullable|integer|exists:recurring_transactions,id',
                'category_id' => [
                    'required_without:id',
                    'nullable',
                    'integer',
                    Rule::exists('categories', 'id')->where(function ($query) use ($familyId) {
                        $query->whereNull('deleted_at')
                            ->where(function ($q) use ($familyId) {
                                $q->whereNull('family_id')->orWhere('family_id', $familyId);
                            });
                    }),
                ],
                'type' => 'required_without:id|nullable|string|in:expense,income',
                'amount' => 'required_without:id|nullable|numeric|min:0.01',
                'frequency' => 'required_without:id|nullable|string|in:daily,weekly,monthly,yearly',
                'interval' => 'nullable|integer|min:1|max:365',
                'start_date' => 'required_without:id|nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|string|in:active,paused,completed',
                'note' => 'nullable|string|max:255',
                'payment_method' => 'nullable|string|max:50',
                'visibility' => 'nullable|string|in:shared,personal',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation error.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $user = $request->user();

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);

            // Update existing recurring template
            if (! empty($validated['id'])) {
                $recurring = RecurringTransaction::where('family_id', $familyId)->find($validated['id']);

                if (! $recurring) {
                    return $this->errorResponse('Recurring transaction not found.', 404);
                }

                if ($recurring->visibility === 'personal' && $recurring->user_id !== $user->id) {
                    return $this->errorResponse('Recurring transaction not found.', 404);
                }

                $effectiveCategoryId = $validated['category_id'] ?? $recurring->category_id;
                $effectiveType = $validated['type'] ?? $recurring->type;
                $category = Category::find($effectiveCategoryId);

                if (! $category || $category->type !== $effectiveType) {
                    return $this->errorResponse('Validation error.', 422, [
                        'category_id' => ['The selected category type does not match the recurring transaction type.'],
                    ]);
                }

                $updateData = [];

                if (isset($validated['category_id'])) {
                    $updateData['category_id'] = $validated['category_id'];
                }

                if (isset($validated['type'])) {
                    $updateData['type'] = $validated['type'];
                }

                if (isset($validated['start_date'])) {
                    $updateData['start_date'] = Carbon::parse($validated['start_date'])->toDateString();
                }

                if (array_key_exists('end_date', $validated)) {
                    $updateData['end_date'] = ! empty($validated['end_date']) ? Carbon::parse($validated['end_date'])->toDateString() : null;
                }

                if (isset($validated['amount'])) {
                    $dateForConversion = isset($updateData['start_date']) ? Carbon::parse($updateData['start_date']) : Carbon::parse($recurring->start_date);
                    $updateData['amount'] = $currencyService->convertToBaseAmount((float) $validated['amount'], $currency, $dateForConversion);
                }

                if (isset($validated['frequency'])) {
                    $updateData['frequency'] = $validated['frequency'];
                }

                if (isset($validated['interval'])) {
                    $updateData['interval'] = $validated['interval'];
                }

                if (isset($validated['status'])) {
                    $updateData['status'] = $validated['status'];
                }

                if (array_key_exists('note', $validated)) {
                    $updateData['note'] = $validated['note'];
                }

                if (array_key_exists('payment_method', $validated)) {
                    $updateData['payment_method'] = $validated['payment_method'];
                }

                if (isset($validated['visibility'])) {
                    $updateData['visibility'] = $validated['visibility'];
                }

                // Rescheduling behaviour: if schedule parameters changed and template hasn't generated yet or starts in future
                if (isset($validated['start_date']) || isset($validated['frequency']) || isset($validated['interval'])) {
                    $newStartDate = $updateData['start_date'] ?? $recurring->start_date;
                    if (empty($recurring->last_generated_at) || $newStartDate > now()->toDateString()) {
                        $updateData['next_due_date'] = $newStartDate;
                    }
                }

                $recurring->update($updateData);

                return $this->successResponse(
                    $this->formatRecurring($recurring->fresh(['category', 'user'])),
                    'Recurring transaction updated successfully.',
                    200
                );
            }

            // Create new recurring template
            $category = Category::find($validated['category_id']);

            if (! $category || $category->type !== $validated['type']) {
                return $this->errorResponse('Validation error.', 422, [
                    'category_id' => ['The selected category type does not match the recurring transaction type.'],
                ]);
            }

            $startDate = Carbon::parse($validated['start_date'])->toDateString();
            $endDate = ! empty($validated['end_date']) ? Carbon::parse($validated['end_date'])->toDateString() : null;
            $rawAmount = (float) $validated['amount'];
            $baseAmount = $currencyService->convertToBaseAmount($rawAmount, $currency, Carbon::parse($startDate));

            $recurring = RecurringTransaction::create([
                'family_id' => $familyId,
                'user_id' => $user->id,
                'category_id' => $validated['category_id'],
                'type' => $validated['type'],
                'amount' => $baseAmount,
                'frequency' => $validated['frequency'],
                'interval' => $validated['interval'] ?? 1,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_due_date' => $startDate,
                'status' => $validated['status'] ?? 'active',
                'note' => $validated['note'] ?? null,
                'payment_method' => $validated['payment_method'] ?? null,
                'visibility' => $validated['visibility'] ?? 'shared',
            ]);

            return $this->successResponse(
                $this->formatRecurring($recurring->fresh(['category', 'user'])),
                'Recurring transaction created successfully.',
                201
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Save recurring transaction failed');
        }
    }

    /**
     * Display a specific recurring transaction.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $recurring = RecurringTransaction::where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['category', 'user', 'transactions' => fn ($q) => $q->latest('date')->limit(10)])
                ->find($id);

            if (! $recurring) {
                return $this->errorResponse('Recurring transaction not found.', 404);
            }

            return $this->successResponse(
                $this->formatRecurring($recurring),
                'Recurring transaction retrieved successfully.'
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Show recurring transaction failed');
        }
    }

    /**
     * Remove the specified recurring transaction template (soft delete).
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $recurring = RecurringTransaction::where('family_id', $familyId)->find($id);

            if (! $recurring) {
                return $this->errorResponse('Recurring transaction not found.', 404);
            }

            if ($recurring->visibility === 'personal' && $recurring->user_id !== $user->id) {
                return $this->errorResponse('Recurring transaction not found.', 404);
            }

            $recurring->delete();

            return $this->successResponse(null, 'Recurring transaction deleted successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Delete recurring transaction failed');
        }
    }

    /**
     * Restore the specified soft-deleted recurring transaction template.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            $recurring = RecurringTransaction::onlyTrashed()->where('family_id', $familyId)->find($id);

            if (! $recurring) {
                return $this->errorResponse('Recurring transaction not found.', 404);
            }

            if ($recurring->visibility === 'personal' && $recurring->user_id !== $user->id) {
                return $this->errorResponse('Recurring transaction not found.', 404);
            }

            $recurring->restore();

            return $this->successResponse(null, 'Recurring transaction restored successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Restore recurring transaction failed');
        }
    }

    /**
     * Format a recurring transaction model with currency display metadata.
     *
     * @return array<string, mixed>
     */
    protected function formatRecurring(RecurringTransaction $recurring): array
    {
        $currency = tenant('currency') ?: config('app.base_currency', 'USD');
        $currencyService = app(CurrencyService::class);
        $date = Carbon::parse($recurring->start_date ?? now());
        $cents = (int) round((float) $recurring->amount * 100);

        $data = $recurring->toArray();
        $data['display'] = $currencyService->formatWithDisplay($cents, $currency, $date);

        return $data;
    }
}
