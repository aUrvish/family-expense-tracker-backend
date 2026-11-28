<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordCodeMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Handles authentication for the family budget app.
 *
 * Each email address maps to a single global `users` record. Family memberships
 * and per-family roles (`owner` or `member`) are stored in `family_user`.
 *
 * Response helpers `successResponse()` and `errorResponse()` are provided by
 * the base Controller.
 */
class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * - With `invite_code` (an invitation code): validates the invitation and joins that existing family as a member.
     * - Without `invite_code`: creates a new family and joins it as owner.
     *
     * If an account with the email already exists, registration is rejected and the
     * user is instructed to log in (and join additional families after login).
     *
     * @param  Request  $request  Expects: name, email, password, password_confirmation,
     *                            and optionally invite_code and family_name.
     * @return JsonResponse 201 with `access_token`, `user`, and `families`,
     *                      422 on validation errors, 500 on unexpected failure.
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $input = $request->all();

            if (isset($input['email']) && is_string($input['email'])) {
                $input['email'] = strtolower(trim($input['email']));
            }

            $validator = Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'invite_code' => ['nullable', 'string', 'max:64'],
                'family_name' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();

            $user = DB::transaction(function () use ($data) {
                $hasInviteCode = ! empty($data['invite_code']);

                if (User::where('email', $data['email'])->exists()) {
                    $message = $hasInviteCode
                        ? 'An account with this email already exists. Please log in to join another family.'
                        : 'An account with this email already exists. Please log in.';

                    throw ValidationException::withMessages([
                        'email' => [$message],
                    ]);
                }

                if ($hasInviteCode) {
                    // Lock invitation row inside transaction to prevent race conditions.
                    $invitation = FamilyInvitation::where('code', $data['invite_code'])
                        ->lockForUpdate()
                        ->first();

                    if (! $invitation || ! $invitation->isValid()) {
                        throw ValidationException::withMessages([
                            'invite_code' => ['The provided invitation code is invalid or expired.'],
                        ]);
                    }

                    $family = Family::findOrFail($invitation->family_id);
                    $role = 'member';
                } else {
                    $family = Family::create([
                        'name' => $data['family_name'] ?? $data['name']."'s Family",
                    ]);

                    $role = 'owner';
                }

                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => $data['email'],
                    'password' => $data['password'], // hashed by the model cast
                ]);

                $family->users()->attach($user->id, ['role' => $role]);

                return $user;
            });

            return $this->successResponse([
                'access_token' => $user->createToken('auth_token')->plainTextToken,
                'user' => $user,
                'families' => $user->families,
            ], 'User registered successfully.', 201);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Register failed');
        }
    }

    /**
     * Log in and return an access token, the user, and all of their families with roles.
     *
     * @param  Request  $request  Expects: email, password.
     * @return JsonResponse 200 with `access_token`, `user` and `families`,
     *                      401 on invalid credentials, 422 on validation errors.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $input = $request->all();

            if (isset($input['email']) && is_string($input['email'])) {
                $input['email'] = strtolower(trim($input['email']));
            }

            $validator = Validator::make($input, [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();
            $user = User::where('email', $data['email'])->first();

            if (! $user || ! Hash::check($data['password'], $user->password)) {
                return $this->errorResponse('Invalid credentials.', 401);
            }

            return $this->successResponse([
                'access_token' => $user->createToken('auth_token')->plainTextToken,
                'user' => $user,
                'families' => $user->families,
            ], 'Login successful.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Login failed');
        }
    }

    /**
     * Get the currently authenticated user's profile with their active family details.
     *
     * @param  Request  $request  Authenticated request (Sanctum + IdentifyFamily).
     * @return JsonResponse 200 with `user` (including active `family`).
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $family = tenant();

            if ($family instanceof Family) {
                $role = $user->roleInFamily($family->id);
                $user->setAttribute('role', $role);
                $user->setAttribute('family', [
                    'id' => $family->id,
                    'name' => $family->name,
                    'role' => $role,
                ]);
            }

            return $this->successResponse([
                'user' => $user,
            ], 'User profile fetched successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch profile failed');
        }
    }

    /**
     * Update the authenticated user's profile (name and/or email).
     *
     * @param  Request  $request  Authenticated request (Sanctum + IdentifyFamily).
     * @return JsonResponse 200 with updated `user` (including active `family`), or 422 on validation errors.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $currentUser = $request->user();
            $input = $request->all();

            if (isset($input['email']) && is_string($input['email'])) {
                $input['email'] = strtolower(trim($input['email']));
            }

            $validator = Validator::make($input, [
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (trim((string) $value) === '') {
                            $fail('The name field is required.');
                        }
                    },
                ],
                'email' => [
                    'sometimes',
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($currentUser->id),
                ],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $updates = $validator->validated();

            if (isset($updates['name'])) {
                $updates['name'] = trim($updates['name']);
            }

            if ($updates !== []) {
                $currentUser->update($updates);
            }

            $updatedUser = $currentUser->fresh();
            $family = tenant();

            if ($family instanceof Family) {
                $role = $updatedUser->roleInFamily($family->id);
                $updatedUser->setAttribute('role', $role);
                $updatedUser->setAttribute('family', [
                    'id' => $family->id,
                    'name' => $family->name,
                    'role' => $role,
                ]);
            }

            return $this->successResponse([
                'user' => $updatedUser,
            ], 'Profile updated successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Update profile failed');
        }
    }

    /**
     * Log out by revoking only the current access token.
     *
     * Other devices stay logged in.
     *
     * @param  Request  $request  Authenticated request (Sanctum).
     * @return JsonResponse 200 on success.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()?->delete();

            return $this->successResponse(null, 'Logged out successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Logout failed');
        }
    }

    /**
     * Send a 6-digit password reset code to the given email.
     *
     * Returns a generic response to prevent account enumeration.
     * The code is stored as a secure hash with a 15-minute expiration.
     *
     * @param  Request  $request  Expects: email.
     * @return JsonResponse 200 generic success response, 422 on validation errors.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $email = strtolower(trim($validator->validated()['email']));
            $genericMessage = 'If an account with that email exists, a password reset code has been sent.';

            $userExists = User::where('email', $email)->exists();

            if ($userExists) {
                $expires = (int) config('auth.passwords.users.expire', 15);
                $code = (string) random_int(100000, 999999);

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $email],
                    [
                        'token' => Hash::make($code),
                        'attempts' => 0,
                        'created_at' => now(),
                    ]
                );

                Mail::to($email)->send(new ResetPasswordCodeMail($code, $expires));
            }

            return $this->successResponse(null, $genericMessage);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Forgot password failed');
        }
    }

    /**
     * Reset the password using the 6-digit code and account email.
     *
     * Requires both email and code to prevent brute-force attacks.
     * Enforces a 5-attempt limit and 15-minute expiration.
     *
     * @param  Request  $request  Expects: email, code, password, password_confirmation.
     * @return JsonResponse 200 on success, 422 on invalid/expired code or validation errors.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'code' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();
            $email = strtolower(trim($data['email']));

            $record = DB::table('password_reset_tokens')->where('email', $email)->first();

            if (! $record) {
                return $this->errorResponse('Invalid or expired password reset code.', 400);
            }

            $expires = (int) config('auth.passwords.users.expire', 15);
            if (Carbon::parse($record->created_at)->addMinutes($expires)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return $this->errorResponse('Invalid or expired password reset code.', 400);
            }

            if ((int) ($record->attempts ?? 0) >= 5) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return $this->errorResponse('Too many failed attempts. Please request a new password reset code.', 429);
            }

            if (! Hash::check($data['code'], $record->token)) {
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->increment('attempts');

                return $this->errorResponse('Invalid or expired password reset code.', 400);
            }

            $user = User::where('email', $email)->first();

            if (! $user) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return $this->errorResponse('Invalid or expired password reset code.', 400);
            }

            DB::transaction(function () use ($user, $data, $email) {
                $user->forceFill([
                    'password' => $data['password'],
                ])->save();

                // Revoke all Sanctum tokens upon password reset
                $user->tokens()->delete();

                event(new PasswordReset($user));

                DB::table('password_reset_tokens')->where('email', $email)->delete();
            });

            return $this->successResponse(null, 'Password has been reset successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Reset password failed');
        }
    }

    /**
     * Change password for the authenticated user and revoke other active sessions.
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();
            $currentUser = $request->user();

            if (! Hash::check($data['current_password'], $currentUser->password)) {
                return $this->errorResponse('Validation failed.', 422, [
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }

            $currentTokenId = $currentUser->currentAccessToken()?->id;

            DB::transaction(function () use ($currentUser, $data, $currentTokenId) {
                $currentUser->forceFill(['password' => $data['password']])->save();

                // Revoke other tokens, keep current device session active
                if ($currentTokenId) {
                    $currentUser->tokens()->where('id', '!=', $currentTokenId)->delete();
                }
            });

            return $this->successResponse(null, 'Password changed successfully. Other active sessions have been revoked.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Change password failed');
        }
    }
}
