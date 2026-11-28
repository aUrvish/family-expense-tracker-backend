<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class BudgetController extends Controller
{
    /**
     * Display a listing of category budgets and overall household budget summary.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $month = $request->integer('month', now()->month);
            $year = $request->integer('year', now()->year);
            $date = Carbon::createFromDate($year, $month, 1)->endOfMonth();

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);
            $decimals = $currencyService->getCurrencyModel($currency)?->decimal_places ?? 2;

            // Helper to convert base amount and format display for the month
            $formatSpend = function (float $amount) use ($currencyService, $currency, $date, $decimals): array {
                $cents = (int) round($amount * 100);
                $convertedCents = $currencyService->convert($cents, $currency, $date);

                return [
                    'amount' => round($convertedCents / (10 ** $decimals), $decimals),
                    'display' => $currencyService->formatWithDisplay($cents, $currency, $date),
                ];
            };

            // 1. Fetch budgets for active household and selected month
            $budgets = Budget::where('family_id', $familyId)
                ->where('month', $month)
                ->where('year', $year)
                ->with(['category'])
                ->get();

            // 2. Aggregate shared expenses directly in SQL in base amount
            $expenses = Transaction::where('family_id', $familyId)
                ->where('visibility', 'shared')
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->groupBy('category_id')
                ->selectRaw('category_id, SUM(amount) as total')
                ->pluck('total', 'category_id');

            // 3. Format category budgets
            $categories = $budgets->map(function ($budget) use ($expenses, $formatSpend, $decimals) {
                $spentBase = (float) ($expenses[$budget->category_id] ?? 0);
                $budgetBase = (float) $budget->amount;

                $budgetSpend = $formatSpend($budgetBase);
                $spentSpend = $formatSpend($spentBase);
                $remainingSpend = $formatSpend(max(0.0, $budgetBase - $spentBase));

                $budgetUnits = $budgetSpend['amount'];
                $spentUnits = $spentSpend['amount'];
                $remainingUnits = max(0.0, round($budgetUnits - $spentUnits, $decimals));
                $overUnits = max(0.0, round($spentUnits - $budgetUnits, $decimals));

                $percentage = $budgetBase > 0 ? round(($spentBase / $budgetBase) * 100, 2) : 0.0;
                $status = $spentBase > $budgetBase ? 'over' : ($percentage >= 90 ? 'near_limit' : 'on_track');

                return [
                    'id' => $budget->id,
                    'category_id' => $budget->category_id,
                    'category' => $budget->category,
                    'budget' => $budgetUnits,
                    'spent' => $spentUnits,
                    'remaining' => $remainingUnits,
                    'percentage' => $percentage,
                    'status' => $status,
                    'over_amount' => $overUnits,
                    'display' => [
                        'budget' => $budgetSpend['display'],
                        'spent' => $spentSpend['display'],
                        'remaining' => $remainingSpend['display'],
                    ],
                ];
            });

            // 4. Overall household budget summary
            $totalBudgetBase = (float) $budgets->sum('amount');
            $totalSpentBase = (float) $expenses->sum();

            $totalBudgetSpend = $formatSpend($totalBudgetBase);
            $totalSpentSpend = $formatSpend($totalSpentBase);
            $remainingBudgetSpend = $formatSpend(max(0.0, $totalBudgetBase - $totalSpentBase));

            $totalBudget = $totalBudgetSpend['amount'];
            $totalSpent = $totalSpentSpend['amount'];
            $percentage = $totalBudgetBase > 0 ? round(($totalSpentBase / $totalBudgetBase) * 100, 2) : 0.0;
            $remainingBudget = max(0.0, round($totalBudget - $totalSpent, $decimals));
            $overTotal = max(0.0, round($totalSpent - $totalBudget, $decimals));

            $data = [
                'month' => Carbon::createFromDate($year, $month, 1)->format('F'),
                'year' => $year,
                'currency' => $currency,
                'summary' => [
                    'total_budget' => $totalBudget,
                    'total_spent' => $totalSpent,
                    'remaining_budget' => $remainingBudget,
                    'over_amount' => $overTotal,
                    'percentage' => $percentage,
                    'status' => $totalSpentBase > $totalBudgetBase ? 'over' : ($percentage >= 90 ? 'near_limit' : 'on_track'),
                    'display' => [
                        'total_budget' => $totalBudgetSpend['display'],
                        'total_spent' => $totalSpentSpend['display'],
                        'remaining_budget' => $remainingBudgetSpend['display'],
                    ],
                ],
                'by_category' => $categories,
            ];

            return $this->successResponse($data, 'Budgets retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch budgets failed');
        }
    }

    /**
     * Create or update a category budget.
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $validator = Validator::make($request->all(), [
                'id' => ['nullable', 'integer', 'exists:budgets,id'],
                'category_id' => [
                    'required',
                    'integer',
                    Rule::exists('categories', 'id')->where(function ($query) use ($familyId) {
                        $query->whereNull('deleted_at')
                            ->where('type', 'expense')
                            ->where(function ($q) use ($familyId) {
                                $q->whereNull('family_id')->orWhere('family_id', $familyId);
                            });
                    }),
                ],
                'amount' => ['required', 'numeric', 'gt:0'],
                'month' => ['nullable', 'integer', 'between:1,12'],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $month = $validated['month'] ?? now()->month;
            $year = $validated['year'] ?? now()->year;
            $rawAmount = (float) $validated['amount'];

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);
            $budgetDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();
            $baseAmount = $currencyService->convertToBaseAmount($rawAmount, $currency, $budgetDate);

            if (! empty($validated['id'])) {
                $budget = Budget::where('family_id', $familyId)->find($validated['id']);

                if (! $budget) {
                    return $this->errorResponse('Budget record not found.', 404);
                }

                $budget->update([
                    'category_id' => $validated['category_id'],
                    'amount' => $baseAmount,
                    'month' => $month,
                    'year' => $year,
                ]);

                $budgetData = $budget->fresh(['category'])->toArray();
                $budgetData['display'] = $currencyService->formatWithDisplay((int) round((float) $budget->amount * 100), $currency, $budgetDate);

                return $this->successResponse(
                    $budgetData,
                    'Budget updated successfully.',
                    200
                );
            }

            $budget = Budget::updateOrCreate(
                [
                    'family_id' => $familyId,
                    'category_id' => $validated['category_id'],
                    'month' => $month,
                    'year' => $year,
                ],
                [
                    'amount' => $baseAmount,
                ]
            );

            $budgetData = $budget->fresh(['category'])->toArray();
            $budgetData['display'] = $currencyService->formatWithDisplay((int) round((float) $budget->amount * 100), $currency, $budgetDate);

            return $this->successResponse(
                $budgetData,
                'Budget saved successfully.',
                201
            );
        } catch (Throwable $e) {
            return $this->handleException($e, 'Save budget failed');
        }
    }

    /**
     * Delete a category budget.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $budget = Budget::where('family_id', $familyId)->find($id);

            if (! $budget) {
                return $this->errorResponse('Budget record not found.', 404);
            }

            $budget->delete();

            return $this->successResponse(null, 'Budget deleted successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Delete budget failed');
        }
    }

    /**
     * Restore a soft-deleted category budget.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $budget = Budget::onlyTrashed()->where('family_id', $familyId)->find($id);

            if (! $budget) {
                return $this->errorResponse('Budget record not found.', 404);
            }

            $budget->restore();

            return $this->successResponse(null, 'Budget restored successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Restore budget failed');
        }
    }
}
