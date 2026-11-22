<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CategoryController extends Controller
{
    /**
     * Display a listing of system categories and family custom categories.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            $query = Category::query();

            if ($familyId) {
                $query->where(function ($q) use ($familyId) {
                    $q->whereNull('family_id')->orWhere('family_id', $familyId);
                });
            } else {
                $query->whereNull('family_id');
            }

            $categories = $query->orderBy('name')->get()->map(function (Category $category) {
                $data = $category->toArray();
                $data['is_system'] = $category->isSystem();

                return $data;
            });

            return $this->successResponse($categories, 'Categories fetched successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Fetch categories failed');
        }
    }

    /**
     * Create or update a family custom category.
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $validator = Validator::make($request->all(), [
                'id' => 'nullable|integer|exists:categories,id',
                'name' => 'required|string|max:100',
                'icon' => 'nullable|string|max:50',
                'color' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation error.', 422, $validator->errors());
            }

            $validated = $validator->validated();
            $categoryId = $validated['id'] ?? null;
            $name = trim($validated['name']);

            // Update existing custom category
            if (! empty($categoryId)) {
                $category = Category::find($categoryId);

                if (! $category) {
                    return $this->errorResponse('Category not found.', 404);
                }

                if ($category->isSystem()) {
                    return $this->errorResponse('System categories cannot be modified.', 403);
                }

                if ($category->family_id !== $familyId) {
                    return $this->errorResponse('Category not found.', 404);
                }

                // Check duplicate name within family or system
                $duplicate = Category::where('id', '!=', $category->id)
                    ->where(function ($q) use ($familyId) {
                        $q->whereNull('family_id')->orWhere('family_id', $familyId);
                    })
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->exists();

                if ($duplicate) {
                    return $this->errorResponse('A category with this name already exists.', 422, [
                        'name' => ['A category with this name already exists.'],
                    ]);
                }

                // Generate unique slug for this family
                $baseSlug = Str::slug($name).'-'.substr((string) $familyId, 0, 8);
                $slug = $baseSlug;
                $counter = 1;
                while (Category::withTrashed()->where('family_id', $familyId)->where('id', '!=', $category->id)->where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }

                $category->update([
                    'name' => $name,
                    'slug' => $slug,
                    'icon' => $validated['icon'] ?? $category->icon,
                    'color' => $validated['color'] ?? $category->color,
                ]);

                $data = $category->fresh()->toArray();
                $data['is_system'] = false;

                return $this->successResponse($data, 'Custom category updated successfully.', 200);
            }

            // Create new custom category
            $duplicate = Category::where(function ($q) use ($familyId) {
                $q->whereNull('family_id')->orWhere('family_id', $familyId);
            })
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->exists();

            if ($duplicate) {
                return $this->errorResponse('A category with this name already exists.', 422, [
                    'name' => ['A category with this name already exists.'],
                ]);
            }

            $baseSlug = Str::slug($name).'-'.substr((string) $familyId, 0, 8);
            $slug = $baseSlug;
            $counter = 1;
            while (Category::withTrashed()->where('family_id', $familyId)->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            $category = Category::create([
                'family_id' => $familyId,
                'name' => $name,
                'slug' => $slug,
                'icon' => $validated['icon'] ?? 'tag',
                'color' => $validated['color'] ?? '#6B7280',
            ]);

            $data = $category->toArray();
            $data['is_system'] = false;

            return $this->successResponse($data, 'Custom category created successfully.', 201);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Save category failed');
        }
    }

    /**
     * Display a specific category.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            $category = Category::where(function ($q) use ($familyId) {
                $q->whereNull('family_id');
                if ($familyId) {
                    $q->orWhere('family_id', $familyId);
                }
            })->find($id);

            if (! $category) {
                return $this->errorResponse('Category not found.', 404);
            }

            $data = $category->toArray();
            $data['is_system'] = $category->isSystem();

            return $this->successResponse($data, 'Category retrieved successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Show category failed');
        }
    }

    /**
     * Delete a family custom category.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $category = Category::find($id);

            if (! $category) {
                return $this->errorResponse('Category not found.', 404);
            }

            if ($category->isSystem()) {
                return $this->errorResponse('System categories cannot be deleted.', 403);
            }

            if ($category->family_id !== $familyId) {
                return $this->errorResponse('Category not found.', 404);
            }

            // Check if active transactions or budgets are using this category
            $inUse = Transaction::where('category_id', $category->id)->exists()
                || Budget::where('category_id', $category->id)->exists();

            if ($inUse) {
                return $this->errorResponse(
                    'Cannot delete category because it is currently used by existing transactions or budgets.',
                    422
                );
            }

            $category->delete();

            return $this->successResponse(null, 'Category deleted successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Delete category failed');
        }
    }

    /**
     * Restore a soft-deleted custom category.
     */
    public function restore(Request $request, int|string $id): JsonResponse
    {
        try {
            $familyId = tenant('id');

            if (! $familyId) {
                return $this->errorResponse('Family context is required.', 400);
            }

            $category = Category::onlyTrashed()
                ->where('family_id', $familyId)
                ->find($id);

            if (! $category) {
                return $this->errorResponse('Trashed category not found.', 404);
            }

            // Conflict check: prevent restoring if an active category with the same name or slug already exists
            $conflict = Category::where('family_id', $familyId)
                ->where('id', '!=', $category->id)
                ->where(function ($q) use ($category) {
                    $q->whereRaw('LOWER(name) = ?', [strtolower($category->name)])
                        ->orWhere('slug', $category->slug);
                })
                ->exists();

            if ($conflict) {
                return $this->errorResponse('Cannot restore category: an active category with this name already exists.', 422);
            }

            $category->restore();

            return $this->successResponse(null, 'Category restored successfully.');
        } catch (Throwable $e) {
            return $this->handleException($e, 'Restore category failed');
        }
    }
}
