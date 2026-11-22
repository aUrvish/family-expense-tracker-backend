<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordCodeMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Handles authentication for the family budget app.
 *
 * A single email address may exist in several families (one `users` row per
 * family), so lookups by email can return multiple users. Passwords are
 * hashed by the `hashed` cast on the User model.
 *
 * Response helpers `successResponse()` and `errorResponse()` are provided by
 * the base Controller.
 */
class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * - With `invite_code` (a family UUID): joins that existing family.
     * - Without `invite_code`: creates a new family and joins it.
     *
     * The same email may register in multiple different families, but only
     * once per family.
     *
     * @param  Request  $request  Expects: name, email, password, password_confirmation,
     *                            and optionally invite_code and family_name.
     * @return JsonResponse 201 with `access_token` and `user` (with family),
     *                      422 on validation errors, 500 on unexpected failure.
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
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

            // Family and user are saved atomically: never a family without a user.
            $user = DB::transaction(function () use ($data) {
                if (! empty($data['invite_code'])) {
                    // Lock invitation row inside transaction to prevent race conditions
                    $invitation = FamilyInvitation::where('code', $data['invite_code'])
                        ->lockForUpdate()
                        ->first();

                    if (! $invitation || ! $invitation->isValid($data['email'])) {
                        throw ValidationException::withMessages([
                            'invite_code' => ['The provided invitation code is invalid, expired, or not authorized for this email.'],
                        ]);
                    }

                    $family = Family::findOrFail($invitation->family_id);

                    // Re-check inside the transaction to guard against race conditions.
                    if ($family->users()->where('email', $data['email'])->exists()) {
                        throw ValidationException::withMessages([
                            'email' => ['The email has already been taken in this family.'],
                        ]);
                    }

                    $role = 'member';
                } else {
                    $family = Family::create([
                        'name' => $data['family_name'] ?? $data['name']."'s Family",
                    ]);

                    $role = 'owner';
                }

                return User::create([
                    'family_id' => $family->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'], // hashed by the model cast
                    'role' => $role,
                ]);
            });

            return $this->successResponse([
                'access_token' => $user->createToken('auth_token')->plainTextToken,
                'user' => $user->load('family'),
            ], 'User registered successfully.', 201);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Register failed');
        }
    }

    /**
     * Log in and return an access token, the user, and all of their families.
     *
     * Because one email can belong to several families, every user row with
     * that email is loaded and the first one whose password matches is used.
     *
     * @param  Request  $request  Expects: email, password.
     * @return JsonResponse 200 with `access_token`, `user` and `families`,
     *                      401 on invalid credentials, 422 on validation errors.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();

            // Single query; `family` is eager loaded to avoid N+1.
            $user = User::with('family')
                ->where('email', $data['email'])
                ->get()
                ->first(fn (User $candidate) => Hash::check($data['password'], $candidate->password));

            if (! $user) {
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
     * Get the currently authenticated user with their families.
     *
     * @param  Request  $request  Authenticated request (Sanctum).
     * @return JsonResponse 200 with `user` and `families`.
     */
    public function user(Request $request): JsonResponse
    {
        try {
            $user = $request->user()->load('family');

            return $this->successResponse([
                'user' => $user,
                'families' => $user->families,
            ], 'User profile fetched successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch user failed');
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
     * @return JsonResponse 200 on success, 400 for an invalid/expired code,
     *                      429 for too many attempts, 422 on validation errors.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'code' => ['required', 'string', 'size:6'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();
            $email = strtolower(trim($data['email']));
            $expires = (int) config('auth.passwords.users.expire', 15);

            $result = DB::transaction(function () use ($email, $data, $expires) {
                $record = DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    return $this->errorResponse('Invalid or expired password reset code.', 400);
                }

                $isExpired = Carbon::parse($record->created_at)->addMinutes($expires)->isPast();
                if ($isExpired) {
                    DB::table('password_reset_tokens')->where('email', $email)->delete();

                    return $this->errorResponse('Invalid or expired password reset code.', 400);
                }

                if ((int) $record->attempts >= 5) {
                    DB::table('password_reset_tokens')->where('email', $email)->delete();

                    return $this->errorResponse('Too many failed attempts. Please request a new password reset code.', 429);
                }

                if (! Hash::check($data['code'], $record->token)) {
                    DB::table('password_reset_tokens')->where('email', $email)->increment('attempts');

                    return $this->errorResponse('Invalid or expired password reset code.', 400);
                }

                $users = User::where('email', $email)->get();

                if ($users->isEmpty()) {
                    DB::table('password_reset_tokens')->where('email', $email)->delete();

                    return $this->errorResponse('Invalid or expired password reset code.', 400);
                }

                // Consume token atomically inside transaction before modifying users
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                foreach ($users as $user) {
                    $user->forceFill(['password' => $data['password']])->save();
                    $user->tokens()->delete(); // log out from all devices
                    event(new PasswordReset($user));
                }

                return null;
            });

            if ($result instanceof JsonResponse) {
                return $result;
            }

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
            $users = User::where('email', $currentUser->email)->get();

            DB::transaction(function () use ($users, $data, $currentTokenId) {
                foreach ($users as $user) {
                    $user->forceFill(['password' => $data['password']])->save();

                    // Revoke other tokens, keep current device session active
                    if ($currentTokenId) {
                        $user->tokens()->where('id', '!=', $currentTokenId)->delete();
                    }
                }
            });

            return $this->successResponse(null, 'Password changed successfully. Other active sessions have been revoked.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Change password failed');
        }
    }
}
