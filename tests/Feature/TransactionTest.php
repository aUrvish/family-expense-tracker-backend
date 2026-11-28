<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_save_transaction(): void
    {
        $response = $this->postJson('/api/transaction/save', [
            'type' => 'expense',
            'amount' => 100,
        ]);

        $response->assertStatus(401);
    }

    public function test_transaction_save_fails_without_family_header(): void
    {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/transaction/save', [
            'type' => 'expense',
            'amount' => 100,
        ]);

        $response->assertStatus(400);
    }

    public function test_transaction_save_fails_with_unauthorized_family(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $user = User::create([
            'family_id' => $familyA->id,
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $familyB->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 100,
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_add_expense_transaction_with_valid_data(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Grocery',
            'slug' => 'grocery',
            'icon' => 'shopping-cart',
            'color' => '#10B981',
        ]);

        $payload = [
            'type' => 'expense',
            'amount' => 1500.50,
            'category_id' => $category->id,
            'date' => '2026-11-13',
            'note' => 'Weekly grocery run',
            'payment_method' => 'card',
        ];

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Expense added successfully.')
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.amount', '1500.50')
            ->assertJsonPath('data.family_id', $family->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.category_id', $category->id);

        $this->assertDatabaseHas('transactions', [
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1500.50,
            'date' => '2026-11-13',
            'note' => 'Weekly grocery run',
            'payment_method' => 'card',
        ]);
    }

    public function test_user_can_add_income_transaction_with_valid_data(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Salary',
            'slug' => 'salary',
            'type' => 'income',
            'icon' => 'banknotes',
            'color' => '#22C55E',
        ]);

        $payload = [
            'type' => 'income',
            'amount' => 50000.00,
            'category_id' => $category->id,
            'date' => '2026-11-01',
            'note' => 'Monthly Salary',
            'payment_method' => 'bank_transfer',
        ];

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Income added successfully.')
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.amount', '50000.00')
            ->assertJsonPath('data.category_id', $category->id);

        $this->assertDatabaseHas('transactions', [
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount' => 50000.00,
            'note' => 'Monthly Salary',
        ]);
    }

    public function test_user_can_update_existing_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $groceryCategory = Category::create([
            'name' => 'Grocery',
            'slug' => 'grocery',
            'icon' => 'shopping-cart',
            'color' => '#10B981',
        ]);

        $foodCategory = Category::create([
            'name' => 'Food',
            'slug' => 'food',
            'icon' => 'utensils',
            'color' => '#F97316',
        ]);

        $transaction = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $groceryCategory->id,
            'type' => 'expense',
            'amount' => 500.00,
            'date' => '2026-11-10',
            'note' => 'Old note',
            'payment_method' => 'cash',
        ]);

        $updatePayload = [
            'id' => $transaction->id,
            'type' => 'expense',
            'amount' => 750.00,
            'category_id' => $foodCategory->id,
            'date' => '2026-11-12',
            'note' => 'Updated note',
            'payment_method' => 'upi',
        ];

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Expense updated successfully.')
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.amount', '750.00')
            ->assertJsonPath('data.category_id', $foodCategory->id)
            ->assertJsonPath('data.note', 'Updated note')
            ->assertJsonPath('data.payment_method', 'upi');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'amount' => 750.00,
            'category_id' => $foodCategory->id,
            'note' => 'Updated note',
            'payment_method' => 'upi',
        ]);

        $this->assertEquals(1, Transaction::count());
    }

    public function test_user_cannot_update_transaction_from_another_family(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $userA = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $familyA->users()->attach($userA->id, ['role' => 'owner']);
        $tokenA = $userA->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Bills',
            'slug' => 'bills',
            'icon' => 'receipt',
            'color' => '#3B82F6',
        ]);

        // Transaction belongs to Family B
        $transactionB = Transaction::create([
            'family_id' => $familyB->id,
            'user_id' => $userA->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1000.00,
            'date' => '2026-11-10',
        ]);

        // User A in Family A tries to update transaction of Family B
        $response = $this->withToken($tokenA)
            ->withHeader('X-Family-Id', $familyA->id)
            ->postJson('/api/transaction/save', [
                'id' => $transactionB->id,
                'type' => 'expense',
                'amount' => 2000.00,
                'category_id' => $category->id,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Transaction not found.');
    }

    public function test_transaction_save_validation_fails_with_invalid_data(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // 1. Missing required fields (type, amount, and category_id)
        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', []);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['type', 'amount', 'category_id']]);

        // 2. Invalid type (not expense or income)
        $responseInvalidType = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'transfer',
                'amount' => 100,
            ]);

        $responseInvalidType->assertStatus(422)
            ->assertJsonStructure(['errors' => ['type']]);

        // 3. Amount <= 0
        $responseZeroAmount = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 0,
            ]);

        $responseZeroAmount->assertStatus(422)
            ->assertJsonStructure(['errors' => ['amount']]);

        // 4. Non-existent category_id
        $responseInvalidCategory = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 100,
                'category_id' => 9999,
            ]);

        $responseInvalidCategory->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category_id']]);
    }

    public function test_default_date_is_today_when_omitted(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'icon' => 'tag',
            'color' => '#64748B',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 100,
                'category_id' => $category->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'family_id' => $family->id,
            'category_id' => $category->id,
            'date' => now()->toDateString(),
            'visibility' => 'shared',
        ]);
    }

    public function test_user_can_add_personal_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Personal Care',
            'slug' => 'personal-care',
            'icon' => 'sparkles',
            'color' => '#EC4899',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 250.00,
                'category_id' => $category->id,
                'visibility' => 'personal',
                'note' => 'Haircut',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.visibility', 'personal');

        $this->assertDatabaseHas('transactions', [
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'visibility' => 'personal',
            'note' => 'Haircut',
        ]);
    }

    public function test_user_can_update_transaction_visibility(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Shopping',
            'slug' => 'shopping',
            'icon' => 'bag',
            'color' => '#8B5CF6',
        ]);

        $transaction = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 500.00,
            'date' => '2026-11-13',
            'visibility' => 'shared',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'id' => $transaction->id,
                'type' => 'expense',
                'amount' => 500.00,
                'category_id' => $category->id,
                'visibility' => 'personal',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.visibility', 'personal');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'visibility' => 'personal',
        ]);
    }

    public function test_another_user_in_same_family_cannot_update_personal_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($alice->id, ['role' => 'owner']);

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($bob->id, ['role' => 'member']);
        $bobToken = $bob->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Shopping',
            'slug' => 'shopping',
            'icon' => 'bag',
            'color' => '#8B5CF6',
        ]);

        // Alice's personal transaction
        $personalTransaction = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 300.00,
            'date' => '2026-11-13',
            'visibility' => 'personal',
        ]);

        // Bob in same family tries to edit Alice's personal transaction
        $response = $this->withToken($bobToken)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'id' => $personalTransaction->id,
                'type' => 'expense',
                'amount' => 999.00,
                'category_id' => $category->id,
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Transaction not found.');

        $this->assertDatabaseHas('transactions', [
            'id' => $personalTransaction->id,
            'amount' => 300.00,
        ]);
    }

    public function test_transaction_validation_fails_with_invalid_visibility(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Shopping',
            'slug' => 'shopping',
            'icon' => 'bag',
            'color' => '#8B5CF6',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 100,
                'category_id' => $category->id,
                'visibility' => 'secret',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['visibility']]);
    }

    public function test_transaction_visibility_scopes(): void
    {
        $family = Family::create(['name' => 'Test Family']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($alice->id, ['role' => 'owner']);

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($bob->id, ['role' => 'member']);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'icon' => 'tag',
            'color' => '#64748B',
        ]);

        $sharedTx = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => '2026-11-13',
            'visibility' => 'shared',
        ]);

        $alicePersonalTx = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 50.00,
            'date' => '2026-11-13',
            'visibility' => 'personal',
        ]);

        $bobPersonalTx = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 75.00,
            'date' => '2026-11-13',
            'visibility' => 'personal',
        ]);

        $this->assertEquals(1, Transaction::shared()->count());
        $this->assertEquals(2, Transaction::personal()->count());

        // Alice sees shared + her personal (2 total)
        $this->assertEquals(2, Transaction::visibleTo($alice)->count());
        $this->assertTrue(Transaction::visibleTo($alice)->pluck('id')->contains($sharedTx->id));
        $this->assertTrue(Transaction::visibleTo($alice)->pluck('id')->contains($alicePersonalTx->id));
        $this->assertFalse(Transaction::visibleTo($alice)->pluck('id')->contains($bobPersonalTx->id));

        // Bob sees shared + his personal (2 total)
        $this->assertEquals(2, Transaction::visibleTo($bob)->count());
        $this->assertTrue(Transaction::visibleTo($bob)->pluck('id')->contains($sharedTx->id));
        $this->assertTrue(Transaction::visibleTo($bob)->pluck('id')->contains($bobPersonalTx->id));
        $this->assertFalse(Transaction::visibleTo($bob)->pluck('id')->contains($alicePersonalTx->id));
    }

    public function test_unauthenticated_user_cannot_access_index_show_or_delete(): void
    {
        $this->getJson('/api/transactions')->assertStatus(401);
        $this->getJson('/api/transaction/1')->assertStatus(401);
        $this->deleteJson('/api/transaction/1')->assertStatus(401);
    }

    public function test_user_can_list_transactions_scoped_to_family_and_visibility(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $familyA->users()->attach($alice->id, ['role' => 'owner']);
        $aliceToken = $alice->createToken('auth_token')->plainTextToken;

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $familyA->users()->attach($bob->id, ['role' => 'member']);

        $category = Category::create([
            'name' => 'Food',
            'slug' => 'food',
            'icon' => 'utensils',
            'color' => '#F97316',
        ]);

        // 1. Shared transaction in Family A
        $sharedTx = Transaction::create([
            'family_id' => $familyA->id,
            'user_id' => $bob->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => '2026-11-10',
            'visibility' => 'shared',
            'note' => 'Family dinner',
        ]);

        // 2. Alice's personal transaction in Family A
        $alicePersonalTx = Transaction::create([
            'family_id' => $familyA->id,
            'user_id' => $alice->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 50.00,
            'date' => '2026-11-11',
            'visibility' => 'personal',
            'note' => 'Alice lunch',
        ]);

        // 3. Bob's personal transaction in Family A (should NOT be visible to Alice)
        Transaction::create([
            'family_id' => $familyA->id,
            'user_id' => $bob->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 75.00,
            'date' => '2026-11-12',
            'visibility' => 'personal',
            'note' => 'Bob coffee',
        ]);

        // 4. Family B transaction (should NOT be visible to Alice)
        Transaction::create([
            'family_id' => $familyB->id,
            'user_id' => $alice->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 999.00,
            'date' => '2026-11-13',
            'visibility' => 'shared',
            'note' => 'Family B item',
        ]);

        $response = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $familyA->id)
            ->getJson('/api/transactions');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Transactions retrieved successfully.')
            ->assertJsonPath('data.total', 2);

        $data = $response->json('data.data');
        $this->assertCount(2, $data);

        $returnedIds = collect($data)->pluck('id');
        $this->assertTrue($returnedIds->contains($sharedTx->id));
        $this->assertTrue($returnedIds->contains($alicePersonalTx->id));
    }

    public function test_user_can_filter_transactions_by_type_category_visibility_and_search(): void
    {
        $family = Family::create(['name' => 'Family A']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $foodCategory = Category::create([
            'name' => 'Food',
            'slug' => 'food',
            'icon' => 'utensils',
            'color' => '#F97316',
        ]);

        $salaryCategory = Category::create([
            'name' => 'Salary',
            'slug' => 'salary',
            'icon' => 'cash',
            'color' => '#10B981',
        ]);

        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $foodCategory->id,
            'type' => 'expense',
            'amount' => 200.00,
            'date' => '2026-11-01',
            'visibility' => 'shared',
            'note' => 'Supermarket shopping',
        ]);

        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $salaryCategory->id,
            'type' => 'income',
            'amount' => 50000.00,
            'date' => '2026-11-05',
            'visibility' => 'personal',
            'note' => 'Monthly tech salary',
        ]);

        // Filter by type=income
        $incomeResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transactions?type=income');
        $incomeResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.type', 'income');

        // Filter by type=expense (or expence / expenses)
        $expenseResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transactions?type=expense');
        $expenseResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.type', 'expense');

        // Filter by category_id
        $categoryResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transactions?category_id='.$foodCategory->id);
        $categoryResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.category_id', $foodCategory->id);

        // Filter by visibility=personal
        $visibilityResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transactions?visibility=personal');
        $visibilityResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.visibility', 'personal');

        // Filter by search
        $searchResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transactions?search=Supermarket');
        $searchResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.note', 'Supermarket shopping');

        // Filter by date range
        $dateResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transactions?start_date=2026-11-02&end_date=2026-11-06');
        $dateResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.date', Carbon::parse('2026-11-05', 'UTC')->timestamp);
    }

    public function test_user_can_view_single_transaction(): void
    {
        $family = Family::create(['name' => 'Family A']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Rent',
            'slug' => 'rent',
            'icon' => 'home',
            'color' => '#3B82F6',
        ]);

        $transaction = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 15000.00,
            'date' => '2026-11-01',
            'visibility' => 'shared',
            'note' => 'Apartment monthly rent',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transaction/'.$transaction->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.amount', '15000.00')
            ->assertJsonPath('data.category.slug', 'rent')
            ->assertJsonPath('data.user.email', 'alice@example.com');
    }

    public function test_user_cannot_view_personal_transaction_of_another_member(): void
    {
        $family = Family::create(['name' => 'Family A']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($alice->id, ['role' => 'owner']);
        $aliceToken = $alice->createToken('auth_token')->plainTextToken;

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($bob->id, ['role' => 'member']);

        $category = Category::create([
            'name' => 'Shopping',
            'slug' => 'shopping',
            'icon' => 'bag',
            'color' => '#8B5CF6',
        ]);

        $bobPersonalTx = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 99.00,
            'date' => '2026-11-13',
            'visibility' => 'personal',
        ]);

        // Alice tries to view Bob's personal transaction
        $response = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transaction/'.$bobPersonalTx->id);

        $response->assertStatus(404)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Transaction not found.');
    }

    public function test_user_can_delete_own_personal_transaction(): void
    {
        $family = Family::create(['name' => 'Family A']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Shopping',
            'slug' => 'shopping',
            'icon' => 'bag',
            'color' => '#8B5CF6',
        ]);

        $transaction = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 120.00,
            'date' => '2026-11-13',
            'visibility' => 'personal',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->deleteJson('/api/transaction/'.$transaction->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Expense deleted successfully.');

        $this->assertSoftDeleted('transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_user_cannot_delete_personal_transaction_of_another_member(): void
    {
        $family = Family::create(['name' => 'Family A']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($alice->id, ['role' => 'owner']);
        $aliceToken = $alice->createToken('auth_token')->plainTextToken;

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($bob->id, ['role' => 'member']);

        $category = Category::create([
            'name' => 'Shopping',
            'slug' => 'shopping',
            'icon' => 'bag',
            'color' => '#8B5CF6',
        ]);

        $bobPersonalTx = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 200.00,
            'date' => '2026-11-13',
            'visibility' => 'personal',
        ]);

        $response = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->deleteJson('/api/transaction/'.$bobPersonalTx->id);

        $response->assertStatus(404)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Transaction not found.');

        $this->assertDatabaseHas('transactions', [
            'id' => $bobPersonalTx->id,
        ]);
    }

    public function test_any_family_member_can_delete_shared_transaction(): void
    {
        $family = Family::create(['name' => 'Family A']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($alice->id, ['role' => 'owner']);
        $aliceToken = $alice->createToken('auth_token')->plainTextToken;

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($bob->id, ['role' => 'member']);

        $category = Category::create([
            'name' => 'Groceries',
            'slug' => 'groceries',
            'icon' => 'cart',
            'color' => '#10B981',
        ]);

        // Bob created a shared transaction
        $sharedTx = Transaction::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 450.00,
            'date' => '2026-11-13',
            'visibility' => 'shared',
        ]);

        // Alice deletes Bob's shared transaction
        $response = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->deleteJson('/api/transaction/'.$sharedTx->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Expense deleted successfully.');

        $this->assertSoftDeleted('transactions', [
            'id' => $sharedTx->id,
        ]);
    }

    public function test_cannot_delete_or_view_transaction_from_another_family(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $familyA->users()->attach($alice->id, ['role' => 'owner']);
        $aliceToken = $alice->createToken('auth_token')->plainTextToken;

        $category = Category::create([
            'name' => 'Groceries',
            'slug' => 'groceries',
            'icon' => 'cart',
            'color' => '#10B981',
        ]);

        $familyBTx = Transaction::create([
            'family_id' => $familyB->id,
            'user_id' => $alice->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => '2026-11-13',
            'visibility' => 'shared',
        ]);

        $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $familyA->id)
            ->getJson('/api/transaction/'.$familyBTx->id)
            ->assertStatus(404);

        $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $familyA->id)
            ->deleteJson('/api/transaction/'.$familyBTx->id)
            ->assertStatus(404);
    }

    public function test_transaction_category_type_must_match_transaction_type(): void
    {
        $family = Family::create(['name' => 'Type Match Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice.typematch@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $expenseCategory = Category::create([
            'name' => 'Fuel',
            'slug' => 'fuel-type-test',
            'type' => 'expense',
            'icon' => 'fuel',
            'color' => '#EC4899',
        ]);
        $incomeCategory = Category::create([
            'name' => 'Salary',
            'slug' => 'salary-type-test',
            'type' => 'income',
            'icon' => 'briefcase',
            'color' => '#10B981',
        ]);

        // 1. Income with income category -> 201
        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'income',
                'amount' => 5000,
                'category_id' => $incomeCategory->id,
                'date' => '2026-11-28',
            ])
            ->assertStatus(201);

        // 2. Income with expense category -> 422
        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'income',
                'amount' => 500,
                'category_id' => $expenseCategory->id,
                'date' => '2026-11-28',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 3. Expense with income category -> 422
        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/transaction/save', [
                'type' => 'expense',
                'amount' => 100,
                'category_id' => $incomeCategory->id,
                'date' => '2026-11-28',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }
}
