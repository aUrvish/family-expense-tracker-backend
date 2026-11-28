<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Debt;
use App\Models\ExchangeRate;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected Family $family;

    protected User $user;

    protected string $token;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        Currency::create([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        Currency::create([
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'symbol' => '¥',
            'decimal_places' => 0,
            'is_active' => true,
        ]);

        $this->family = Family::create([
            'name' => 'Regression Family',
            'currency' => 'EUR',
        ]);

        $this->user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ]);
        $this->family->users()->attach($this->user->id, ['role' => 'owner']);

        $this->token = $this->user->createToken('auth_token')->plainTextToken;

        $this->category = Category::create([
            'name' => 'Food & Dining',
            'slug' => 'food-dining',
            'icon' => 'utensils',
            'color' => '#F59E0B',
        ]);

        Category::create([
            'name' => 'Others',
            'slug' => 'others',
            'icon' => 'tag',
            'color' => '#6B7280',
        ]);
    }

    public function test_transaction_created_in_family_currency_converts_to_base_currency_for_storage(): void
    {
        // 1 USD = 0.95 EUR on 2026-11-20
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        // User posts 95.00 EUR
        $response = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 95.00,
                'category_id' => $this->category->id,
                'date' => '2026-11-20',
                'note' => 'Dinner in Paris',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.display.currency', 'EUR')
            ->assertJsonPath('data.display.amount_cents', 9500)
            ->assertJsonPath('data.display.formatted', '€95.00');

        // Verify stored database record is in base USD: 95.00 EUR / 0.95 = 100.00 USD
        $this->assertDatabaseHas('transactions', [
            'family_id' => $this->family->id,
            'note' => 'Dinner in Paris',
            'amount' => '100.00',
        ]);
    }

    public function test_budget_created_in_family_currency_converts_to_base_currency_for_storage(): void
    {
        // 1 USD = 0.95 EUR on 2026-11-01
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-01',
        ]);

        // User posts budget 190.00 EUR for Nov 2026
        $response = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/budget/save', [
                'category_id' => $this->category->id,
                'amount' => 190.00,
                'month' => 11,
                'year' => 2026,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.display.currency', 'EUR')
            ->assertJsonPath('data.display.amount_cents', 19000)
            ->assertJsonPath('data.display.formatted', '€190.00');

        // Base USD stored: 190.00 / 0.95 = 200.00 USD
        $this->assertDatabaseHas('budgets', [
            'family_id' => $this->family->id,
            'category_id' => $this->category->id,
            'amount' => '200.00',
        ]);
    }

    public function test_debt_created_in_family_currency_converts_to_base_and_settlement_creates_base_transaction(): void
    {
        // 1 USD = 0.95 EUR on 2026-11-20
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        // User posts debt lent 95.00 EUR to Bob
        $saveResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Bob',
                'type' => 'lent',
                'amount' => 95.00,
                'date' => '2026-11-20',
                'category_id' => $this->category->id,
            ]);

        $saveResponse->assertStatus(201)
            ->assertJsonPath('data.display.currency', 'EUR')
            ->assertJsonPath('data.display.amount_cents', 9500)
            ->assertJsonPath('data.display.formatted', '€95.00');

        $debtId = $saveResponse->json('data.id');

        // Verify stored in base USD (100.00 USD)
        $this->assertDatabaseHas('debts', [
            'id' => $debtId,
            'amount' => '100.00',
        ]);

        $this->assertDatabaseHas('transactions', [
            'family_id' => $this->family->id,
            'amount' => '100.00',
            'type' => 'expense',
        ]);

        // Settle debt
        $settleResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debtId}/settle");

        $settleResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.display.currency', 'EUR')
            ->assertJsonPath('data.display.amount_cents', 9500)
            ->assertJsonPath('data.settlement_transaction.display.formatted', '€95.00');

        // Verify settlement transaction created in base USD
        $this->assertDatabaseHas('transactions', [
            'family_id' => $this->family->id,
            'amount' => '100.00',
            'type' => 'income',
        ]);
    }

    public function test_missing_historical_rates_safely_falls_back_without_throwing_error(): void
    {
        // Rate exists on 2026-11-20
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-20',
        ]);

        // Transaction dated 2026-11-05 has no rate on 2026-11-05
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => 50.00,
            'date' => '2026-11-05',
            'visibility' => 'shared',
        ]);

        $response = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/transaction');

        $response->assertStatus(200)
            ->assertJsonPath('data.data.0.display.currency', 'EUR')
            ->assertJsonPath('data.data.0.display.amount_cents', 4750);
    }

    public function test_historical_reporting_converts_each_transaction_on_its_own_date_when_rates_change(): void
    {
        // Nov 5: 1 USD = 0.90 EUR
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.90000000,
            'date' => '2026-11-05',
        ]);

        // Nov 25: 1 USD = 0.95 EUR
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.95000000,
            'date' => '2026-11-25',
        ]);

        // Tx 1 on Nov 5: $100 USD base -> converts to €90.00 EUR
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => '2026-11-05',
            'visibility' => 'shared',
        ]);

        // Tx 2 on Nov 25: $100 USD base -> converts to €95.00 EUR
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => '2026-11-25',
            'visibility' => 'shared',
        ]);

        // Total should be €90.00 + €95.00 = €185.00 EUR (not $200 * 0.95 = €190 EUR!)
        $response = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/reports?month=11&year=2026');

        $response->assertStatus(200)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.total_spend', 185)
            ->assertJsonPath('data.display.amount_cents', 18500)
            ->assertJsonPath('data.display.formatted', '€185.00')
            ->assertJsonPath('data.by_category.0.amount', 185);
    }

    public function test_csv_export_includes_converted_amount_and_currency_context(): void
    {
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.90000000,
            'date' => '2026-11-05',
        ]);

        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => '2026-11-05',
            'visibility' => 'shared',
            'note' => 'Groceries export test',
        ]);

        $response = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->get('/api/reports/export?month=11&year=2026');

        $response->assertStatus(200);

        $content = $response->streamedContent();

        $this->assertStringContainsString('Amount,Currency,Formatted,"Base Amount","Base Currency"', $content);
        $this->assertStringContainsString('90,EUR,€90.00,100.00,USD', $content);
    }
}
