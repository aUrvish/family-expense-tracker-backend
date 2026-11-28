<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiFamilySupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_multiple_families_and_list_them(): void
    {
        // Register first family
        $regRes = $this->postJson('/api/register', [
            'name' => 'Urvish',
            'email' => 'urvish@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'family_name' => 'Primary Family',
        ]);

        $regRes->assertStatus(201);
        $token = $regRes->json('data.access_token');
        $primaryFamilyId = $regRes->json('data.families.0.id');

        // Requires X-Family-Id header
        $this->withToken($token)->getJson('/api/families')->assertStatus(400);

        // Create second family via authenticated endpoint (name only)
        $createRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $primaryFamilyId)
            ->postJson('/api/families', [
                'name' => 'Second Family',
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.family.name', 'Second Family')
            ->assertJsonPath('data.family.role', 'owner');

        // Empty payload or sending both name and invite_code fails validation
        $this->withToken($token)
            ->withHeader('X-Family-Id', $primaryFamilyId)
            ->postJson('/api/families', [])
            ->assertStatus(422);
        $this->withToken($token)
            ->withHeader('X-Family-Id', $primaryFamilyId)
            ->postJson('/api/families', [
                'name' => 'Another',
                'invite_code' => 'inv_123',
            ])
            ->assertStatus(422);

        // List families returns both as owner
        $listRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $primaryFamilyId)
            ->getJson('/api/families');
        $listRes->assertStatus(200)
            ->assertJsonCount(2, 'data.families');

        $families = collect($listRes->json('data.families'))->keyBy('name');
        $this->assertEquals('owner', $families['Primary Family']['pivot']['role']);
        $this->assertEquals('owner', $families['Second Family']['pivot']['role']);
        $this->assertEquals(1, User::where('email', 'urvish@example.com')->count());
    }

    public function test_authenticated_user_can_join_another_family_without_changing_password_or_verification(): void
    {
        $familyOwner = User::create([
            'name' => 'Rahul',
            'email' => 'rahul@example.com',
            'password' => 'RahulPass123!',
        ]);
        $rahulFamily = Family::create(['name' => "Rahul's Family"]);
        $rahulFamily->users()->attach($familyOwner->id, ['role' => 'owner']);

        $invitation = FamilyInvitation::create([
            'family_id' => $rahulFamily->id,
            'invited_by_user_id' => $familyOwner->id,
            'code' => 'inv_join_rahul_family_123',
        ]);

        $verifiedAt = now()->subDays(2);
        $urvish = User::create([
            'name' => 'Urvish',
            'email' => 'urvish@example.com',
            'password' => 'UrvishPass123!',
        ]);
        $urvish->forceFill(['email_verified_at' => $verifiedAt])->save();

        $myFamily = Family::create(['name' => 'My Family']);
        $myFamily->users()->attach($urvish->id, ['role' => 'owner']);

        $token = $urvish->createToken('auth_token')->plainTextToken;

        $joinRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $myFamily->id)
            ->postJson('/api/families', [
                'invite_code' => $invitation->code,
            ]);

        $joinRes->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.family.id', $rahulFamily->id)
            ->assertJsonPath('data.family.name', "Rahul's Family")
            ->assertJsonPath('data.family.role', 'member');

        // Still one global user, password unchanged, email verification preserved globally
        $this->assertEquals(1, User::where('email', 'urvish@example.com')->count());
        $freshUser = $urvish->fresh();
        $this->assertTrue(Hash::check('UrvishPass123!', $freshUser->password));
        $this->assertEquals($verifiedAt->timestamp, $freshUser->email_verified_at->timestamp);

        // Login returns both families with respective roles
        $loginRes = $this->postJson('/api/login', [
            'email' => 'urvish@example.com',
            'password' => 'UrvishPass123!',
        ]);

        $loginRes->assertStatus(200)
            ->assertJsonCount(2, 'data.families');

        $families = collect($loginRes->json('data.families'))->keyBy('name');
        $this->assertEquals('owner', $families['My Family']['pivot']['role']);
        $this->assertEquals('member', $families["Rahul's Family"]['pivot']['role']);
    }

    public function test_authenticated_user_cannot_join_same_family_twice(): void
    {
        $family = Family::create(['name' => 'Existing Family']);
        $user = User::create([
            'name' => 'Urvish',
            'email' => 'urvish@example.com',
            'password' => 'Password123!',
        ]);
        $family->users()->attach($user->id, ['role' => 'owner']);

        $invitation = FamilyInvitation::create([
            'family_id' => $family->id,
            'invited_by_user_id' => $user->id,
            'code' => 'inv_duplicate_membership_test',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $joinRes = $this->withToken($token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/families', [
                'invite_code' => $invitation->code,
            ]);

        $joinRes->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code']);

        // Database unique constraint on (user_id, family_id) also prevents duplicates
        $this->expectException(QueryException::class);
        $family->users()->attach($user->id, ['role' => 'member']);
    }

    public function test_user_can_switch_between_families_using_x_family_id_and_cannot_access_non_member_family(): void
    {
        $user = User::create([
            'name' => 'Urvish',
            'email' => 'urvish@example.com',
            'password' => 'Password123!',
        ]);

        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);
        $strangerFamily = Family::create(['name' => 'Stranger Family']);

        $familyA->users()->attach($user->id, ['role' => 'owner']);
        $familyB->users()->attach($user->id, ['role' => 'member']);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Access Family A -> 200 and role is owner
        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyA->id)
            ->getJson('/api/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.user.family.id', $familyA->id)
            ->assertJsonPath('data.user.family.role', 'owner');

        // Owner can create invitation in Family A
        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyA->id)
            ->postJson('/api/family/invitations')
            ->assertStatus(201);

        // Switch to Family B -> 200 and role is member
        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyB->id)
            ->getJson('/api/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.user.family.id', $familyB->id)
            ->assertJsonPath('data.user.family.role', 'member');

        // Member cannot create invitation in Family B -> 403
        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyB->id)
            ->postJson('/api/family/invitations')
            ->assertStatus(403);

        // Non-member family -> 403
        $this->withToken($token)
            ->withHeader('X-Family-Id', $strangerFamily->id)
            ->getJson('/api/dashboard')
            ->assertStatus(403);

        // Tenant data isolation when switching families
        $category = Category::create(['name' => 'Food', 'slug' => 'food', 'icon' => 'utensils', 'color' => '#FB923C']);
        Transaction::create([
            'family_id' => $familyA->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 50,
            'date' => now()->toDateString(),
            'note' => 'Family A Expense',
        ]);

        $this->withToken($token)
            ->withHeader('X-Family-Id', $familyB->id)
            ->getJson('/api/transactions')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }
}
