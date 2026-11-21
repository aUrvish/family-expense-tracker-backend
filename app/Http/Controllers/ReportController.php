<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\CurrencyService;
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

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);
            $decimals = $currencyService->getCurrencyModel($currency)?->decimal_places ?? 2;

            // 1. Base query for active household / personal expenses
            $baseQuery = Transaction::where('family_id', $familyId)
                ->where('type', 'expense');

            if ($isPersonal) {
                $baseQuery->where('user_id', $user->id);
            } else {
                $baseQuery->where('visibility', 'shared');
            }

            // 2. Monthly transactions with categories
            $transactions = (clone $baseQuery)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->with('category')
                ->get();

            $totalConvertedCents = 0;
            $categoryGroups = [];
            $weeklyCents = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

            foreach ($transactions as $tx) {
                $cents = (int) round(((float) $tx->amount) * 100);
                $txDate = Carbon::parse($tx->date);
                $convertedCents = $currencyService->convert($cents, $currency, $txDate);
                $totalConvertedCents += $convertedCents;

                // Category grouping
                $category = $tx->category;
                $categoryId = $category?->id ?? 0;
                if (! isset($categoryGroups[$categoryId])) {
                    $categoryGroups[$categoryId] = [
                        'id' => $categoryId,
                        'name' => $category?->name ?? 'Other',
                        'slug' => $category?->slug ?? 'other',
                        'color' => $category?->color ?? '#6B7280',
                        'cents' => 0,
                    ];
                }
                $categoryGroups[$categoryId]['cents'] += $convertedCents;

                // Weekly bucket (Week 1: 1-7, Week 2: 8-14, Week 3: 15-21, Week 4: 22+)
                $day = $txDate->day;
                $weekIndex = $day <= 7 ? 1 : ($day <= 14 ? 2 : ($day <= 21 ? 3 : 4));
                $weeklyCents[$weekIndex] += $convertedCents;
            }

            // 3. Category distribution
            $byCategory = collect($categoryGroups)->map(function ($cat) use ($totalConvertedCents, $currencyService, $currency, $decimals) {
                $amount = round($cat['cents'] / (10 ** $decimals), $decimals);

                return [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'color' => $cat['color'],
                    'amount' => $amount,
                    'display' => $currencyService->formatConverted($cat['cents'], $currency),
                    'percentage' => $totalConvertedCents > 0 ? round(($cat['cents'] / $totalConvertedCents) * 100, 2) : 0.0,
                ];
            })->sortByDesc('amount')->values()->all();

            // 4. Weekly breakdown
            $weeklyBreakdown = [
                ['label' => 'Week 1', 'percentage' => $totalConvertedCents > 0 ? round(($weeklyCents[1] / $totalConvertedCents) * 100, 2) : 0.0],
                ['label' => 'Week 2', 'percentage' => $totalConvertedCents > 0 ? round(($weeklyCents[2] / $totalConvertedCents) * 100, 2) : 0.0],
                ['label' => 'Week 3', 'percentage' => $totalConvertedCents > 0 ? round(($weeklyCents[3] / $totalConvertedCents) * 100, 2) : 0.0],
                ['label' => 'Week 4', 'percentage' => $totalConvertedCents > 0 ? round(($weeklyCents[4] / $totalConvertedCents) * 100, 2) : 0.0],
            ];

            // 5. Previous month comparison
            $prevDate = Carbon::createFromDate($year, $month, 1)->subMonth();
            $prevTransactions = (clone $baseQuery)
                ->whereYear('date', $prevDate->year)
                ->whereMonth('date', $prevDate->month)
                ->get();

            $prevCents = $prevTransactions->sum(function ($tx) use ($currencyService, $currency) {
                return $currencyService->convert((int) round(((float) $tx->amount) * 100), $currency, Carbon::parse($tx->date));
            });

            $comparison = [
                'previous_month' => $prevDate->format('F'),
                'previous_total' => round($prevCents / (10 ** $decimals), $decimals),
                'display' => $currencyService->formatConverted($prevCents, $currency),
            ];

            $totalSpend = round($totalConvertedCents / (10 ** $decimals), $decimals);

            return $this->successResponse([
                'month' => Carbon::createFromDate($year, $month, 1)->format('F'),
                'year' => $year,
                'scope' => $isPersonal ? 'personal' : 'household',
                'currency' => $currency,
                'total_spend' => $totalSpend,
                'display' => $currencyService->formatConverted($totalConvertedCents, $currency),
                'comparison' => $comparison,
                'weekly_breakdown' => $weeklyBreakdown,
                'by_category' => $byCategory,
            ], 'Report retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch report failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Export report data as a downloadable CSV with converted amounts and currency context.
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

            $query = Transaction::where('family_id', $familyId)
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

            $currency = tenant('currency') ?: config('app.base_currency', 'USD');
            $currencyService = app(CurrencyService::class);
            $decimals = $currencyService->getCurrencyModel($currency)?->decimal_places ?? 2;
            $baseCurrency = strtoupper((string) config('app.base_currency', 'USD'));

            $fileName = "report-{$monthName}-{$year}.csv";

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ];

            $callback = function () use ($transactions, $currencyService, $currency, $decimals, $baseCurrency) {
                $file = fopen('php://output', 'w');
                fputcsv($file, [
                    'ID',
                    'Date',
                    'Category',
                    'Type',
                    'Amount',
                    'Currency',
                    'Formatted',
                    'Base Amount',
                    'Base Currency',
                    'Note',
                    'Visibility',
                    'User',
                ]);

                foreach ($transactions as $tx) {
                    $cents = (int) round(((float) $tx->amount) * 100);
                    $display = $currencyService->formatWithDisplay($cents, $currency, Carbon::parse($tx->date));
                    $convertedAmount = round($display['amount_cents'] / (10 ** $decimals), $decimals);

                    fputcsv($file, [
                        $tx->id,
                        $tx->date,
                        $tx->category?->name ?? 'Expense',
                        $tx->type,
                        $convertedAmount,
                        $currency,
                        $display['formatted'],
                        $tx->amount,
                        $baseCurrency,
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
