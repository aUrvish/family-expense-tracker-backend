<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_budgets(): void
    {
        $this->getJson('/api/budgets')->assertStatus(401);
    }

    public function test_user_cannot_access_budgets_without_family_header(): void
    {
        $user = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/budgets')
            ->assertStatus(400);
    }

    public function test_user_can_retrieve_budgets_matching_screen_calculations(): void
    {
        $family = Family::create(['name' => 'Sharma Household']);

        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $groceries = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'cart', 'color' => '#EAB308']);
        $medical = Category::create(['name' => 'Medical', 'slug' => 'medical', 'icon' => 'plus', 'color' => '#EF4444']);
        $bills = Category::create(['name' => 'Bills & utilities', 'slug' => 'bills-utilities', 'icon' => 'bolt', 'color' => '#10B981']);
        $school = Category::create(['name' => 'School', 'slug' => 'school', 'icon' => 'book', 'color' => '#10B981']);
        $other = Category::create(['name' => 'Other', 'slug' => 'other', 'icon' => 'dots', 'color' => '#10B981']);

        $month = now()->month;
        $year = now()->year;

        // Set budgets matching the design: Total 65,000
        Budget::create(['family_id' => $family->id, 'category_id' => $groceries->id, 'amount' => 15000, 'month' => $month, 'year' => $year]);
        Budget::create(['family_id' => $family->id, 'category_id' => $medical->id, 'amount' => 8000, 'month' => $month, 'year' => $year]);
        Budget::create(['family_id' => $family->id, 'category_id' => $bills->id, 'amount' => 14000, 'month' => $month, 'year' => $year]);
        Budget::create(['family_id' => $family->id, 'category_id' => $school->id, 'amount' => 12000, 'month' => $month, 'year' => $year]);
        Budget::create(['family_id' => $family->id, 'category_id' => $other->id, 'amount' => 16000, 'month' => $month, 'year' => $year]);

        $today = now()->toDateString();

        // Create transactions matching the design: Total 48,260
        Transaction::create(['family_id' => $family->id, 'user_id' => $user->id, 'category_id' => $groceries->id, 'type' => 'expense', 'amount' => 14800, 'date' => $today, 'visibility' => 'shared']);
        Transaction::create(['family_id' => $family->id, 'user_id' => $user->id, 'category_id' => $medical->id, 'type' => 'expense', 'amount' => 8400, 'date' => $today, 'visibility' => 'shared']);
        Transaction::create(['family_id' => $family->id, 'user_id' => $user->id, 'category_id' => $bills->id, 'type' => 'expense', 'amount' => 11200, 'date' => $today, 'visibility' => 'shared']);
        Transaction::create(['family_id' => $family->id, 'user_id' => $user->id, 'category_id' => $school->id, 'type' => 'expense', 'amount' => 9600, 'date' => $today, 'visibility' => 'shared']);
        Transaction::create(['family_id' => $family->id, 'user_id' => $user->id, 'category_id' => $other->id, 'type' => 'expense', 'amount' => 4260, 'date' => $today, 'visibility' => 'shared']);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson("/api/budgets?month={$month}&year={$year}");

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.summary.total_budget', 65000)
            ->assertJsonPath('data.summary.total_spent', 48260)
            ->assertJsonPath('data.summary.remaining_budget', 16740)
            ->assertJsonPath('data.summary.percentage', 74.25)
            ->assertJsonPath('data.summary.status', 'on_track');

        $categories = collect($response->json('data.by_category'));

        // Groceries: 14800 of 15000 (98.67% >= 80% => near_limit)
        $groceriesItem = $categories->firstWhere('category_id', $groceries->id);
        $this->assertEquals(15000, $groceriesItem['budget']);
        $this->assertEquals(14800, $groceriesItem['spent']);
        $this->assertEquals(200, $groceriesItem['remaining']);
        $this->assertEquals('near_limit', $groceriesItem['status']);
        $this->assertEquals(0, $groceriesItem['over_amount']);

        // Medical: 8400 of 8000 (spent > budget => over by 400)
        $medicalItem = $categories->firstWhere('category_id', $medical->id);
        $this->assertEquals(8000, $medicalItem['budget']);
        $this->assertEquals(8400, $medicalItem['spent']);
        $this->assertEquals(0, $medicalItem['remaining']);
        $this->assertEquals('over', $medicalItem['status']);
        $this->assertEquals(400, $medicalItem['over_amount']);

        // Other: 4260 of 16000 (< 80% => on_track)
        $otherItem = $categories->firstWhere('category_id', $other->id);
        $this->assertEquals(16000, $otherItem['budget']);
        $this->assertEquals(4260, $otherItem['spent']);
        $this->assertEquals(11740, $otherItem['remaining']);
        $this->assertEquals('on_track', $otherItem['status']);
    }

    public function test_user_can_save_and_update_category_budget(): void
    {
        $family = Family::create(['name' => 'Sharma Household']);

        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'cart', 'color' => '#10B981']);

        // 1. Create budget
        $createResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/budget/save', [
                'category_id' => $category->id,
                'amount' => 15000,
                'month' => 11,
                'year' => 2026,
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.amount', '15000.00')
            ->assertJsonPath('data.month', 11)
            ->assertJsonPath('data.year', 2026);

        $budgetId = $createResponse->json('data.id');

        // 2. Update budget
        $updateResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/budget/save', [
                'id' => $budgetId,
                'category_id' => $category->id,
                'amount' => 18000,
                'month' => 11,
                'year' => 2026,
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.amount', '18000.00');

        $this->assertDatabaseHas('budgets', [
            'id' => $budgetId,
            'amount' => 18000,
        ]);
    }

    public function test_user_can_delete_category_budget(): void
    {
        $family = Family::create(['name' => 'Sharma Household']);

        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'cart', 'color' => '#10B981']);

        $budget = Budget::create([
            'family_id' => $family->id,
            'category_id' => $category->id,
            'amount' => 10000,
            'month' => 11,
            'year' => 2026,
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->deleteJson("/api/budget/{$budget->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Budget deleted successfully.');

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }
}
