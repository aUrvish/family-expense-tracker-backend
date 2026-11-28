<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(
            ['slug' => 'others'],
            [
                'name' => 'Others',
                'slug' => 'others',
                'icon' => 'more-horizontal',
                'color' => '#94A3B8',
            ]
        );
    }

    public function test_unauthenticated_user_cannot_access_debt_endpoints(): void
    {
        $this->getJson('/api/debts')->assertStatus(401);
        $this->postJson('/api/debt/save', [])->assertStatus(401);
        $this->getJson('/api/debt/1')->assertStatus(401);
        $this->postJson('/api/debt/1/settle')->assertStatus(401);
        $this->deleteJson('/api/debt/1')->assertStatus(401);
    }

    public function test_debt_endpoints_require_family_header(): void
    {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/debts')
            ->assertStatus(400);

        $this->withToken($token)
            ->postJson('/api/debt/save', [
                'person_name' => 'John Doe',
                'type' => 'lent',
                'amount' => 500,
            ])
            ->assertStatus(400);
    }

    public function test_user_can_create_lent_debt_and_automatically_creates_expense_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $payload = [
            'person_name' => 'John Doe',
            'type' => 'lent',
            'amount' => 5000.00,
            'date' => '2026-11-14',
            'due_date' => '2026-12-14',
            'note' => 'Emergency loan for medical bill',
            'visibility' => 'shared',
        ];

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Lent record added successfully.')
            ->assertJsonPath('data.person_name', 'John Doe')
            ->assertJsonPath('data.type', 'lent')
            ->assertJsonPath('data.amount', '5000.00')
            ->assertJsonPath('data.status', 'pending');

        $debtId = $response->json('data.id');
        $transactionId = $response->json('data.transaction_id');

        $this->assertNotNull($transactionId);

        // Verify debt in database
        $this->assertDatabaseHas('debts', [
            'id' => $debtId,
            'family_id' => $family->id,
            'user_id' => $user->id,
            'person_name' => 'John Doe',
            'type' => 'lent',
            'amount' => 5000.00,
            'status' => 'pending',
            'transaction_id' => $transactionId,
        ]);

        // Verify automatically created expense transaction
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 5000.00,
            'note' => 'Lent to John Doe: Emergency loan for medical bill',
            'visibility' => 'shared',
        ]);
    }

    public function test_user_can_create_borrowed_debt_and_automatically_creates_income_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $payload = [
            'person_name' => 'Jane Smith',
            'type' => 'borrowed',
            'amount' => 12000.00,
            'date' => '2026-11-10',
            'due_date' => '2026-12-01',
            'note' => 'Borrowed for car repair',
            'visibility' => 'personal',
        ];

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Borrowed record added successfully.')
            ->assertJsonPath('data.person_name', 'Jane Smith')
            ->assertJsonPath('data.type', 'borrowed')
            ->assertJsonPath('data.amount', '12000.00')
            ->assertJsonPath('data.visibility', 'personal')
            ->assertJsonPath('data.status', 'pending');

        $debtId = $response->json('data.id');
        $transactionId = $response->json('data.transaction_id');

        $this->assertNotNull($transactionId);

        // Verify debt in database
        $this->assertDatabaseHas('debts', [
            'id' => $debtId,
            'type' => 'borrowed',
            'amount' => 12000.00,
            'visibility' => 'personal',
        ]);

        // Verify automatically created income transaction
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'type' => 'income',
            'amount' => 12000.00,
            'note' => 'Borrowed from Jane Smith: Borrowed for car repair',
            'visibility' => 'personal',
        ]);
    }

    public function test_user_can_settle_lent_debt_and_creates_income_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // Create lent debt
        $debtResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Mark',
                'type' => 'lent',
                'amount' => 3000.00,
                'note' => 'Short term cash',
            ]);

        $debtId = $debtResponse->json('data.id');

        // Settle the debt
        $settleResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson("/api/debt/{$debtId}/settle");

        $settleResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Debt settled successfully.')
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.settlement_transaction_id', fn ($val) => ! empty($val));

        $settlementTxId = $settleResponse->json('data.settlement_transaction_id');

        // Verify settlement transaction created with 'income' type
        $this->assertDatabaseHas('transactions', [
            'id' => $settlementTxId,
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 3000.00,
            'note' => 'Settled: Received from Mark: Short term cash',
        ]);

        // Calling settle again is idempotent
        $againResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson("/api/debt/{$debtId}/settle");

        $againResponse->assertStatus(200)
            ->assertJsonPath('message', 'Debt record is already settled.');
    }

    public function test_user_can_settle_borrowed_debt_and_creates_expense_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // Create borrowed debt
        $debtResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Uncle Dave',
                'type' => 'borrowed',
                'amount' => 8000.00,
                'note' => 'House renovation advance',
            ]);

        $debtId = $debtResponse->json('data.id');

        // Settle the debt
        $settleResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson("/api/debt/{$debtId}/settle");

        $settleResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'settled');

        $settlementTxId = $settleResponse->json('data.settlement_transaction_id');

        // Verify settlement transaction created with 'expense' type
        $this->assertDatabaseHas('transactions', [
            'id' => $settlementTxId,
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 8000.00,
            'note' => 'Settled: Paid to Uncle Dave: House renovation advance',
        ]);
    }

    public function test_user_can_update_existing_debt_and_syncs_transaction(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $debtResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Tom',
                'type' => 'lent',
                'amount' => 1000.00,
                'note' => 'Initial note',
            ]);

        $debtId = $debtResponse->json('data.id');
        $txId = $debtResponse->json('data.transaction_id');

        // Update amount, note, and person_name
        $updateResponse = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'id' => $debtId,
                'person_name' => 'Thomas',
                'type' => 'lent',
                'amount' => 1500.00,
                'note' => 'Updated note',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.person_name', 'Thomas')
            ->assertJsonPath('data.amount', '1500.00');

        $this->assertDatabaseHas('debts', [
            'id' => $debtId,
            'person_name' => 'Thomas',
            'amount' => 1500.00,
            'note' => 'Updated note',
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $txId,
            'amount' => 1500.00,
            'note' => 'Lent to Thomas: Updated note',
        ]);
    }

    public function test_user_can_list_debts_with_visibility_and_filters(): void
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

        // 1. Shared lent debt by Bob
        $bobSharedDebt = Debt::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'person_name' => 'Cousin Charlie',
            'type' => 'lent',
            'amount' => 2000.00,
            'date' => '2026-11-01',
            'visibility' => 'shared',
            'status' => 'pending',
        ]);

        // 2. Personal borrowed debt by Alice
        $alicePersonalDebt = Debt::create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'person_name' => 'Friend Frank',
            'type' => 'borrowed',
            'amount' => 500.00,
            'date' => '2026-11-05',
            'visibility' => 'personal',
            'status' => 'settled',
        ]);

        // 3. Personal debt by Bob (Alice must NOT see this)
        Debt::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'person_name' => 'Secret Lender',
            'type' => 'borrowed',
            'amount' => 9999.00,
            'date' => '2026-11-10',
            'visibility' => 'personal',
            'status' => 'pending',
        ]);

        // Alice fetches all debts visible to her
        $response = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/debts');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.total', 2);

        $returnedIds = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($returnedIds->contains($bobSharedDebt->id));
        $this->assertTrue($returnedIds->contains($alicePersonalDebt->id));

        // Filter by type=lent
        $lentResponse = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/debts?type=lent');
        $lentResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.type', 'lent');

        // Filter by status=settled
        $settledResponse = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/debts?status=settled');
        $settledResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.status', 'settled');

        // Search by person_name
        $searchResponse = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/debts?search=Charlie');
        $searchResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.person_name', 'Cousin Charlie');
    }

    public function test_user_can_view_single_debt(): void
    {
        $family = Family::create(['name' => 'Family A']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        $debt = Debt::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'person_name' => 'Sarah',
            'type' => 'lent',
            'amount' => 4000.00,
            'date' => '2026-11-12',
            'visibility' => 'shared',
            'status' => 'pending',
            'note' => 'College project funding',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/debt/'.$debt->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $debt->id)
            ->assertJsonPath('data.person_name', 'Sarah')
            ->assertJsonPath('data.amount', '4000.00');
    }

    public function test_user_cannot_view_or_delete_personal_debt_of_another_member(): void
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

        $bobPersonalDebt = Debt::create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'person_name' => 'Bob Friend',
            'type' => 'lent',
            'amount' => 100.00,
            'date' => '2026-11-14',
            'visibility' => 'personal',
            'status' => 'pending',
        ]);

        // Alice cannot view Bob's personal debt
        $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/debt/'.$bobPersonalDebt->id)
            ->assertStatus(404);

        // Alice cannot settle Bob's personal debt
        $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/'.$bobPersonalDebt->id.'/settle')
            ->assertStatus(404);

        // Alice cannot delete Bob's personal debt
        $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->deleteJson('/api/debt/'.$bobPersonalDebt->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('debts', [
            'id' => $bobPersonalDebt->id,
        ]);
    }

    public function test_any_family_member_can_delete_shared_debt_and_cleans_up_linked_transactions(): void
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
        $bobToken = $bob->createToken('auth_token')->plainTextToken;

        // Bob creates shared debt
        $debtResponse = $this->withToken($bobToken)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Neighbor Ned',
                'type' => 'lent',
                'amount' => 500.00,
                'visibility' => 'shared',
            ]);

        $debtId = $debtResponse->json('data.id');
        $txId = $debtResponse->json('data.transaction_id');

        // Alice deletes Bob's shared debt
        $response = $this->withToken($aliceToken)
            ->withHeader('X-Family-Id', $family->id)
            ->deleteJson("/api/debt/{$debtId}");

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Lent record deleted successfully.');

        $this->assertSoftDeleted('debts', ['id' => $debtId]);
        $this->assertSoftDeleted('transactions', ['id' => $txId]);
    }

    public function test_debt_validation_fails_with_invalid_data(): void
    {
        $family = Family::create(['name' => 'Test Family']);
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);
        $token = $user->createToken('auth_token')->plainTextToken;

        // Missing required fields
        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['person_name', 'type', 'amount']]);

        // Invalid type
        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Sam',
                'type' => 'invalid_type',
                'amount' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['type']]);

        // Amount <= 0
        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Sam',
                'type' => 'lent',
                'amount' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['amount']]);
    }
}
