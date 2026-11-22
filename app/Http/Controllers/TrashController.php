<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtSettlement;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class TrashController extends Controller
{
    /**
     * Display all soft-deleted records for the active family.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();
            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);

            $format = function ($amount, $date) use ($currencyService, $currency): array {
                $cents = (int) round(((float) $amount) * 100);

                return $currencyService->formatWithDisplay($cents, $currency, Carbon::parse($date ?? now()));
            };

            // 1. Trashed Transactions (strictly scoped to visible records)
            $transactionsQuery = Transaction::onlyTrashed()
                ->where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['category', 'user']);

            // 2. Trashed Debts (strictly scoped to visible records)
            $debtsQuery = Debt::onlyTrashed()
                ->where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['user', 'transaction', 'settlementTransaction', 'settlements.transaction']);

            // 3. Trashed Budgets (household resource)
            $budgetsQuery = Budget::onlyTrashed()
                ->where('family_id', $familyId)
                ->with(['category']);

            // 4. Trashed Custom Categories (household resource)
            $categoriesQuery = Category::onlyTrashed()
                ->where('family_id', $familyId);

            // 5. Trashed Recurring Transactions (strictly scoped to visible records)
            $recurringQuery = RecurringTransaction::onlyTrashed()
                ->where('family_id', $familyId)
                ->visibleTo($user)
                ->with(['category', 'user']);

            // Apply visibility or scope filter if requested
            $visibility = $request->input('visibility');
            $scope = $request->input('scope');

            $isPersonal = ($visibility === 'personal' || $scope === 'personal');
            $isShared = ($visibility === 'shared' || $scope === 'household');

            if ($isPersonal) {
                $transactionsQuery->where('visibility', 'personal')->where('user_id', $user->id);
                $debtsQuery->where('visibility', 'personal')->where('user_id', $user->id);
                $recurringQuery->where('visibility', 'personal')->where('user_id', $user->id);
            } elseif ($isShared) {
                $transactionsQuery->where('visibility', 'shared');
                $debtsQuery->where('visibility', 'shared');
                $recurringQuery->where('visibility', 'shared');
            }

            // Apply type filter if requested
            if ($request->filled('type')) {
                $type = $request->input('type');
                if (in_array($type, ['expense', 'income'])) {
                    $transactionsQuery->where('type', $type);
                    $recurringQuery->where('type', $type);
                    if ($type === 'income') {
                        $budgetsQuery->whereRaw('1 = 0');
                    }
                } elseif (in_array($type, ['lent', 'borrowed'])) {
                    $debtsQuery->where('type', $type);
                    $transactionsQuery->whereRaw('1 = 0');
                    $recurringQuery->whereRaw('1 = 0');
                    $budgetsQuery->whereRaw('1 = 0');
                }
            }

            $transactions = $transactionsQuery->get()->map(function (Transaction $tx) use ($format) {
                $data = $tx->toArray();
                $data['display'] = $format($tx->amount, $tx->date);

                return $data;
            });

            $debts = $debtsQuery->get()->map(function (Debt $debt) use ($format) {
                $data = $debt->toArray();
                $data['display'] = $format($debt->amount, $debt->date);

                return $data;
            });

            $budgets = $isPersonal ? collect([]) : $budgetsQuery->get()->map(function (Budget $b) use ($format) {
                $data = $b->toArray();
                $date = Carbon::createFromDate($b->year, $b->month, 1)->endOfMonth();
                $data['display'] = $format($b->amount, $date);

                return $data;
            });

            $categories = $isPersonal ? collect([]) : $categoriesQuery->get()->map(function (Category $cat) {
                $data = $cat->toArray();
                $data['is_system'] = false;

                return $data;
            });

            $recurring = $recurringQuery->get()->map(function (RecurringTransaction $r) use ($format) {
                $data = $r->toArray();
                $data['display'] = $format($r->amount, $r->start_date);

                return $data;
            });

            return $this->successResponse([
                'transactions' => $transactions,
                'debts' => $debts,
                'budgets' => $budgets,
                'categories' => $categories,
                'recurring_transactions' => $recurring,
            ], 'Trash items retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch trash failed');
        }
    }

    /**
     * Permanently purge all soft-deleted items for the active family.
     */
    public function emptyTrash(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();

            DB::transaction(function () use ($familyId, $user, $request) {
                $visibility = $request->input('visibility');
                $scope = $request->input('scope');

                $isPersonal = ($visibility === 'personal' || $scope === 'personal');
                $isShared = ($visibility === 'shared' || $scope === 'household');

                // Permanently delete trashed transactions visible to this user
                $txQuery = Transaction::onlyTrashed()
                    ->where('family_id', $familyId)
                    ->visibleTo($user);

                if ($isPersonal) {
                    $txQuery->where('visibility', 'personal')->where('user_id', $user->id);
                } elseif ($isShared) {
                    $txQuery->where('visibility', 'shared');
                }
                $txQuery->forceDelete();

                // Permanently delete trashed debts & settlements visible to this user
                $debtsQuery = Debt::onlyTrashed()
                    ->where('family_id', $familyId)
                    ->visibleTo($user);

                if ($isPersonal) {
                    $debtsQuery->where('visibility', 'personal')->where('user_id', $user->id);
                } elseif ($isShared) {
                    $debtsQuery->where('visibility', 'shared');
                }

                $trashedDebts = $debtsQuery->get();
                foreach ($trashedDebts as $debt) {
                    DebtSettlement::onlyTrashed()->where('debt_id', $debt->id)->forceDelete();
                    $debt->forceDelete();
                }

                // Permanently delete trashed recurring transactions visible to this user
                $recurringQuery = RecurringTransaction::onlyTrashed()
                    ->where('family_id', $familyId)
                    ->visibleTo($user);

                if ($isPersonal) {
                    $recurringQuery->where('visibility', 'personal')->where('user_id', $user->id);
                } elseif ($isShared) {
                    $recurringQuery->where('visibility', 'shared');
                }
                $recurringQuery->forceDelete();

                // Permanently delete trashed household items only when not in personal scope
                if (! $isPersonal) {
                    Budget::onlyTrashed()
                        ->where('family_id', $familyId)
                        ->forceDelete();

                    Category::onlyTrashed()
                        ->where('family_id', $familyId)
                        ->forceDelete();
                }
            });

            return $this->successResponse(null, 'Trash permanently emptied successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Empty trash failed');
        }
    }
}
