<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class TransactionController extends Controller
{
    /**
     * Create or update a transaction (expense or income).
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => ['nullable', 'integer', 'exists:transactions,id'],
                'type' => ['required', 'string', Rule::in(['expense', 'income'])],
                'amount' => ['required', 'numeric', 'gt:0'],
                'category_id' => ['required', 'integer', 'exists:categories,id'],
                'date' => ['nullable', 'date'],
                'note' => ['nullable', 'string', 'max:255'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'visibility' => ['nullable', 'string', Rule::in(['shared', 'personal'])],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $user = $request->user();

            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            // Update existing transaction if 'id' is provided
            if (! empty($validated['id'])) {
                $transaction = Transaction::where('family_id', $familyId)->find($validated['id']);

                if (! $transaction) {
                    return $this->errorResponse('Transaction not found.', 404);
                }

                if ($transaction->visibility === 'personal' && $transaction->user_id !== $user->id) {
                    return $this->errorResponse('Transaction not found.', 404);
                }

                $transaction->update([
                    'category_id' => $validated['category_id'],
                    'type' => $validated['type'],
                    'amount' => $validated['amount'],
                    'date' => $validated['date'] ?? $transaction->date,
                    'note' => $validated['note'] ?? null,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'visibility' => $validated['visibility'] ?? $transaction->visibility,
                ]);

                return $this->successResponse(
                    $transaction->fresh(),
                    ucfirst($validated['type']).' updated successfully.',
                    200
                );
            }

            // Otherwise, create new transaction
            $transaction = Transaction::create([
                'family_id' => $familyId,
                'user_id' => $user->id,
                'category_id' => $validated['category_id'],
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'date' => $validated['date'] ?? now()->toDateString(),
                'note' => $validated['note'] ?? null,
                'payment_method' => $validated['payment_method'] ?? null,
                'visibility' => $validated['visibility'] ?? 'shared',
            ]);

            return $this->successResponse(
                $transaction,
                ucfirst($validated['type']).' added successfully.',
                201
            );
        } catch (Throwable $e) {
            Log::error('Save transaction failed: '.$e->getMessage());

            return $this->errorResponse();
        }
    }
}
