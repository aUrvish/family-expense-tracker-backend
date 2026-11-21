<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Display the consolidated dashboard data for household and personal spending.
     * All transactions are converted on-the-fly using the exchange rate of their individual transaction dates.
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
            $decimals = $currencyService->getCurrencyModel($currency)?->decimal_places ?? 2;

            $month = $request->integer('month', now()->month);
            $year = $request->integer('year', now()->year);
            $monthName = Carbon::createFromDate($year, $month, 1)->format('F');

            // Helper to convert transactions by their individual transaction dates
            $convertTransactionsTotal = function ($txs) use ($currencyService, $currency, $decimals): array {
                $totalCents = 0;
                foreach ($txs as $tx) {
                    $c = (int) round(((float) $tx->amount) * 100);
                    $txDate = Carbon::parse($tx->date);
                    $totalCents += $currencyService->convert($c, $currency, $txDate);
                }

                $units = round($totalCents / (10 ** $decimals), $decimals);

                return [
                    'amount' => $units,
                    'display' => $currencyService->formatConverted($totalCents, $currency),
                ];
            };

            // 1. Household shared spend for the given month (converting each on its date)
            $householdTxs = Transaction::where('family_id', $familyId)
                ->where('visibility', 'shared')
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get(['amount', 'date']);

            $household = $convertTransactionsTotal($householdTxs);

            // 2. Household spend by member
            $membersSpend = [];
            $familyMembers = User::where('family_id', $familyId)->get();

            foreach ($familyMembers as $member) {
                $memberTxs = Transaction::where('family_id', $familyId)
                    ->where('user_id', $member->id)
                    ->where('visibility', 'shared')
                    ->where('type', 'expense')
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->get(['amount', 'date']);

                $spend = $convertTransactionsTotal($memberTxs);
                $isCurrentUser = $member->id === $user->id;

                $membersSpend[] = [
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'display_name' => $isCurrentUser ? 'You' : $member->name,
                    'avatar_initial' => strtoupper(substr($member->name, 0, 1)),
                    'is_current_user' => $isCurrentUser,
                    'amount' => $spend['amount'],
                    'display' => $spend['display'],
                ];
            }

            usort($membersSpend, function ($a, $b) {
                if ($a['is_current_user']) {
                    return -1;
                }
                if ($b['is_current_user']) {
                    return 1;
                }

                return $b['amount'] <=> $a['amount'];
            });

            // 3. Personal spend for the current user (converting each on its date)
            $personalTxs = Transaction::where('family_id', $familyId)
                ->where('user_id', $user->id)
                ->where('visibility', 'personal')
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get(['amount', 'date']);

            $personal = $convertTransactionsTotal($personalTxs);

            // Helper to format a recent transaction
            $formatRecent = function ($tx) use ($currencyService, $currency): array {
                $cents = (int) round((float) $tx->amount * 100);
                $date = Carbon::parse($tx->date ?? now());

                return [
                    'id' => $tx->id,
                    'title' => $tx->note ?: ($tx->category?->name ?? 'Expense'),
                    'note' => $tx->note,
                    'amount' => (float) $tx->amount,
                    'display' => $currencyService->formatWithDisplay($cents, $currency, $date),
                    'date' => $tx->date,
                    'user_name' => $tx->user?->name,
                    'category' => $tx->category,
                ];
            };

            // 4. Recent transactions
            $recentHouseholdTransactions = Transaction::where('family_id', $familyId)
                ->where('visibility', 'shared')
                ->where('type', 'expense')
                ->with(['category', 'user'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map($formatRecent);

            $recentPersonalTransactions = Transaction::where('family_id', $familyId)
                ->where('user_id', $user->id)
                ->where('visibility', 'personal')
                ->where('type', 'expense')
                ->with(['category'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map($formatRecent);

            $data = [
                'month' => $monthName,
                'currency' => $currency,
                'household' => [
                    'total_spend' => $household['amount'],
                    'display' => $household['display'],
                    'members' => $membersSpend,
                    'recent_transactions' => $recentHouseholdTransactions,
                ],
                'personal' => [
                    'total_spend' => $personal['amount'],
                    'display' => $personal['display'],
                    'recent_transactions' => $recentPersonalTransactions,
                ],
            ];

            return $this->successResponse($data, 'Dashboard data retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch dashboard data failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
