<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordCodeMail;
use App\Models\Family;
use App\Models\User;
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
            $inviteCode = $request->input('invite_code');

            $emailRules = ['required', 'email', 'max:255'];

            // Email must be unique only inside the family being joined.
            if ($inviteCode) {
                $emailRules[] = Rule::unique('users', 'email')->where('family_id', $inviteCode);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => $emailRules,
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'invite_code' => ['nullable', 'string', 'exists:families,id'],
                'family_name' => ['nullable', 'string', 'max:255'],
            ], [
                'invite_code.exists' => 'The provided invite code is invalid.',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();

            // Family and user are saved atomically: never a family without a user.
            $user = DB::transaction(function () use ($data) {
                if (! empty($data['invite_code'])) {
                    $family = Family::findOrFail($data['invite_code']);

                    // Re-check inside the transaction to guard against race conditions.
                    if ($family->users()->where('email', $data['email'])->exists()) {
                        throw ValidationException::withMessages([
                            'email' => ['The email has already been taken in this family.'],
                        ]);
                    }
                } else {
                    $family = Family::create([
                        'name' => $data['family_name'] ?? $data['name']."'s Family",
                    ]);
                }

                return User::create([
                    'family_id' => $family->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'], // hashed by the model cast
                ]);
            });

            return $this->successResponse([
                'access_token' => $user->createToken('auth_token')->plainTextToken,
                'user' => $user->load('family'),
            ], 'User registered successfully.', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed.', 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('Register failed: '.$e->getMessage());

            return $this->errorResponse();
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
            Log::error('Login failed: '.$e->getMessage());

            return $this->errorResponse();
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
            Log::error('Fetch user failed: '.$e->getMessage());

            return $this->errorResponse();
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
            Log::error('Logout failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Send a 6-digit password reset code to the given email.
     *
     * The code is unique among all currently valid codes, because
     * resetPassword() identifies the account by code alone. Requesting a new
     * code replaces the previous one for that email.
     *
     * @param  Request  $request  Expects: email.
     * @return JsonResponse 200 when the mail is sent, 400 if the email is unknown,
     *                      422 on validation errors.
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

            $email = $validator->validated()['email'];

            if (! User::where('email', $email)->exists()) {
                return $this->errorResponse("We can't find a user with that email address.", 400);
            }

            $expires = config('auth.passwords.users.expire', 60);

            // Regenerate until the code does not clash with another valid code.
            do {
                $code = (string) random_int(100000, 999999);
            } while (DB::table('password_reset_tokens')
                ->where('token', $code)
                ->where('created_at', '>=', now()->subMinutes($expires))
                ->exists());

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => $code, 'created_at' => now()]
            );

            Mail::to($email)->send(new ResetPasswordCodeMail($code, $expires));

            return $this->successResponse(null, 'Password reset code sent successfully.');
        } catch (Throwable $e) {
            Log::error('Forgot password failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Reset the password using the 6-digit code sent by email.
     *
     * The new password is applied to every user row that shares the email
     * (all families), all their tokens are revoked (logged out everywhere),
     * and the used code is deleted.
     *
     * @param  Request  $request  Expects: code, password, password_confirmation.
     * @return JsonResponse 200 on success, 400 for an invalid/expired code,
     *                      404 if no user matches, 422 on validation errors.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => ['required', 'string', 'size:6'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();
            $expires = config('auth.passwords.users.expire', 60);

            $record = DB::table('password_reset_tokens')
                ->where('token', $data['code'])
                ->where('created_at', '>=', now()->subMinutes($expires))
                ->first();

            if (! $record) {
                return $this->errorResponse('Invalid or expired password reset code.', 400);
            }

            $users = User::where('email', $record->email)->get();

            if ($users->isEmpty()) {
                return $this->errorResponse("We can't find a user with that email address.", 404);
            }

            // All-or-nothing: no half-reset state if one save fails.
            DB::transaction(function () use ($users, $data, $record) {
                foreach ($users as $user) {
                    $user->forceFill(['password' => $data['password']])->save();

                    $user->tokens()->delete(); // log out from all devices

                    event(new PasswordReset($user));
                }

                DB::table('password_reset_tokens')->where('email', $record->email)->delete();
            });

            return $this->successResponse(null, 'Password has been reset successfully.');
        } catch (Throwable $e) {
            Log::error('Reset password failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
