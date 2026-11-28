<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class FamilyController extends Controller
{
    /**
     * List all families the authenticated user belongs to along with their role.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            return $this->successResponse([
                'families' => $request->user()->families,
            ], 'Families retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch families failed');
        }
    }

    /**
     * Create a new family (with `name`) or join an existing family (with `invite_code`).
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required_without:invite_code', 'nullable', 'string', 'max:255', 'prohibits:invite_code'],
                'invite_code' => ['required_without:name', 'nullable', 'string', 'max:64', 'prohibits:name'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $data = $validator->validated();
            $user = $request->user();

            if (! empty($data['invite_code'])) {
                $family = DB::transaction(function () use ($data, $user) {
                    $invitation = FamilyInvitation::where('code', trim($data['invite_code']))
                        ->lockForUpdate()
                        ->first();

                    if (! $invitation || ! $invitation->isValid()) {
                        throw ValidationException::withMessages([
                            'invite_code' => ['The provided invitation code is invalid or expired.'],
                        ]);
                    }

                    $family = Family::findOrFail($invitation->family_id);

                    if ($family->users()->where('users.id', $user->id)->exists()) {
                        throw ValidationException::withMessages([
                            'invite_code' => ['You are already a member of this family.'],
                        ]);
                    }

                    $family->users()->attach($user->id, ['role' => 'member']);

                    return $family;
                });

                return $this->successResponse([
                    'family' => [
                        'id' => $family->id,
                        'name' => $family->name,
                        'currency' => $family->effectiveCurrency(),
                        'role' => 'member',
                    ],
                ], 'Joined family successfully.');
            }

            $family = DB::transaction(function () use ($data, $user) {
                $family = Family::create([
                    'name' => trim($data['name']),
                ]);

                $family->users()->attach($user->id, ['role' => 'owner']);

                return $family;
            });

            return $this->successResponse([
                'family' => [
                    'id' => $family->id,
                    'name' => $family->name,
                    'currency' => $family->effectiveCurrency(),
                    'role' => 'owner',
                ],
            ], 'Family created successfully.', 201);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Save family membership failed');
        }
    }

    /**
     * Display a listing of family members in the active household.
     */
    public function members(): JsonResponse
    {
        try {
            $family = tenant();

            if (! $family) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $members = $family->users()
                ->orderBy('name')
                ->get()
                ->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'role' => $member->pivot?->role ?? 'member',
                        'joined_at' => $member->pivot?->created_at?->timestamp ?? $member->created_at,
                    ];
                });

            return $this->successResponse($members, 'Family members retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch family members failed');
        }
    }

    /**
     * Display the profile of a specific family member.
     */
    public function show(int|string $id): JsonResponse
    {
        try {
            $family = tenant();

            if (! $family) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $member = $family->users()->where('users.id', $id)->first();

            if (! $member) {
                return $this->errorResponse('Family member not found.', 404);
            }

            $data = [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot?->role ?? 'member',
                'joined_at' => $member->pivot?->created_at?->timestamp ?? $member->created_at,
            ];

            return $this->successResponse($data, 'Family member profile retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch family member profile failed');
        }
    }
}
