<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_unauthorized_action_returns_standard_403_error_envelope(): void
    {
        $family = Family::create(['name' => 'Patel Family']);
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'family_id' => $family->id,
        ]);
        $family->update(['owner_id' => $owner->id]);

        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'password123',
            'family_id' => $family->id,
        ]);

        $token = $member->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Family-Id', $family->id)
            ->postJson('/api/family/invitations');

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Only family owners can manage invitations.',
            ]);
    }

    public function test_not_found_resource_returns_standard_404_error_envelope(): void
    {
        $family = Family::create(['name' => 'Patel Family']);
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'family_id' => $family->id,
        ]);
        $family->update(['owner_id' => $user->id]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Family-Id', $family->id)
            ->getJson('/api/transaction/999999');

        $response->assertStatus(404)
            ->assertJson([
                'status' => false,
                'message' => 'Transaction not found.',
            ]);
    }

    public function test_validation_failure_returns_standard_422_error_envelope(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'status',
                'message',
                'errors' => [
                    'email',
                    'password',
                ],
            ])
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Validation failed.');
    }

    public function test_controller_unexpected_exception_returns_safe_500_without_leaking_internals(): void
    {
        Log::spy();

        Route::get('/api/test-controller-failure', function () {
            $controller = new class extends Controller
            {
                public function fail(): JsonResponse
                {
                    try {
                        throw new \RuntimeException('Database syntax error: SELECT * FROM secrets WHERE key = super_secret_val');
                    } catch (\Throwable $e) {
                        return $this->handleException($e, 'Controller action failed');
                    }
                }
            };

            return $controller->fail();
        });

        $response = $this->getJson('/api/test-controller-failure');

        $response->assertStatus(500)
            ->assertJson([
                'status' => false,
                'message' => 'Internal Server Error',
            ]);

        $response->assertDontSee('secrets');
        $response->assertDontSee('super_secret_val');
        $response->assertDontSee('Database syntax error');
        $response->assertDontSee('RuntimeException');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Controller action failed')
                    && isset($context['exception'])
                    && $context['exception'] === \RuntimeException::class;
            });
    }

    public function test_controller_handle_exception_preserves_expected_statuses_and_safely_handles_unexpected_errors(): void
    {
        $testController = new class extends Controller
        {
            public function trigger(string $type): JsonResponse
            {
                try {
                    match ($type) {
                        'validation' => throw ValidationException::withMessages(['code' => ['Invalid code provided']]),
                        'authentication' => throw new AuthenticationException('Unauthenticated custom'),
                        'authorization' => throw new AuthorizationException('Forbidden action custom'),
                        'access_denied' => throw new AccessDeniedHttpException('Access denied custom'),
                        'model_not_found' => throw new ModelNotFoundException('Entity not found'),
                        'not_found_http' => throw new NotFoundHttpException('Endpoint missing'),
                        'http_custom' => throw new HttpException(429, 'Rate limit exceeded'),
                        'unexpected' => throw new \RuntimeException('SQL query crashed: table dropped'),
                    };
                } catch (\Throwable $e) {
                    return $this->handleException($e, 'Trigger action failed');
                }
            }
        };

        // 1. ValidationException -> 422
        $valResponse = $testController->trigger('validation');
        $this->assertSame(422, $valResponse->getStatusCode());
        $valData = $valResponse->getData(true);
        $this->assertFalse($valData['status']);
        $this->assertSame('Validation failed.', $valData['message']);
        $this->assertArrayHasKey('code', $valData['errors']);

        // 2. AuthenticationException -> 401
        $authResponse = $testController->trigger('authentication');
        $this->assertSame(401, $authResponse->getStatusCode());
        $authData = $authResponse->getData(true);
        $this->assertFalse($authData['status']);
        $this->assertSame('Unauthenticated.', $authData['message']);

        // 3. AuthorizationException -> 403
        $authzResponse = $testController->trigger('authorization');
        $this->assertSame(403, $authzResponse->getStatusCode());
        $authzData = $authzResponse->getData(true);
        $this->assertFalse($authzData['status']);
        $this->assertSame('Forbidden action custom', $authzData['message']);

        // 4. AccessDeniedHttpException -> 403
        $deniedResponse = $testController->trigger('access_denied');
        $this->assertSame(403, $deniedResponse->getStatusCode());
        $deniedData = $deniedResponse->getData(true);
        $this->assertFalse($deniedData['status']);
        $this->assertSame('Access denied custom', $deniedData['message']);

        // 5. ModelNotFoundException -> 404
        $modelResponse = $testController->trigger('model_not_found');
        $this->assertSame(404, $modelResponse->getStatusCode());
        $modelData = $modelResponse->getData(true);
        $this->assertFalse($modelData['status']);
        $this->assertSame('Entity not found', $modelData['message']);

        // 6. NotFoundHttpException -> 404
        $notFoundResponse = $testController->trigger('not_found_http');
        $this->assertSame(404, $notFoundResponse->getStatusCode());
        $notFoundData = $notFoundResponse->getData(true);
        $this->assertFalse($notFoundData['status']);
        $this->assertSame('Endpoint missing', $notFoundData['message']);

        // 7. HttpExceptionInterface (429) -> 429
        $httpCustomResponse = $testController->trigger('http_custom');
        $this->assertSame(429, $httpCustomResponse->getStatusCode());
        $httpCustomData = $httpCustomResponse->getData(true);
        $this->assertFalse($httpCustomData['status']);
        $this->assertSame('Rate limit exceeded', $httpCustomData['message']);

        // 8. Unexpected Exception -> 500 safe response
        Log::spy();
        $unexpectedResponse = $testController->trigger('unexpected');
        $this->assertSame(500, $unexpectedResponse->getStatusCode());
        $unexpectedData = $unexpectedResponse->getData(true);
        $this->assertFalse($unexpectedData['status']);
        $this->assertSame('Internal Server Error', $unexpectedData['message']);
        $this->assertArrayNotHasKey('errors', $unexpectedData);

        // Verify unexpected exception was logged
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Trigger action failed')
                    && isset($context['exception'])
                    && $context['exception'] === \RuntimeException::class;
            });
    }
}
