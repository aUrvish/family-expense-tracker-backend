<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Family $family;

    protected User $user;

    protected string $token;

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
            'name' => 'Test Family',
            'currency' => 'USD',
        ]);

        $this->user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $this->family->users()->attach($this->user->id, ['role' => 'owner']);

        $this->token = $this->user->createToken('auth_token')->plainTextToken;
    }

    public function test_family_effective_currency_resolves_correctly(): void
    {
        $this->assertSame('USD', $this->family->effectiveCurrency());

        $this->family->update(['currency' => 'EUR']);
        $this->assertSame('EUR', $this->family->fresh()->effectiveCurrency());
    }

    public function test_changing_family_currency_leaves_expense_rows_strictly_unchanged(): void
    {
        $category = Category::create([
            'name' => 'Groceries',
            'slug' => 'groceries',
            'icon' => 'cart',
            'color' => '#10B981',
        ]);

        $transaction = Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 125.50,
            'date' => '2026-11-20',
            'visibility' => 'shared',
            'note' => 'Original base expense',
        ]);

        // Change family currency to EUR
        $this->family->update(['currency' => 'EUR']);

        // Verify transaction database row is strictly UNCHANGED
        $transaction->refresh();
        $this->assertEquals('125.50', (string) $transaction->amount);

        // Change family currency to JPY
        $this->family->update(['currency' => 'JPY']);

        // Verify transaction database row is STILL strictly UNCHANGED
        $transaction->refresh();
        $this->assertEquals('125.50', (string) $transaction->amount);
    }

    public function test_totals_are_summed_in_base_cents_and_converted_once(): void
    {
        // Exchange rate: 1 USD = 0.90 EUR on 2026-11-01
        ExchangeRate::create([
            'currency' => 'EUR',
            'rate' => 0.90000000,
            'date' => '2026-11-01',
        ]);

        // Set family currency to EUR
        $this->family->update(['currency' => 'EUR']);

        $category = Category::create([
            'name' => 'Groceries',
            'slug' => 'groceries',
            'icon' => 'cart',
            'color' => '#10B981',
        ]);

        // Transaction 1: $10.00 USD
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 10.00,
            'date' => '2026-11-10',
            'visibility' => 'shared',
        ]);

        // Transaction 2: $20.00 USD
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 20.00,
            'date' => '2026-11-15',
            'visibility' => 'shared',
        ]);

        // Total base = 30.00 USD (3000 cents).
        // Converted EUR = 27.00 EUR (2700 cents)

        // 1. Dashboard
        $dashResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/dashboard?month=11&year=2026');

        $dashResponse->assertStatus(200)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.household.total_spend', 27)
            ->assertJsonPath('data.household.display.amount_cents', 2700)
            ->assertJsonPath('data.household.display.formatted', '€27.00');

        // 2. Reports
        $reportResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/reports?month=11&year=2026&scope=household');

        $reportResponse->assertStatus(200)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.total_spend', 27)
            ->assertJsonPath('data.display.amount_cents', 2700)
            ->assertJsonPath('data.display.formatted', '€27.00')
            ->assertJsonPath('data.by_category.0.amount', 27);
    }
}
