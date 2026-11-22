<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtSettlement;
use App\Models\Family;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyFinanceEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected Family $family;

    protected User $user;

    protected string $token;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::create([
            'name' => 'Smith Family',
            'currency' => 'USD',
        ]);

        $this->user = User::factory()->create([
            'family_id' => $this->family->id,
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
        ]);

        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'color' => '#10B981',
        ]);

        Category::create([
            'name' => 'Others',
            'slug' => 'others',
            'color' => '#6B7280',
        ]);
    }

    public function test_debt_partial_settlement_creates_settlement_records_and_updates_remaining_balance(): void
    {
        // 1. Create debt of $100.00
        $createResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Bob',
                'type' => 'lent',
                'amount' => 100.00,
                'date' => '2026-11-20',
                'category_id' => $this->category->id,
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.settled_amount', 0)
            ->assertJsonPath('data.remaining_amount', 100)
            ->assertJsonPath('data.status', 'pending');

        $debtId = $createResponse->json('data.id');

        // 2. Partial settlement of $40.00
        $settlePartialResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debtId}/settle", [
                'amount' => 40.00,
                'note' => 'First partial repayment',
            ]);

        $settlePartialResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'partially_settled')
            ->assertJsonPath('data.settled_amount', 40)
            ->assertJsonPath('data.remaining_amount', 60);

        $this->assertDatabaseHas('debt_settlements', [
            'debt_id' => $debtId,
            'amount' => '40.00',
            'note' => 'First partial repayment',
        ]);

        // Income transaction generated for lent repayment
        $this->assertDatabaseHas('transactions', [
            'family_id' => $this->family->id,
            'type' => 'income',
            'amount' => '40.00',
        ]);

        // 3. Validation: attempting to settle more than remaining ($65 > $60) fails
        $overSettleResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debtId}/settle", [
                'amount' => 65.00,
            ]);

        $overSettleResponse->assertStatus(422);

        // 4. Settle remaining balance without specifying amount (defaults to remaining $60)
        $settleFullResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debtId}/settle");

        $settleFullResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.settled_amount', 100)
            ->assertJsonPath('data.remaining_amount', 0);

        $this->assertCount(2, DebtSettlement::where('debt_id', $debtId)->get());
    }

    public function test_family_custom_categories_crud_and_tenant_isolation(): void
    {
        $otherFamily = Family::create([
            'name' => 'Johnson Family',
            'currency' => 'USD',
        ]);

        // 1. Create family custom category
        $createResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/category/save', [
                'name' => 'Vacation & Trips',
                'icon' => 'plane',
                'color' => '#3B82F6',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.name', 'Vacation & Trips')
            ->assertJsonPath('data.is_system', false);

        $customCategoryId = $createResponse->json('data.id');

        // 2. Listing categories for this family includes system + custom category
        $listResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/category');

        $listResponse->assertStatus(200)
            ->assertJsonFragment(['name' => 'Vacation & Trips'])
            ->assertJsonFragment(['name' => 'General']);

        // 3. Other family does NOT see this custom category
        $otherUser = User::factory()->create([
            'family_id' => $otherFamily->id,
            'email' => 'johnson@example.com',
        ]);
        $otherToken = $otherUser->createToken('token-b')->plainTextToken;

        auth()->forgetGuards();

        $otherListResponse = $this->withToken($otherToken)
            ->withHeader('X-Family-Id', $otherFamily->id)
            ->getJson('/api/category');

        $otherListResponse->assertStatus(200)
            ->assertJsonMissing(['name' => 'Vacation & Trips'])
            ->assertJsonFragment(['name' => 'General']);

        auth()->forgetGuards();

        // 4. System categories cannot be deleted or modified by families
        $systemCategory = Category::where('slug', 'general')->first();

        $deleteSystemResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->deleteJson("/api/category/{$systemCategory->id}");

        $deleteSystemResponse->assertStatus(403);

        // 5. Update custom category
        $updateResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/category/save', [
                'id' => $customCategoryId,
                'name' => 'Vacation Trips Updated',
                'color' => '#2563EB',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.name', 'Vacation Trips Updated');

        // 6. Delete custom category (soft delete)
        $deleteCustomResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->deleteJson("/api/category/{$customCategoryId}");

        $deleteCustomResponse->assertStatus(200);

        $this->assertSoftDeleted('categories', ['id' => $customCategoryId]);

        // 7. Restore custom category
        $restoreResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/category/{$customCategoryId}/restore");

        $restoreResponse->assertStatus(200);

        $this->assertNotSoftDeleted('categories', ['id' => $customCategoryId]);
    }

    public function test_recurring_transactions_crud_and_duplicate_prevention_processor(): void
    {
        $today = now()->toDateString();

        // 1. Create recurring monthly subscription
        $createResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'category_id' => $this->category->id,
                'type' => 'expense',
                'amount' => 19.99,
                'frequency' => 'monthly',
                'interval' => 1,
                'start_date' => $today,
                'note' => 'Cloud Storage Plan',
                'visibility' => 'shared',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.amount', '19.99')
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.status', 'active');

        $recurringId = $createResponse->json('data.id');

        // 2. Process recurring transactions command
        $this->artisan('recurring:process')
            ->assertSuccessful();

        // One transaction created for today
        $this->assertDatabaseHas('transactions', [
            'recurring_transaction_id' => $recurringId,
            'amount' => '19.99',
            'date' => $today,
        ]);

        $recurring = RecurringTransaction::find($recurringId);
        $expectedNextDue = Carbon::parse($today)->addMonthNoOverflow()->toDateString();
        $this->assertSame($expectedNextDue, Carbon::parse($recurring->next_due_date)->toDateString());

        // 3. Safe Duplicate Prevention: Running the command again does NOT create duplicate for same cycle
        $this->artisan('recurring:process')
            ->assertSuccessful();

        $this->assertSame(1, Transaction::where('recurring_transaction_id', $recurringId)->count());

        // 4. Update status (Pause and Resume via save)
        $pauseResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'id' => $recurringId,
                'status' => 'paused',
            ]);

        $pauseResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'paused');

        $resumeResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'id' => $recurringId,
                'status' => 'active',
            ]);

        $resumeResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        // 5. Listing recurring transactions via single endpoint GET /api/recurring-transaction
        $listResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/recurring-transaction');

        $listResponse->assertStatus(200)
            ->assertJsonPath('data.data.0.id', $recurringId);
    }

    public function test_soft_delete_and_restore_with_trash_overview(): void
    {
        // 1. Create transaction
        $txResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/transaction/save', [
                'category_id' => $this->category->id,
                'type' => 'expense',
                'amount' => 50.00,
                'date' => '2026-11-20',
                'note' => 'Test groceries',
            ]);

        $txId = $txResponse->json('data.id');

        // 2. Delete transaction (soft delete)
        $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->deleteJson("/api/transaction/{$txId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('transactions', ['id' => $txId]);

        // Excluded from standard index
        $indexResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/transactions');

        $indexResponse->assertStatus(200)
            ->assertJsonMissing(['id' => $txId]);

        // 3. Included in Trash overview
        $trashResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/trash');

        $trashResponse->assertStatus(200)
            ->assertJsonFragment(['id' => $txId, 'note' => 'Test groceries']);

        // 4. Restore transaction
        $restoreResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/transaction/{$txId}/restore");

        $restoreResponse->assertStatus(200);

        $this->assertNotSoftDeleted('transactions', ['id' => $txId]);

        // 5. Debt soft-deletion also soft-deletes linked transactions and settlements
        $debtResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Charlie',
                'type' => 'lent',
                'amount' => 80.00,
                'date' => '2026-11-20',
                'category_id' => $this->category->id,
            ]);

        $debtId = $debtResponse->json('data.id');
        $initialTxId = $debtResponse->json('data.transaction_id');

        $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->deleteJson("/api/debt/{$debtId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('debts', ['id' => $debtId]);
        $this->assertSoftDeleted('transactions', ['id' => $initialTxId]);

        // Restoring debt also restores linked initial transaction
        $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debtId}/restore")
            ->assertStatus(200);

        $this->assertNotSoftDeleted('debts', ['id' => $debtId]);
        $this->assertNotSoftDeleted('transactions', ['id' => $initialTxId]);
    }

    public function test_trash_list_strictly_respects_visibility_and_scope(): void
    {
        // Member 2 in the same family
        $member2 = User::factory()->create([
            'family_id' => $this->family->id,
            'email' => 'member2@example.com',
        ]);
        $member2Token = $member2->createToken('member2')->plainTextToken;

        // User 1 creates and deletes a personal transaction
        $personalTx = Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => '25.00',
            'date' => now()->toDateString(),
            'note' => 'User 1 personal secret expense',
            'visibility' => 'personal',
        ]);
        $personalTx->delete();

        // User 1 creates and deletes a shared transaction
        $sharedTx = Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => '50.00',
            'date' => now()->toDateString(),
            'note' => 'User 1 shared groceries',
            'visibility' => 'shared',
        ]);
        $sharedTx->delete();

        // 1. User 2 checks trash: User 2 sees the shared transaction, but NEVER sees User 1's personal transaction
        $member2Trash = $this->withToken($member2Token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/trash');

        $member2Trash->assertStatus(200)
            ->assertJsonFragment(['note' => 'User 1 shared groceries'])
            ->assertJsonMissing(['note' => 'User 1 personal secret expense']);

        auth()->forgetGuards();

        // 2. User 1 checks trash: sees both shared and their own personal transaction
        $user1Trash = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/trash');

        $user1Trash->assertStatus(200)
            ->assertJsonFragment(['note' => 'User 1 shared groceries'])
            ->assertJsonFragment(['note' => 'User 1 personal secret expense']);

        // 3. User 1 filters trash by visibility=personal: only personal items returned, budgets/categories empty
        $user1PersonalTrash = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/trash?visibility=personal');

        $user1PersonalTrash->assertStatus(200)
            ->assertJsonFragment(['note' => 'User 1 personal secret expense'])
            ->assertJsonMissing(['note' => 'User 1 shared groceries'])
            ->assertJsonPath('data.budgets', [])
            ->assertJsonPath('data.categories', []);

        // 4. User 1 filters trash by visibility=shared: only shared items returned
        $user1SharedTrash = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/trash?visibility=shared');

        $user1SharedTrash->assertStatus(200)
            ->assertJsonFragment(['note' => 'User 1 shared groceries'])
            ->assertJsonMissing(['note' => 'User 1 personal secret expense']);

        // 5. User 2 cannot restore User 1's personal transaction
        auth()->forgetGuards();
        $restoreAttempt = $this->withToken($member2Token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/transaction/{$personalTx->id}/restore");

        $restoreAttempt->assertStatus(404);
        $this->assertSoftDeleted('transactions', ['id' => $personalTx->id]);
    }

    public function test_concurrent_or_double_settlement_prevents_overpayment(): void
    {
        $debt = Debt::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'person_name' => 'David',
            'type' => 'lent',
            'amount' => 100.00,
            'settled_amount' => 0.00,
            'date' => now()->toDateString(),
            'status' => 'pending',
            'visibility' => 'shared',
        ]);

        // Request 1: settle $70 of $100 -> succeeds, remaining is $30
        $res1 = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debt->id}/settle", [
                'amount' => 70.00,
            ]);

        $res1->assertStatus(200)
            ->assertJsonPath('data.status', 'partially_settled')
            ->assertJsonPath('data.remaining_amount', 30);

        // Request 2 (simulating race/second attempt with stale $70 amount): fails because remaining is only $30
        $res2 = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debt->id}/settle", [
                'amount' => 70.00,
            ]);

        $res2->assertStatus(422)
            ->assertJsonPath('status', false);

        // Request 3: settle remaining $30 -> succeeds, debt becomes fully settled
        $res3 = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$debt->id}/settle", [
                'amount' => 30.00,
            ]);

        $res3->assertStatus(200)
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_recurring_category_ownership_validation_and_isolation(): void
    {
        $otherFamily = Family::create([
            'name' => 'Other Family',
            'currency' => 'USD',
        ]);

        $otherCategory = Category::create([
            'family_id' => $otherFamily->id,
            'name' => 'Secret Other Category',
            'slug' => 'secret-other-cat',
            'color' => '#EF4444',
        ]);

        // 1. Family A attempts to create recurring transaction using Other Family's category -> rejected 422
        $recurringRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'category_id' => $otherCategory->id,
                'type' => 'expense',
                'amount' => 25.00,
                'frequency' => 'monthly',
                'start_date' => now()->toDateString(),
            ]);

        $recurringRes->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 2. Family A creates recurring transaction with system category -> succeeds
        $validRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'category_id' => $this->category->id,
                'type' => 'expense',
                'amount' => 25.00,
                'frequency' => 'monthly',
                'start_date' => now()->toDateString(),
            ]);

        $validRes->assertStatus(201);
        $recurringId = $validRes->json('data.id');

        // 3. Family A attempts to update recurring transaction to Other Family's category -> rejected 422
        $updateRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'id' => $recurringId,
                'category_id' => $otherCategory->id,
            ]);

        $updateRes->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_category_restore_rejects_conflict_with_active_category(): void
    {
        // 1. Create custom category
        $create1 = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/category/save', [
                'name' => 'Pet Care',
            ]);
        $cat1Id = $create1->json('data.id');

        // 2. Delete it
        $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->deleteJson("/api/category/{$cat1Id}")
            ->assertStatus(200);

        // 3. Create a new active category with the same name
        $create2 = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/category/save', [
                'name' => 'Pet Care',
            ]);
        $create2->assertStatus(201);

        // 4. Attempt to restore the first one -> rejected with 422 conflict
        $restoreConflict = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/category/{$cat1Id}/restore");

        $restoreConflict->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertSoftDeleted('categories', ['id' => $cat1Id]);
    }

    public function test_recurring_transaction_rescheduling_and_database_duplicate_constraint(): void
    {
        // 1. Create recurring schedule starting next month
        $futureDate = now()->addMonth()->toDateString();
        $createRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'category_id' => $this->category->id,
                'type' => 'expense',
                'amount' => 50.00,
                'frequency' => 'monthly',
                'start_date' => $futureDate,
            ]);

        $recurringId = $createRes->json('data.id');
        $this->assertSame(Carbon::parse($futureDate)->timestamp, $createRes->json('data.next_due_date'));

        // 2. Rescheduling: update start_date to a different future date
        $rescheduledDate = now()->addMonths(2)->toDateString();
        $updateRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/recurring-transaction/save', [
                'id' => $recurringId,
                'start_date' => $rescheduledDate,
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.next_due_date', Carbon::parse($rescheduledDate)->timestamp);

        // 3. Database unique constraint prevents duplicate (recurring_transaction_id, date)
        $txDate = now()->toDateString();
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'recurring_transaction_id' => $recurringId,
            'type' => 'expense',
            'amount' => 50.00,
            'date' => $txDate,
        ]);

        $this->expectException(QueryException::class);

        // Attempting direct DB insert with same recurring_transaction_id and date throws QueryException
        Transaction::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'recurring_transaction_id' => $recurringId,
            'type' => 'expense',
            'amount' => 50.00,
            'date' => $txDate,
        ]);
    }

    public function test_category_ownership_is_enforced_across_all_apis(): void
    {
        $otherFamily = Family::create([
            'name' => 'Other Family',
            'currency' => 'USD',
        ]);

        $otherCategory = Category::create([
            'family_id' => $otherFamily->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
            'color' => '#EF4444',
        ]);

        // 1. Transaction save rejects another family's category
        $txReject = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/transaction/save', [
                'category_id' => $otherCategory->id,
                'type' => 'expense',
                'amount' => 30.00,
                'date' => now()->toDateString(),
            ]);

        $txReject->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 2. Transaction save succeeds with system category, but rejects updating to other family's category
        $txValid = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/transaction/save', [
                'category_id' => $this->category->id,
                'type' => 'expense',
                'amount' => 30.00,
                'date' => now()->toDateString(),
            ]);
        $txValid->assertStatus(201);
        $txId = $txValid->json('data.id');

        $txUpdateReject = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/transaction/save', [
                'id' => $txId,
                'category_id' => $otherCategory->id,
                'type' => 'expense',
                'amount' => 30.00,
            ]);
        $txUpdateReject->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 3. Budget save rejects another family's category
        $budgetReject = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/budget/save', [
                'category_id' => $otherCategory->id,
                'amount' => 150.00,
            ]);

        $budgetReject->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 4. Budget save succeeds with system category
        $budgetValid = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/budget/save', [
                'category_id' => $this->category->id,
                'amount' => 150.00,
            ]);
        $budgetValid->assertStatus(201);

        // 5. Debt save rejects another family's category
        $debtReject = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Eve',
                'type' => 'lent',
                'amount' => 50.00,
                'category_id' => $otherCategory->id,
            ]);

        $debtReject->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 6. Debt save succeeds with system category
        $debtValid = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/debt/save', [
                'person_name' => 'Eve',
                'type' => 'lent',
                'amount' => 50.00,
                'category_id' => $this->category->id,
            ]);
        $debtValid->assertStatus(201);
    }

    public function test_existing_settled_debts_preserve_zero_remaining_balance_without_backfill(): void
    {
        // Simulate a legacy debt row in the database created prior to the partial settlement feature:
        // status is 'settled', amount is 200.00, and settled_amount is null (no backfill or default).
        $legacySettledDebt = Debt::create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'person_name' => 'Frank',
            'type' => 'lent',
            'amount' => 200.00,
            'settled_amount' => null,
            'date' => now()->toDateString(),
            'status' => 'settled',
            'visibility' => 'shared',
        ]);

        // Model attribute / accessor level assertions
        $this->assertSame(0.0, (float) $legacySettledDebt->remaining_amount);
        $this->assertSame(200.0, (float) $legacySettledDebt->settled_amount);

        // API endpoint GET /api/debt/{id} returns 0 remaining and full settled amount
        $showResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson("/api/debt/{$legacySettledDebt->id}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'settled')
            ->assertJsonPath('data.remaining_amount', 0)
            ->assertJsonPath('data.settled_amount', 200)
            ->assertJsonPath('data.display.remaining.amount_cents', 0)
            ->assertJsonPath('data.display.settled.amount_cents', 20000);

        // Attempting to settle an already-settled legacy debt returns early with settled message
        $settleResponse = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/debt/{$legacySettledDebt->id}/settle");

        $settleResponse->assertStatus(200)
            ->assertJsonPath('message', 'Debt record is already settled.')
            ->assertJsonPath('data.remaining_amount', 0);
    }
}
