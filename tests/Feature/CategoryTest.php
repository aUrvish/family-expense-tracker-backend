<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_categories(): void
    {
        $response = $this->getJson('/api/categories');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_categories_list(): void
    {
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

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/categories');

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

    public function test_categories_are_central_and_accessible_with_or_without_family_context(): void
    {
        $this->seed(CategorySeeder::class);

        $family = Family::create(['name' => 'Smith Family']);
        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        // 1. Without X-Family-Id header
        $responseWithoutHeader = $this->withToken($token)
            ->getJson('/api/categories');

        $responseWithoutHeader->assertStatus(200)
            ->assertJsonPath('status', true);

        // 2. With X-Family-Id header
        $responseWithHeader = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/categories');

        $responseWithHeader->assertStatus(200)
            ->assertJsonPath('status', true);
    }
}
