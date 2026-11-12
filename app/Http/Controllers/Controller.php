<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Class Controller
 *
 * Abstract base controller providing standard API response envelope methods.
 */
abstract class Controller
{
    /**
     * Send a standardized success API response envelope.
     *
     * @param  mixed  $data  Response payload data.
     * @param  string  $message  User-friendly success message.
     * @param  int  $statusCode  HTTP status code (default: 200).
     * @return JsonResponse Structured JSON response.
     */
    public function successResponse(
        mixed $data,
        string $message = 'Success',
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'data' => $data,
            'status' => true,
            'message' => $message,
        ], $statusCode);
    }

    /**
     * Send a standardized error API response envelope.
     *
     * @param  string  $message  User-friendly error message.
     * @param  int  $statusCode  HTTP status code (default: 500).
     * @param  mixed  $errors  Optional validation errors or details.
     * @return JsonResponse Structured JSON response.
     */
    public function errorResponse(
        string $message = 'Internal Server Error',
        int $statusCode = 500,
        mixed $errors = null
    ): JsonResponse {
        $response = [
            'status' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }
}
