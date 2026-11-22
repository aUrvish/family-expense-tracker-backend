<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentifyFamily;
use App\Mail\ResetPasswordCodeMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_creates_new_family_when_no_family_id_provided(): void
    {
        $payload = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'access_token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'family_id',
                        'family' => ['id', 'name'],
                    ],
                ],
            ])
            ->assertJsonMissingPath('data.token_type')
            ->assertJsonMissingPath('data.family')
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        $this->assertDatabaseCount('families', 1);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user->family_id);
        $this->assertEquals($user->name."'s Family", $user->family->name);
    }

    public function test_user_can_register_with_custom_family_name(): void
    {
        $payload = [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'family_name' => 'The Doe Household',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('families', [
            'name' => 'The Doe Household',
        ]);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertEquals('The Doe Household', $user->family->name);
    }

    public function test_user_can_register_into_existing_family_with_invite_code_without_creating_new_family(): void
    {
        $existingFamily = Family::create([
            'name' => 'Existing Family',
        ]);

        $invitation = FamilyInvitation::create([
            'family_id' => $existingFamily->id,
            'code' => 'inv_test_valid_code_123',
        ]);

        $this->assertDatabaseCount('families', 1);

        $payload = [
            'name' => 'Family Member',
            'email' => 'member@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonMissingPath('data.family')
            ->assertJsonMissingPath('data.token_type')
            ->assertJsonPath('data.user.family.id', $existingFamily->id)
            ->assertJsonPath('data.user.family_id', $existingFamily->id);

        // Ensures no second family was created
        $this->assertDatabaseCount('families', 1);

        $user = User::where('email', 'member@example.com')->first();
        $this->assertEquals($existingFamily->id, $user->family_id);
    }

    public function test_registration_fails_if_invite_code_is_invalid(): void
    {
        $payload = [
            'name' => 'Invalid Code User',
            'email' => 'invalid@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => '00000000-0000-0000-0000-000000000000',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['invite_code']]);

        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.com']);
        $this->assertDatabaseCount('families', 0);
    }

    public function test_registration_fails_with_invalid_data(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['name', 'email', 'password']]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $family = Family::create(['name' => 'Miller Family']);
        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Mark Miller',
            'email' => 'mark@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'mark@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'access_token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'family_id',
                        'family' => ['id', 'name'],
                    ],
                    'families',
                ],
            ])
            ->assertJsonMissingPath('data.token_type')
            ->assertJsonCount(1, 'data.families')
            ->assertJsonPath('data.families.0.name', 'Miller Family');
    }

    public function test_login_returns_all_families_associated_with_user_email(): void
    {
        $familyA = Family::create(['name' => 'Family Alpha']);
        $familyB = Family::create(['name' => 'Family Beta']);

        User::create([
            'family_id' => $familyA->id,
            'name' => 'Multi User A',
            'email' => 'multi@example.com',
            'password' => 'secret123',
        ]);

        User::create([
            'family_id' => $familyB->id,
            'name' => 'Multi User B',
            'email' => 'multi@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'multi@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data.families');

        $familyNames = collect($response->json('data.families'))->pluck('name')->all();
        $this->assertContains('Family Alpha', $familyNames);
        $this->assertContains('Family Beta', $familyNames);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Mark Miller',
            'email' => 'mark@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'mark@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid credentials.',
            ]);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $family = Family::create(['name' => 'Brown Family']);
        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Charlie Brown',
            'email' => 'charlie@example.com',
            'password' => 'secret123',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.email', 'charlie@example.com')
            ->assertJsonPath('data.user.family.name', 'Brown Family')
            ->assertJsonPath('data.families.0.name', 'Brown Family');
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::create([
            'name' => 'Charlie Brown',
            'email' => 'charlie@example.com',
            'password' => 'secret123',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Logged out successfully.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_request_password_reset_code(): void
    {
        Mail::fake();

        User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'alice@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'If an account with that email exists, a password reset code has been sent.',
            ]);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'alice@example.com',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', 'alice@example.com')->first();
        $this->assertNotNull($record);

        Mail::assertSent(ResetPasswordCodeMail::class, function (ResetPasswordCodeMail $mail) use ($record) {
            return $mail->hasTo('alice@example.com') && Hash::check($mail->code, $record->token);
        });
    }

    public function test_reset_password_code_mail_renders_markdown_template(): void
    {
        $mailable = new ResetPasswordCodeMail('654321', 15);

        $mailable->assertSeeInHtml('654321');
        $mailable->assertSeeInHtml('Password Reset Code');
        $mailable->assertHasSubject('Password Reset Code');
    }

    public function test_cannot_request_password_reset_code_for_non_existent_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        // Returns generic 200 response to prevent account enumeration
        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'If an account with that email exists, a password reset code has been sent.',
            ]);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'unknown@example.com',
        ]);
    }

    public function test_same_email_can_register_in_different_families(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $inviteA = FamilyInvitation::create([
            'family_id' => $familyA->id,
            'code' => 'inv_test_family_a_code',
        ]);

        $inviteB = FamilyInvitation::create([
            'family_id' => $familyB->id,
            'code' => 'inv_test_family_b_code',
        ]);

        $responseA = $this->postJson('/api/register', [
            'name' => 'Shared Email User A',
            'email' => 'shared@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $inviteA->code,
        ]);

        $responseA->assertStatus(201);

        $responseB = $this->postJson('/api/register', [
            'name' => 'Shared Email User B',
            'email' => 'shared@example.com',
            'password' => 'password456',
            'password_confirmation' => 'password456',
            'invite_code' => $inviteB->code,
        ]);

        $responseB->assertStatus(201);

        $this->assertEquals(2, User::where('email', 'shared@example.com')->count());
    }

    public function test_same_email_cannot_register_twice_in_same_family(): void
    {
        $family = Family::create(['name' => 'Only One Family']);

        $invite = FamilyInvitation::create([
            'family_id' => $family->id,
            'code' => 'inv_test_only_one_family',
        ]);

        User::create([
            'family_id' => $family->id,
            'name' => 'First Member',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'Second Member',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invite->code,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_user_can_reset_password_with_valid_code(): void
    {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'alice@example.com',
            'token' => Hash::make('123456'),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'alice@example.com',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Password has been reset successfully.',
            ]);

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'alice@example.com',
        ]);
    }

    public function test_user_cannot_reset_password_with_invalid_code(): void
    {
        User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'alice@example.com',
            'token' => Hash::make('123456'),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'alice@example.com',
            'code' => '999999',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid or expired password reset code.',
            ]);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'alice@example.com',
            'attempts' => 1,
        ]);
    }

    public function test_user_cannot_reset_password_with_expired_code(): void
    {
        User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'oldpassword123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'alice@example.com',
            'token' => Hash::make('123456'),
            'attempts' => 0,
            'created_at' => now()->subMinutes(16),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'alice@example.com',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid or expired password reset code.',
            ]);
    }

    public function test_identify_family_middleware_initializes_tenancy_for_valid_family_member(): void
    {
        $family = Family::create(['name' => 'Valid Family']);
        $user = User::create([
            'family_id' => $family->id,
            'name' => 'Valid Member',
            'email' => 'valid@example.com',
            'password' => 'secret123',
        ]);

        $request = Request::create('/test-tenant', 'GET');
        $request->headers->set('X-Family-Id', $family->id);
        $request->setUserResolver(fn () => $user);

        $middleware = new IdentifyFamily;
        $response = $middleware->handle($request, function ($req) {
            return response()->json(['tenant_id' => tenant('id')]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($family->id, tenant('id'));
    }

    public function test_identify_family_middleware_aborts_when_family_id_is_missing(): void
    {
        $request = Request::create('/test-tenant', 'GET');
        $middleware = new IdentifyFamily;

        $this->expectException(HttpException::class);
        $middleware->handle($request, fn () => response()->noContent());
    }

    public function test_identify_family_middleware_aborts_when_user_is_not_member_of_family(): void
    {
        $familyA = Family::create(['name' => 'Family A']);
        $familyB = Family::create(['name' => 'Family B']);

        $user = User::create([
            'family_id' => $familyA->id,
            'name' => 'Member A',
            'email' => 'membera@example.com',
            'password' => 'secret123',
        ]);

        $request = Request::create('/test-tenant', 'GET');
        $request->headers->set('X-Family-Id', $familyB->id);
        $request->setUserResolver(fn () => $user);

        $middleware = new IdentifyFamily;

        $this->expectException(HttpException::class);
        $middleware->handle($request, fn () => response()->noContent());
    }
}
