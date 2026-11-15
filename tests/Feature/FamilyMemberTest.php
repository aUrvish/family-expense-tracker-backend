<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_family_members(): void
    {
        $this->getJson('/api/family/members')->assertStatus(401);
    }

    public function test_family_members_requires_family_header(): void
    {
        $user = User::create([
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/family/members')
            ->assertStatus(400);
    }

    public function test_user_can_list_family_members_in_active_family(): void
    {
        $familyA = Family::create(['name' => 'Sharma Parivar']);
        $familyB = Family::create(['name' => 'Patel Parivar']);

        $anita = User::create([
            'family_id' => $familyA->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $anitaToken = $anita->createToken('auth_token')->plainTextToken;

        $rakesh = User::create([
            'family_id' => $familyA->id,
            'name' => 'Rakesh',
            'email' => 'rakesh@example.com',
            'password' => 'secret123',
        ]);

        $dadaji = User::create([
            'family_id' => $familyA->id,
            'name' => 'Dadaji',
            'email' => 'dadaji@example.com',
            'password' => 'secret123',
        ]);

        // User in another family (must NOT appear)
        User::create([
            'family_id' => $familyB->id,
            'name' => 'Other Member',
            'email' => 'other@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->withToken($anitaToken)
            ->withHeader('X-Family-Id', $familyA->id)
            ->getJson('/api/family/members');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Family members retrieved successfully.');

        $members = $response->json('data');
        $this->assertCount(3, $members);

        $anitaEntry = collect($members)->firstWhere('id', $anita->id);
        $this->assertNotNull($anitaEntry);
        $this->assertEquals('Anita', $anitaEntry['name']);
        $this->assertEquals('anita@example.com', $anitaEntry['email']);
        $this->assertNotNull($anitaEntry['joined_at']);
        $this->assertArrayNotHasKey('avatar_initial', $anitaEntry);
        $this->assertArrayNotHasKey('is_current_user', $anitaEntry);

        $rakeshEntry = collect($members)->firstWhere('id', $rakesh->id);
        $this->assertNotNull($rakeshEntry);
        $this->assertEquals('Rakesh', $rakeshEntry['name']);
        $this->assertEquals('rakesh@example.com', $rakeshEntry['email']);
        $this->assertArrayNotHasKey('avatar_initial', $rakeshEntry);
        $this->assertArrayNotHasKey('is_current_user', $rakeshEntry);
    }

    public function test_user_cannot_access_members_of_unauthorized_family(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $user = User::create([
            'family_id' => $familyA->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyB->id)
            ->getJson('/api/family/members')
            ->assertStatus(403);
    }

    public function test_user_can_view_specific_family_member_profile(): void
    {
        $family = Family::create(['name' => 'Sharma Parivar']);

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

        $response = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson("/api/family/members/{$rakesh->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $rakesh->id)
            ->assertJsonPath('data.name', 'Rakesh')
            ->assertJsonPath('data.email', 'rakesh@example.com')
            ->assertJsonPath('data.joined_at', $rakesh->created_at->toDateString())
            ->assertJsonMissingPath('data.avatar_initial')
            ->assertJsonMissingPath('data.is_current_user')
            ->assertJsonMissingPath('data.summary')
            ->assertJsonMissingPath('data.recent_transactions');
    }

    public function test_family_member_profile_returns_404_for_member_in_another_family(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $anita = User::create([
            'family_id' => $familyA->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $anita->createToken('auth_token')->plainTextToken;

        $otherUser = User::create([
            'family_id' => $familyB->id,
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => 'secret123',
        ]);

        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyA->id)
            ->getJson("/api/family/members/{$otherUser->id}")
            ->assertStatus(404)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Family member not found.');
    }

    public function test_family_member_profile_returns_404_for_non_existent_id(): void
    {
        $family = Family::create(['name' => 'Family A']);

        $anita = User::create([
            'family_id' => $family->id,
            'name' => 'Anita',
            'email' => 'anita@example.com',
            'password' => 'secret123',
        ]);
        $token = $anita->createToken('auth_token')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/family/members/99999')
            ->assertStatus(404)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Family member not found.');
    }
}
