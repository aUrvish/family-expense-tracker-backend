<?php

namespace App\Http\Controllers;

use App\Models\FamilyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FamilyInvitationController extends Controller
{
    /**
     * Create a new secure, high-entropy invitation for the family.
     * Only family owners can manage invitations.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            if (! $request->user()->isOwner()) {
                return $this->errorResponse('Only family owners can manage invitations.', 403);
            }

            $code = 'inv_'.bin2hex(random_bytes(16));

            $invitation = FamilyInvitation::create([
                'family_id' => $familyId,
                'invited_by_user_id' => $request->user()->id,
                'code' => $code,
            ]);

            return $this->successResponse($invitation, 'Invitation created successfully.', 201);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Create invitation failed');
        }
    }
}
