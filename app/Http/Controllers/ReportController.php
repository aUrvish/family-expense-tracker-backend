<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReportController extends Controller
{
    /**
     * Display report analytics including monthly spend, comparison, weekly breakdown, and category distribution.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();
            $month = $request->integer('month', now()->month);
            $year = $request->integer('year', now()->year);
            $scope = $request->input('scope', 'household');
            $isPersonal = $scope === 'personal';

            $monthName = Carbon::createFromDate($year, $month, 1)->format('F');

            // 1. Base query for active household / personal expenses
            $baseQuery = Transaction::query()
                ->where('transactions.family_id', $familyId)
                ->where('transactions.type', 'expense');

            if ($isPersonal) {
                $baseQuery->where('transactions.user_id', $user->id);
            } else {
                $baseQuery->where('transactions.visibility', 'shared');
            }

            // 2. Aggregate category spend in the database (efficient: 0 model hydration, scalable to high volume)
            $categoryRows = (clone $baseQuery)
                ->whereYear('transactions.date', $year)
                ->whereMonth('transactions.date', $month)
                ->join('categories', 'transactions.category_id', '=', 'categories.id')
                ->selectRaw('categories.id, categories.name, categories.slug, categories.color, SUM(transactions.amount) as amount')
                ->groupBy('categories.id', 'categories.name', 'categories.slug', 'categories.color')
                ->orderByDesc('amount')
                ->get();

            $totalSpend = (float) $categoryRows->sum('amount');

            $byCategory = $categoryRows->map(function ($row) use ($totalSpend) {
                $amount = (float) $row->amount;

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'color' => $row->color,
                    'amount' => $amount,
                    'percentage' => $totalSpend > 0 ? round(($amount / $totalSpend) * 100, 2) : 0.0,
                ];
            });

            // 3. Previous Month Total Spend (Single aggregated SUM query)
            $prevDate = Carbon::createFromDate($year, $month, 1)->subMonth();
            $prevMonthSpend = (float) (clone $baseQuery)
                ->whereYear('transactions.date', $prevDate->year)
                ->whereMonth('transactions.date', $prevDate->month)
                ->sum('transactions.amount');

            $comparison = [
                'previous_month' => $prevDate->format('F'),
                'previous_total' => $prevMonthSpend,
            ];

            // 4. Weekly Breakdown (4 indexed date-range SUM queries)
            $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
            $weekRanges = [
                ['label' => 'Week 1', 'start' => 1, 'end' => 7],
                ['label' => 'Week 2', 'start' => 8, 'end' => 14],
                ['label' => 'Week 3', 'start' => 15, 'end' => 21],
                ['label' => 'Week 4', 'start' => 22, 'end' => $daysInMonth],
            ];

            $weeklyBreakdown = [];
            foreach ($weekRanges as $range) {
                $startDate = Carbon::createFromDate($year, $month, $range['start'])->toDateString();
                $endDate = Carbon::createFromDate($year, $month, $range['end'])->toDateString();

                $weekSpend = (float) (clone $baseQuery)
                    ->whereBetween('transactions.date', [$startDate, $endDate])
                    ->sum('transactions.amount');

                $weeklyBreakdown[] = [
                    'label' => $range['label'],
                    'percentage' => $totalSpend > 0 ? round(($weekSpend / $totalSpend) * 100, 2) : 0.0,
                ];
            }

            $data = [
                'month' => $monthName,
                'year' => $year,
                'scope' => $isPersonal ? 'personal' : 'household',
                'total_spend' => $totalSpend,
                'comparison' => $comparison,
                'weekly_breakdown' => $weeklyBreakdown,
                'by_category' => $byCategory,
            ];

            return $this->successResponse($data, 'Report retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch report failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Export report data as a downloadable CSV.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $user = $request->user();
            $month = $request->integer('month', now()->month);
            $year = $request->integer('year', now()->year);
            $scope = $request->input('scope', 'household');
            $isPersonal = $scope === 'personal';

            $monthName = Carbon::createFromDate($year, $month, 1)->format('F');

            $query = Transaction::query()
                ->where('family_id', $familyId)
                ->where('type', 'expense')
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->with(['category', 'user'])
                ->orderBy('date');

            if ($isPersonal) {
                $query->where('user_id', $user->id);
            } else {
                $query->where('visibility', 'shared');
            }

            $transactions = $query->get();

            $fileName = "report-{$monthName}-{$year}.csv";

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ];

            $callback = function () use ($transactions) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['ID', 'Date', 'Category', 'Type', 'Amount', 'Note', 'Visibility', 'User']);

                foreach ($transactions as $tx) {
                    fputcsv($file, [
                        $tx->id,
                        $tx->getRawOriginal('date'),
                        $tx->category?->name ?? 'Expense',
                        $tx->type,
                        $tx->amount,
                        $tx->note ?? '',
                        $tx->visibility,
                        $tx->user?->name ?? '',
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } catch (Throwable $e) {
            Log::error('Export report failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
