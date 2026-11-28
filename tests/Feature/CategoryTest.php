<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_categories(): void
    {
        $response = $this->getJson('/api/category');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_categories_list(): void
    {
        $family = Family::create(['name' => 'Test Family']);

        Category::create([
            'name' => 'Grocery',
            'slug' => 'grocery',
            'icon' => 'shopping-cart',
            'color' => '#10B981',
        ]);

        Category::create([
            'name' => 'Fuel',
            'slug' => 'fuel',
            'icon' => 'fuel',
            'color' => '#EC4899',
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/category');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Categories fetched successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'type',
                        'icon',
                        'color',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_category_seeder_is_idempotent_and_does_not_duplicate_records(): void
    {
        $this->seed(CategorySeeder::class);
        $initialCount = Category::count();

        $this->assertGreaterThanOrEqual(16, $initialCount);
        $this->assertDatabaseHas('categories', ['slug' => 'grocery']);
        $this->assertDatabaseHas('categories', ['slug' => 'bills']);
        $this->assertDatabaseHas('categories', ['slug' => 'recharge']);
        $this->assertDatabaseHas('categories', ['slug' => 'subscription']);
        $this->assertDatabaseHas('categories', ['slug' => 'medical']);
        $this->assertDatabaseHas('categories', ['slug' => 'education']);
        $this->assertDatabaseHas('categories', ['slug' => 'fuel']);

        // Running the seeder a second time should not duplicate any categories
        $this->seed(CategorySeeder::class);
        $this->assertEquals($initialCount, Category::count());
    }

    public function test_categories_require_family_context(): void
    {
        $this->seed(CategorySeeder::class);

        $family = Family::create(['name' => 'Smith Family']);
        $user = User::create([
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);

        $token = $user->createToken('auth_token')->plainTextToken;

        // 1. Without X-Family-Id header -> 400
        $responseWithoutHeader = $this->withToken($token)
            ->getJson('/api/category');

        $responseWithoutHeader->assertStatus(400);

        // 2. With X-Family-Id header -> 200
        $responseWithHeader = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/category');

        $responseWithHeader->assertStatus(200)
            ->assertJsonPath('status', true);
    }

    public function test_categories_index_filters_by_type_and_returns_all_without_type(): void
    {
        $this->seed(CategorySeeder::class);

        $family = Family::create(['name' => 'Filter Family']);
        $user = User::create([
            'name' => 'Filter User',
            'email' => 'filter@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // Without type -> all 24 seeded categories (16 expense + 8 income)
        $allRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/category');

        $allRes->assertStatus(200)
            ->assertJsonCount(24, 'data');

        // Filter by type=expense -> 16
        $expenseRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/category?type=expense');

        $expenseRes->assertStatus(200)
            ->assertJsonCount(16, 'data');
        $this->assertTrue(collect($expenseRes->json('data'))->every(fn ($c) => $c['type'] === 'expense'));

        // Filter by type=income -> 8
        $incomeRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/category?type=income');

        $incomeRes->assertStatus(200)
            ->assertJsonCount(8, 'data');
        $this->assertTrue(collect($incomeRes->json('data'))->every(fn ($c) => $c['type'] === 'income'));
    }

    public function test_custom_income_category_creation_duplicate_name_scoping_and_type_lock_when_in_use(): void
    {
        $this->seed(CategorySeeder::class);

        $family = Family::create(['name' => 'Custom Cat Family']);
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner.cat@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // 1. Create custom income category
        $createIncomeRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/category/save', [
                'name' => 'Bonus',
                'type' => 'income',
                'icon' => 'award',
                'color' => '#10B981',
            ]);

        $createIncomeRes->assertStatus(201)
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.name', 'Bonus');

        $bonusId = $createIncomeRes->json('data.id');

        // 2. Same name allowed across different types ("Gifts" exists as expense, create "Gifts" as income)
        $sameNameDiffTypeRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/category/save', [
                'name' => 'Gifts',
                'type' => 'income',
            ]);

        $sameNameDiffTypeRes->assertStatus(201)
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.name', 'Gifts');

        // 3. Same name in the same type is rejected
        $duplicateSameTypeRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/category/save', [
                'name' => 'Bonus',
                'type' => 'income',
            ]);

        $duplicateSameTypeRes->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 4. Cannot change type when category is in use by a transaction
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $bonusId,
            'type' => 'income',
            'amount' => 500,
            'date' => now()->toDateString(),
        ]);

        $changeTypeRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/category/save', [
                'id' => $bonusId,
                'name' => 'Bonus',
                'type' => 'expense',
            ]);

        $changeTypeRes->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }
}
