<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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

    /**
     * Handle an exception caught in a controller.
     *
     * Expected client/HTTP exceptions (validation, authentication, authorization, not found, HTTP)
     * are converted to their proper HTTP status and standard response structure.
     * Unexpected server errors are logged with context and returned as a safe generic 500 response.
     *
     * @param  Throwable  $e  Caught exception.
     * @param  string  $logMessage  Context message for server-side logging.
     * @return JsonResponse Standardized error response.
     */
    protected function handleException(Throwable $e, string $logMessage = 'Operation failed'): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->errorResponse('Validation failed.', 422, $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return $this->errorResponse($e->getMessage() ?: 'This action is unauthorized.', 403);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return $this->errorResponse($e->getMessage() ?: 'Resource not found.', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return $this->errorResponse(
                $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'HTTP Error'),
                $status
            );
        }

        Log::error($logMessage.': '.$e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return $this->errorResponse();
    }
}
