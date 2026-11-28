<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::firstOrCreate(
            ['slug' => 'groceries'],
            ['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'cart', 'color' => '#10B981']
        );

        Category::firstOrCreate(
            ['slug' => 'shopping'],
            ['name' => 'Shopping', 'slug' => 'shopping', 'icon' => 'bag', 'color' => '#8B5CF6']
        );

        Category::firstOrCreate(
            ['slug' => 'food'],
            ['name' => 'Food & Dining', 'slug' => 'food', 'icon' => 'utensils', 'color' => '#F97316']
        );
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function test_dashboard_requires_family_header(): void
    {
        $user = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertStatus(400);
    }

    public function test_dashboard_returns_complete_calculated_data_matching_screens(): void
    {
        $family = Family::create([
            'name' => 'Sharma Parivar',
        ]);

        $anita = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($anita->id, ['role' => 'owner']);
        $anitaToken = $anita->createToken('auth_token')->plainTextToken;

        $rakesh = User::create([
            'name' => 'Rakesh',
            'email' => 'rakesh@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($rakesh->id, ['role' => 'member']);

        $dadaji = User::create([
            'name' => 'Dadaji',
            'email' => 'dadaji@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($dadaji->id, ['role' => 'member']);

        $meera = User::create([
            'name' => 'Meera',
            'email' => 'meera@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($meera->id, ['role' => 'member']);

        $groceriesCategory = Category::where('slug', 'groceries')->first();
        $shoppingCategory = Category::where('slug', 'shopping')->first();
        $foodCategory = Category::where('slug', 'food')->first();

        $now = now();
        $today = $now->toDateString();

        // 1. Anita's shared spending (18,400)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $groceriesCategory->id,
            'type' => 'expense',
            'amount' => 18400.00,
            'date' => $today,
            'visibility' => 'shared',
            'note' => 'Monthly Provisions',
        ]);

        // 2. Anita's personal spending (4,380)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $shoppingCategory->id,
            'type' => 'expense',
            'amount' => 2199.00,
            'date' => $today,
            'visibility' => 'personal',
            'note' => 'Shopping',
        ]);

        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $foodCategory->id,
            'type' => 'expense',
            'amount' => 2181.00,
            'date' => $today,
            'visibility' => 'personal',
            'note' => 'Lunch with friends',
        ]);

        // 3. Rakesh's shared spending (21,200)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $rakesh->id,
            'category_id' => $groceriesCategory->id,
            'type' => 'expense',
            'amount' => 21200.00,
            'date' => $today,
            'visibility' => 'shared',
            'note' => 'Groceries & utilities',
        ]);

        // 4. Dadaji's shared spending (5,900)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $dadaji->id,
            'category_id' => $groceriesCategory->id,
            'type' => 'expense',
            'amount' => 5900.00,
            'date' => $today,
            'visibility' => 'shared',
            'note' => 'Medicines',
        ]);

        // 5. Meera's shared spending (2,900)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $meera->id,
            'category_id' => $foodCategory->id,
            'type' => 'expense',
            'amount' => 2900.00,
            'date' => $today,
            'visibility' => 'shared',
            'note' => 'Books & Snacks',
        ]);

        // Total shared: 18,400 + 21,200 + 5,900 + 2,900 = 48,400
        // Budget: 65,000 => Remaining: 16,600
        // Anita total spending: 18,400 (household) + 4,380 (personal) = 22,780

        $response = $this->withToken($anitaToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.month', now()->format('F'))
            // Household Card
            ->assertJsonPath('data.household.total_spend', 48400)
            // Personal Card
            ->assertJsonPath('data.personal.total_spend', 4380);

        // Verify Members List: Anita is first with display_name 'You'
        $members = $response->json('data.household.members');
        $this->assertCount(4, $members);
        $this->assertEquals('You', $members[0]['display_name']);
        $this->assertTrue($members[0]['is_current_user']);
        $this->assertEquals(18400, $members[0]['amount']);

        // Verify Recent Household Transactions
        $recentHousehold = $response->json('data.household.recent_transactions');
        $this->assertNotEmpty($recentHousehold);
        $this->assertIsInt($recentHousehold[0]['date']);
        $this->assertEquals(Carbon::parse($today, 'UTC')->timestamp, $recentHousehold[0]['date']);

        // Verify Recent Personal Transactions
        $recentPersonal = $response->json('data.personal.recent_transactions');
        $this->assertCount(2, $recentPersonal);
        $this->assertEquals('Lunch with friends', $recentPersonal[0]['title']);
        $this->assertEquals('Shopping', $recentPersonal[1]['title']);
    }

    public function test_dashboard_hides_other_members_personal_transactions(): void
    {
        $family = Family::create(['name' => 'Sharma Parivar']);

        $anita = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($anita->id, ['role' => 'owner']);
        $anitaToken = $anita->createToken('auth_token')->plainTextToken;

        $rakesh = User::create([
            'name' => 'Rakesh',
            'email' => 'rakesh@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($rakesh->id, ['role' => 'member']);

        $category = Category::where('slug', 'shopping')->first();

        // Rakesh's personal transaction (Must NEVER be visible to Anita)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $rakesh->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 9999.00,
            'date' => now()->toDateString(),
            'visibility' => 'personal',
            'note' => 'Secret gift',
        ]);

        $response = $this->withToken($anitaToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/dashboard');

        $response->assertStatus(200);

        // Anita's personal spend is 0, not 9999
        $this->assertEquals(0, $response->json('data.personal.total_spend'));
        $this->assertEmpty($response->json('data.personal.recent_transactions'));
        $this->assertEquals(0, $response->json('data.household.total_spend'));
    }

    public function test_dashboard_supports_month_and_year_parameters(): void
    {
        $family = Family::create(['name' => 'Sharma Parivar']);

        $anita = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $family->users()->attach($anita->id, ['role' => 'owner']);
        $anitaToken = $anita->createToken('auth_token')->plainTextToken;

        $category = Category::where('slug', 'groceries')->first();

        // Transaction in October 2026
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 5000.00,
            'date' => '2026-10-15',
            'visibility' => 'shared',
        ]);

        // Transaction in November 2026
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 8000.00,
            'date' => '2026-11-05',
            'visibility' => 'shared',
        ]);

        // Query October 2026
        $responseOct = $this->withToken($anitaToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/dashboard?month=10&year=2026');

        $responseOct->assertStatus(200)
            ->assertJsonPath('data.month', 'October')
            ->assertJsonPath('data.household.total_spend', 5000);

        // Query November 2026
        $responseNov = $this->withToken($anitaToken)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/dashboard?month=11&year=2026');

        $responseNov->assertStatus(200)
            ->assertJsonPath('data.month', 'November')
            ->assertJsonPath('data.household.total_spend', 8000);
    }
}
