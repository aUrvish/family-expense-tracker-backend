<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CategoryController extends Controller
{
    /**
     * Display a listing of central categories.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $categories = Category::orderBy('name')->get();

            return $this->successResponse($categories, 'Categories fetched successfully.');
        } catch (Throwable $e) {
            return $this->errorResponse();
        }
    }
}
