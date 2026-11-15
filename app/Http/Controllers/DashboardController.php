<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Display the consolidated dashboard data for household and personal spending.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();
            $family = Family::find($familyId);

            if (! $family) {
                return $this->errorResponse('Family not found.', 404);
            }

            // Month and year selection (defaults to current month and year)
            $month = $request->integer('month', now()->month);
            $year = $request->integer('year', now()->year);
            $monthName = Carbon::createFromDate($year, $month, 1)->format('F');

            // 1. Household shared spend for the given month
            $householdSharedQuery = Transaction::query()
                ->where('family_id', $familyId)
                ->where('visibility', 'shared')
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month);

            $householdTotalSpend = (float) (clone $householdSharedQuery)->sum('amount');

            // 2. Household spend by member
            $familyMembers = $family->users()->get();
            $membersSpend = [];

            foreach ($familyMembers as $member) {
                $memberSpend = (float) Transaction::query()
                    ->where('family_id', $familyId)
                    ->where('user_id', $member->id)
                    ->where('visibility', 'shared')
                    ->where('type', 'expense')
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month)
                    ->sum('amount');

                $isCurrentUser = $member->id === $user->id;

                $membersSpend[] = [
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'display_name' => $isCurrentUser ? 'You' : $member->name,
                    'avatar_initial' => strtoupper(substr($member->name, 0, 1)),
                    'is_current_user' => $isCurrentUser,
                    'amount' => $memberSpend,
                ];
            }

            // Order members: current user first, then others by spend descending
            usort($membersSpend, function ($a, $b) {
                if ($a['is_current_user']) {
                    return -1;
                }
                if ($b['is_current_user']) {
                    return 1;
                }

                return $b['amount'] <=> $a['amount'];
            });

            // 3. Current user's personal spend for the given month
            $myPersonalSpend = (float) Transaction::query()
                ->where('family_id', $familyId)
                ->where('user_id', $user->id)
                ->where('visibility', 'personal')
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->sum('amount');

            // 4. Recent household transactions (Top 5)
            $recentHouseholdTransactions = Transaction::query()
                ->where('family_id', $familyId)
                ->where('visibility', 'shared')
                ->where('type', 'expense')
                ->with(['category', 'user'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'title' => $tx->category?->name ?? 'Expense',
                        'note' => $tx->note,
                        'amount' => (float) $tx->amount,
                        'date' => $tx->date->toDateString(),
                        'user_name' => $tx->user?->name,
                        'category' => $tx->category,
                    ];
                });

            // 5. Recent personal transactions for the current user (Top 5)
            $recentPersonalTransactions = Transaction::query()
                ->where('family_id', $familyId)
                ->where('user_id', $user->id)
                ->where('visibility', 'personal')
                ->where('type', 'expense')
                ->with(['category'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(function ($tx) {
                    $title = ! empty($tx->note) ? $tx->note : ($tx->category?->name ?? 'Personal Expense');

                    return [
                        'id' => $tx->id,
                        'title' => $title,
                        'note' => $tx->note,
                        'amount' => (float) $tx->amount,
                        'date' => $tx->date->toDateString(),
                        'category' => $tx->category,
                    ];
                });

            $data = [
                'month' => $monthName,
                'household' => [
                    'total_spend' => $householdTotalSpend,
                    'members' => $membersSpend,
                    'recent_transactions' => $recentHouseholdTransactions,
                ],
                'personal' => [
                    'total_spend' => $myPersonalSpend,
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
