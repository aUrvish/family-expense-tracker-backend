<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

            // 1. Fetch budgets for active household and selected month
            $budgets = Budget::where('family_id', $familyId)
                ->where('month', $month)
                ->where('year', $year)
                ->with(['category'])
                ->get();

            // 2. Aggregate shared expenses directly in SQL (no Eloquent model hydration)
            $expenses = Transaction::where('family_id', $familyId)
                ->where('visibility', 'shared')
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->groupBy('category_id')
                ->selectRaw('category_id, SUM(amount) as total')
                ->pluck('total', 'category_id');

            // 3. Format category budgets
            $categories = $budgets->map(function ($budget) use ($expenses) {
                $spent = (float) ($expenses[$budget->category_id] ?? 0);
                $budgetAmount = (float) $budget->amount;
                $percentage = $budgetAmount > 0 ? round(($spent / $budgetAmount) * 100, 2) : 0.0;
                $status = $spent > $budgetAmount ? 'over' : ($percentage >= 90 ? 'near_limit' : 'on_track');

                return [
                    'id' => $budget->id,
                    'category_id' => $budget->category_id,
                    'category' => $budget->category,
                    'budget' => $budgetAmount,
                    'spent' => $spent,
                    'remaining' => max(0.0, $budgetAmount - $spent),
                    'percentage' => $percentage,
                    'status' => $status,
                    'over_amount' => max(0.0, round($spent - $budgetAmount, 2)),
                ];
            });

            // 4. Overall household budget summary
            $totalBudget = (float) $categories->sum('budget');
            $totalSpent = (float) $expenses->sum();
            $percentage = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 2) : 0.0;

            $data = [
                'month' => Carbon::createFromDate($year, $month, 1)->format('F'),
                'year' => $year,
                'summary' => [
                    'total_budget' => $totalBudget,
                    'total_spent' => $totalSpent,
                    'remaining_budget' => max(0.0, $totalBudget - $totalSpent),
                    'over_amount' => max(0.0, round($totalSpent - $totalBudget, 2)),
                    'percentage' => $percentage,
                    'status' => $totalSpent > $totalBudget ? 'over' : ($percentage >= 90 ? 'near_limit' : 'on_track'),
                ],
                'by_category' => $categories,
            ];

            return $this->successResponse($data, 'Budgets retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch budgets failed: '.$e->getMessage());

            return $this->errorResponse();
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
                'category_id' => ['required', 'integer', 'exists:categories,id'],
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

            if (! empty($validated['id'])) {
                $budget = Budget::where('family_id', $familyId)->find($validated['id']);

                if (! $budget) {
                    return $this->errorResponse('Budget record not found.', 404);
                }

                $budget->update([
                    'category_id' => $validated['category_id'],
                    'amount' => $validated['amount'],
                    'month' => $month,
                    'year' => $year,
                ]);

                return $this->successResponse(
                    $budget->fresh(['category']),
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
                    'amount' => $validated['amount'],
                ]
            );

            return $this->successResponse(
                $budget->fresh(['category']),
                'Budget saved successfully.',
                201
            );
        } catch (Throwable $e) {
            Log::error('Save budget failed: '.$e->getMessage());

            return $this->errorResponse();
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
            Log::error('Delete budget failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
