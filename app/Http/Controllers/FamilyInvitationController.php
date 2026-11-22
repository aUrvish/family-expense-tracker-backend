<?php

namespace App\Http\Controllers;

use App\Models\FamilyInvitation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class FamilyInvitationController extends Controller
{
    /**
     * Display a listing of invitations for the active family.
     * Only family owners can manage invitations.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            if (! $request->user()->isOwner()) {
                return $this->errorResponse('Only family owners can manage invitations.', 403);
            }

            $invitations = FamilyInvitation::with('inviter')
                ->where('family_id', $familyId)
                ->orderByDesc('created_at')
                ->get();

            return $this->successResponse($invitations, 'Family invitations retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Fetch invitations failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

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

            $validator = Validator::make($request->all(), [
                'email' => ['nullable', 'email', 'max:255'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $code = 'inv_'.bin2hex(random_bytes(16));

            $invitation = FamilyInvitation::create([
                'family_id' => $familyId,
                'invited_by_user_id' => $request->user()->id,
                'email' => $validated['email'] ?? null,
                'code' => $code,
            ]);

            return $this->successResponse($invitation, 'Invitation created successfully.', 201);
        } catch (Throwable $e) {
            Log::error('Create invitation failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Delete an active invitation.
     * Only family owners can manage invitations.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            if (! $request->user()->isOwner()) {
                return $this->errorResponse('Only family owners can manage invitations.', 403);
            }

            $invitation = FamilyInvitation::where('family_id', $familyId)->find($id);

            if (! $invitation) {
                return $this->errorResponse('Invitation not found.', 404);
            }

            $invitation->delete();

            return $this->successResponse(null, 'Invitation deleted successfully.');
        } catch (Throwable $e) {
            Log::error('Delete invitation failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }

    /**
     * Regenerate an invitation code and refresh its expiration.
     * Only family owners can manage invitations.
     */
    public function regenerate(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            if (! $request->user()->isOwner()) {
                return $this->errorResponse('Only family owners can manage invitations.', 403);
            }

            $invitation = FamilyInvitation::where('family_id', $familyId)->find($id);

            if (! $invitation) {
                return $this->errorResponse('Invitation not found.', 404);
            }

            $newCode = 'inv_'.bin2hex(random_bytes(16));
            $invitation->update([
                'code' => $newCode,
                'created_at' => Carbon::now(),
            ]);

            return $this->successResponse($invitation, 'Invitation regenerated successfully.');
        } catch (Throwable $e) {
            Log::error('Regenerate invitation failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
