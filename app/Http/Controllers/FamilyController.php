<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Throwable;

class FamilyController extends Controller
{
    /**
     * Display a listing of family members in the active household.
     */
    public function members(): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $members = User::where('family_id', $familyId)
                ->orderBy('name')
                ->get()
                ->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'joined_at' => $member->created_at,
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
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $member = User::where('family_id', $familyId)->find($id);

            if (! $member) {
                return $this->errorResponse('Family member not found.', 404);
            }

            $data = [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'joined_at' => $member->created_at,
            ];

            return $this->successResponse($data, 'Family member profile retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch family member profile failed');
        }
    }
}
