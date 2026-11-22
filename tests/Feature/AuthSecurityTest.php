<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordCodeMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Family $family;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::create([
            'name' => 'Security Test Family',
            'currency' => 'USD',
        ]);

        $this->user = User::create([
            'family_id' => $this->family->id,
            'name' => 'Alice Admin',
            'email' => 'alice.security@example.com',
            'password' => 'SecurePass123!',
        ]);

        $this->token = $this->user->createToken('auth-token')->plainTextToken;
    }

    public function test_password_reset_code_is_stored_as_secure_hash_not_plaintext(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $this->user->email,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $record = DB::table('password_reset_tokens')->where('email', $this->user->email)->first();
        $this->assertNotNull($record);

        // Token in DB is a bcrypt/hashed string, NOT the 6-digit plaintext code
        $this->assertNotEquals(6, strlen($record->token));
        $this->assertStringStartsWith('$2y$', $record->token);

        Mail::assertSent(ResetPasswordCodeMail::class, function (ResetPasswordCodeMail $mail) use ($record) {
            return Hash::check($mail->code, $record->token);
        });
    }

    public function test_forgot_password_returns_generic_response_for_non_existent_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        // Generic response matches real user response
        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'If an account with that email exists, a password reset code has been sent.',
            ]);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'nonexistent@example.com',
        ]);
    }

    public function test_reset_password_requires_both_email_and_code(): void
    {
        // 1. Missing email fails validation
        $noEmailRes = $this->postJson('/api/reset-password', [
            'code' => '123456',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $noEmailRes->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // 2. Missing code fails validation
        $noCodeRes = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $noCodeRes->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_reset_password_locks_after_five_failed_attempts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $code = '654321';
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($code),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        // Attempt 1 through 4 with incorrect code -> rejected 400 and increments attempts
        for ($i = 1; $i <= 4; $i++) {
            $res = $this->postJson('/api/reset-password', [
                'email' => $this->user->email,
                'code' => '000000',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

            $res->assertStatus(400);
            $this->assertDatabaseHas('password_reset_tokens', [
                'email' => $this->user->email,
                'attempts' => $i,
            ]);
        }

        // 5th failed attempt
        $res5 = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'code' => '000000',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);
        $res5->assertStatus(400);

        // 6th attempt (even with CORRECT code now) -> locked out with 429 and token pruned
        $resLocked = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'code' => $code,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $resLocked->assertStatus(429)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Too many failed attempts. Please request a new password reset code.');

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }

    public function test_auth_endpoints_are_protected_by_rate_limiting(): void
    {
        // Login endpoint rate limiter: 5 attempts per minute
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'rate.limit@example.com',
                'password' => 'wrongpass',
            ]);
        }

        // 6th attempt is throttled with 429
        $res = $this->postJson('/api/login', [
            'email' => 'rate.limit@example.com',
            'password' => 'wrongpass',
        ]);

        $res->assertStatus(429);
    }

    public function test_reset_password_expires_after_fifteen_minutes(): void
    {
        $code = '112233';
        DB::table('password_reset_tokens')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($code),
            'attempts' => 0,
            'created_at' => now()->subMinutes(16),
        ]);

        $res = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'code' => $code,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired password reset code.');

        // Expired record is pruned
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }

    public function test_family_uuid_is_strictly_rejected_as_invitation_code(): void
    {
        // Attempting to register using a raw family UUID fails
        $res = $this->postJson('/api/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $this->family->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code']);

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_family_invitations_crud_and_lifecycle(): void
    {
        // 1. Create invitation without user limits (hardcoded 48 hours)
        $createRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson('/api/family/invitations');

        $createRes->assertStatus(201)
            ->assertJsonPath('data.status', 'active');

        $inviteId = $createRes->json('data.id');
        $inviteCode = $createRes->json('data.code');
        $this->assertStringStartsWith('inv_', $inviteCode);

        // 2. List invitations
        $listRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->getJson('/api/family/invitations');

        $listRes->assertStatus(200)
            ->assertJsonFragment(['code' => $inviteCode]);

        // 3. First user registers with this invitation -> succeeds
        $regRes1 = $this->postJson('/api/register', [
            'name' => 'Member One',
            'email' => 'member.one@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $inviteCode,
        ]);

        $regRes1->assertStatus(201)
            ->assertJsonPath('data.user.family_id', $this->family->id);

        // 4. Multiple users can join via the same invitation without user limits -> succeeds
        $regRes2 = $this->postJson('/api/register', [
            'name' => 'Member Two',
            'email' => 'member.two@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $inviteCode,
        ]);

        $regRes2->assertStatus(201)
            ->assertJsonPath('data.user.family_id', $this->family->id);
    }

    public function test_invitation_deletion_and_regeneration(): void
    {
        $invite = FamilyInvitation::create([
            'family_id' => $this->family->id,
            'invited_by_user_id' => $this->user->id,
            'code' => 'inv_revoke_test_123',
        ]);

        // Delete / revoke invitation (force delete)
        $revokeRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->deleteJson("/api/family/invitations/{$invite->id}");

        $revokeRes->assertStatus(200)
            ->assertJsonPath('message', 'Invitation deleted successfully.');

        $this->assertDatabaseMissing('family_invitations', ['id' => $invite->id]);

        // Registration with deleted invitation fails
        $regRes = $this->postJson('/api/register', [
            'name' => 'Blocked Member',
            'email' => 'blocked@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $invite->code,
        ]);

        $regRes->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code']);

        // Regenerate invitation
        $activeInvite = FamilyInvitation::create([
            'family_id' => $this->family->id,
            'invited_by_user_id' => $this->user->id,
            'code' => 'inv_regen_test_123',
        ]);

        $regenRes = $this->withToken($this->token)
            ->withHeader('X-Family-Id', $this->family->id)
            ->postJson("/api/family/invitations/{$activeInvite->id}/regenerate");

        $regenRes->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $newCode = $regenRes->json('data.code');
        $this->assertNotEquals('inv_regen_test_123', $newCode);

        // Registration with newly regenerated code succeeds
        $regNewRes = $this->postJson('/api/register', [
            'name' => 'Allowed Member',
            'email' => 'allowed@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $newCode,
        ]);

        $regNewRes->assertStatus(201);
    }

    public function test_invitation_email_restriction_prevents_unauthorized_email(): void
    {
        $invite = FamilyInvitation::create([
            'family_id' => $this->family->id,
            'invited_by_user_id' => $this->user->id,
            'email' => 'specific.person@example.com',
            'code' => 'inv_specific_only_123',
        ]);

        // Registration with different email is rejected
        $wrongEmailRes = $this->postJson('/api/register', [
            'name' => 'Wrong Person',
            'email' => 'wrong.person@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $invite->code,
        ]);

        $wrongEmailRes->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code']);

        // Registration with matching email succeeds
        $correctEmailRes = $this->postJson('/api/register', [
            'name' => 'Specific Person',
            'email' => 'specific.person@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $invite->code,
        ]);

        $correctEmailRes->assertStatus(201);
    }

    public function test_change_password_requires_current_password_and_revokes_other_sessions(): void
    {
        // Create a second session token for Alice
        $otherToken = $this->user->createToken('device-2-token')->plainTextToken;

        // 1. Wrong current password fails validation
        $wrongCurrentRes = $this->withToken($this->token)
            ->postJson('/api/change-password', [
                'current_password' => 'WrongPassword123!',
                'password' => 'BrandNewPassword123!',
                'password_confirmation' => 'BrandNewPassword123!',
            ]);

        $wrongCurrentRes->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // 2. Correct current password succeeds
        $correctCurrentRes = $this->withToken($this->token)
            ->postJson('/api/change-password', [
                'current_password' => 'SecurePass123!',
                'password' => 'BrandNewPassword123!',
                'password_confirmation' => 'BrandNewPassword123!',
            ]);

        $correctCurrentRes->assertStatus(200)
            ->assertJsonPath('status', true);

        $this->user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword123!', $this->user->password));

        // Current session token is still valid
        $authRes = $this->withToken($this->token)->getJson('/api/user');
        $authRes->assertStatus(200);

        // Other session token was revoked
        auth()->forgetGuards();
        $otherRes = $this->withToken($otherToken)->getJson('/api/user');
        $otherRes->assertStatus(401);
    }

    public function test_invitation_expires_after_48_hours(): void
    {
        $invite = FamilyInvitation::create([
            'family_id' => $this->family->id,
            'invited_by_user_id' => $this->user->id,
            'code' => 'inv_48h_expiry_test',
        ]);

        $this->assertFalse($invite->isExpired());

        $this->travel(49)->hours();

        $this->assertTrue($invite->isExpired());

        $regRes = $this->postJson('/api/register', [
            'name' => 'Late Member',
            'email' => 'late@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_code' => $invite->code,
        ]);

        $regRes->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code']);
    }
}
