<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_reports(): void
    {
        $this->getJson('/api/reports')->assertStatus(401);
    }

    public function test_user_cannot_access_reports_without_family_header(): void
    {
        $user = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/reports')
            ->assertStatus(400);
    }

    public function test_user_can_retrieve_reports_matching_screen_analytics(): void
    {
        $family = Family::create(['name' => 'Sharma Household']);

        $anita = User::create([
            'family_id' => $family->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $anita->createToken('auth_token')->plainTextToken;

        $groceries = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'cart', 'color' => '#10B981']);
        $bills = Category::create(['name' => 'Bills & utilities', 'slug' => 'bills-utilities', 'icon' => 'bolt', 'color' => '#3B82F6']);
        $school = Category::create(['name' => 'School', 'slug' => 'school', 'icon' => 'book', 'color' => '#F59E0B']);
        $medical = Category::create(['name' => 'Medical', 'slug' => 'medical', 'icon' => 'plus', 'color' => '#EF4444']);
        $other = Category::create(['name' => 'Other', 'slug' => 'other', 'icon' => 'dots', 'color' => '#6B7280']);

        // Previous month: October 2026 (Spend 51,360)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $groceries->id,
            'type' => 'expense',
            'amount' => 51360,
            'date' => '2026-10-15',
            'visibility' => 'shared',
        ]);

        // Current month: November 2026 (Total spend 48,260 => 3,100 less than October)
        // Week 1 (Day 1-7): 10,500
        Transaction::create(['family_id' => $family->id, 'user_id' => $anita->id, 'category_id' => $groceries->id, 'type' => 'expense', 'amount' => 10500, 'date' => '2026-11-04', 'visibility' => 'shared']);
        // Week 2 (Day 8-14): 16,200
        Transaction::create(['family_id' => $family->id, 'user_id' => $anita->id, 'category_id' => $bills->id, 'type' => 'expense', 'amount' => 11200, 'date' => '2026-11-10', 'visibility' => 'shared']);
        Transaction::create(['family_id' => $family->id, 'user_id' => $anita->id, 'category_id' => $groceries->id, 'type' => 'expense', 'amount' => 4300, 'date' => '2026-11-12', 'visibility' => 'shared']);
        // Week 3 (Day 15-21): 9,600
        Transaction::create(['family_id' => $family->id, 'user_id' => $anita->id, 'category_id' => $school->id, 'type' => 'expense', 'amount' => 9600, 'date' => '2026-11-18', 'visibility' => 'shared']);
        // Week 4 (Day 22-30): 11,960
        Transaction::create(['family_id' => $family->id, 'user_id' => $anita->id, 'category_id' => $medical->id, 'type' => 'expense', 'amount' => 8400, 'date' => '2026-11-25', 'visibility' => 'shared']);
        Transaction::create(['family_id' => $family->id, 'user_id' => $anita->id, 'category_id' => $other->id, 'type' => 'expense', 'amount' => 4260, 'date' => '2026-11-28', 'visibility' => 'shared']);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/reports?month=11&year=2026&scope=household');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.month', 'November')
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.scope', 'household')
            ->assertJsonPath('data.total_spend', 48260)
            ->assertJsonPath('data.comparison.previous_month', 'October')
            ->assertJsonPath('data.comparison.previous_total', 51360);

        // Weekly breakdown (4 weeks)
        $weeks = $response->json('data.weekly_breakdown');
        $this->assertCount(4, $weeks);
        $this->assertEquals('Week 1', $weeks[0]['label']);
        $this->assertEquals(21.76, $weeks[0]['percentage']);
        $this->assertEquals('Week 2', $weeks[1]['label']);
        $this->assertEquals(32.12, $weeks[1]['percentage']);

        // Category breakdown (sorted descending)
        $categories = $response->json('data.by_category');
        $this->assertNotEmpty($categories);
        $this->assertEquals('Groceries', $categories[0]['name']);
        $this->assertEquals(14800, $categories[0]['amount']);
    }

    public function test_user_can_retrieve_personal_report(): void
    {
        $family = Family::create(['name' => 'Sharma Household']);

        $anita = User::create([
            'family_id' => $family->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $anita->createToken('auth_token')->plainTextToken;

        $rakesh = User::create([
            'family_id' => $family->id,
            'name' => 'Rakesh',
            'email' => 'rakesh@example.com',
            'password' => 'secret123',
        ]);

        $category = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'cart', 'color' => '#10B981']);

        // Anita's personal expense
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $anita->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 3000,
            'date' => '2026-11-05',
            'visibility' => 'personal',
        ]);

        // Rakesh's expense (should NOT be included in Anita's personal report)
        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $rakesh->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 7000,
            'date' => '2026-11-05',
            'visibility' => 'personal',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/reports?month=11&year=2026&scope=personal');

        $response->assertStatus(200)
            ->assertJsonPath('data.scope', 'personal')
            ->assertJsonPath('data.total_spend', 3000);
    }

    public function test_user_can_export_report_csv(): void
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

        Transaction::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1250,
            'date' => '2026-11-10',
            'visibility' => 'shared',
            'note' => 'Weekly milk & veg',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->get('/api/reports/export?month=11&year=2026');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename="report-November-2026.csv"', $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Weekly milk & veg', $content);
        $this->assertStringContainsString('1250', $content);
    }
}
