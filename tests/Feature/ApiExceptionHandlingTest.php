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

    public function test_controller_handle_exception_sanitizes_messages_and_preserves_status_and_headers(): void
    {
        $testController = new class extends Controller
        {
            public function trigger(string $type): JsonResponse
            {
                try {
                    match ($type) {
                        'validation' => throw ValidationException::withMessages(['code' => ['Invalid code provided']]),
                        'authentication' => throw new AuthenticationException('Internal token decoding failed at auth driver'),
                        'authorization' => throw new AuthorizationException('User [ID: 42] lacks permission for table [users]'),
                        'access_denied' => throw new AccessDeniedHttpException('Policy check failed for App\Policies\SecretPolicy', null, 0, ['X-Custom-Auth' => 'denied-flag']),
                        'model_not_found' => (function () {
                            $e = new ModelNotFoundException('No query results for model [App\Models\InternalFinancialRecord] 98765');
                            $e->setModel('App\Models\InternalFinancialRecord', [98765]);

                            throw $e;
                        })(),
                        'not_found_http' => throw new NotFoundHttpException('Internal controller route /admin/internal-path not found', null, 0, ['X-Resource-Scope' => 'tenant-private']),
                        'http_rate_limit' => throw new HttpException(429, 'Rate limit breached on redis node 10.0.0.12', null, ['Retry-After' => '60', 'X-RateLimit-Reset' => '1700000000']),
                        'unexpected_sql' => throw new \RuntimeException('SQLSTATE[HY000]: General error 1364 Field user_id does not have default value'),
                    };
                } catch (\Throwable $e) {
                    return $this->handleException($e, 'Sanitization trigger failed');
                }
            }
        };

        // 1. ValidationException -> 422 with validation errors
        $valResponse = $testController->trigger('validation');
        $this->assertSame(422, $valResponse->getStatusCode());
        $valData = $valResponse->getData(true);
        $this->assertFalse($valData['status']);
        $this->assertSame('Validation failed.', $valData['message']);
        $this->assertArrayHasKey('code', $valData['errors']);

        // 2. AuthenticationException -> 401 with sanitized message (no internal token/driver details)
        $authResponse = $testController->trigger('authentication');
        $this->assertSame(401, $authResponse->getStatusCode());
        $authData = $authResponse->getData(true);
        $this->assertFalse($authData['status']);
        $this->assertSame('Unauthenticated.', $authData['message']);
        $this->assertStringNotContainsString('decoding failed', json_encode($authData));

        // 3. AuthorizationException -> 403 with sanitized message (no table or user IDs)
        $authzResponse = $testController->trigger('authorization');
        $this->assertSame(403, $authzResponse->getStatusCode());
        $authzData = $authzResponse->getData(true);
        $this->assertFalse($authzData['status']);
        $this->assertSame('This action is unauthorized.', $authzData['message']);
        $this->assertStringNotContainsString('42', json_encode($authzData));
        $this->assertStringNotContainsString('table', json_encode($authzData));

        // 4. AccessDeniedHttpException -> 403 with sanitized message and preserved headers
        $deniedResponse = $testController->trigger('access_denied');
        $this->assertSame(403, $deniedResponse->getStatusCode());
        $deniedData = $deniedResponse->getData(true);
        $this->assertFalse($deniedData['status']);
        $this->assertSame('This action is unauthorized.', $deniedData['message']);
        $this->assertStringNotContainsString('SecretPolicy', json_encode($deniedData));
        $this->assertSame('denied-flag', $deniedResponse->headers->get('X-Custom-Auth'));

        // 5. ModelNotFoundException -> 404 with sanitized message (no model names or database IDs)
        $modelResponse = $testController->trigger('model_not_found');
        $this->assertSame(404, $modelResponse->getStatusCode());
        $modelData = $modelResponse->getData(true);
        $this->assertFalse($modelData['status']);
        $this->assertSame('Resource not found.', $modelData['message']);
        $this->assertStringNotContainsString('InternalFinancialRecord', json_encode($modelData));
        $this->assertStringNotContainsString('98765', json_encode($modelData));

        // 6. NotFoundHttpException -> 404 with sanitized message and preserved headers
        $notFoundResponse = $testController->trigger('not_found_http');
        $this->assertSame(404, $notFoundResponse->getStatusCode());
        $notFoundData = $notFoundResponse->getData(true);
        $this->assertFalse($notFoundData['status']);
        $this->assertSame('Resource not found.', $notFoundData['message']);
        $this->assertStringNotContainsString('/admin/internal-path', json_encode($notFoundData));
        $this->assertSame('tenant-private', $notFoundResponse->headers->get('X-Resource-Scope'));

        // 7. HttpExceptionInterface (429) -> 429 with standard status text and preserved headers (Retry-After)
        $rateLimitResponse = $testController->trigger('http_rate_limit');
        $this->assertSame(429, $rateLimitResponse->getStatusCode());
        $rateLimitData = $rateLimitResponse->getData(true);
        $this->assertFalse($rateLimitData['status']);
        $this->assertSame('Too Many Requests', $rateLimitData['message']);
        $this->assertStringNotContainsString('10.0.0.12', json_encode($rateLimitData));
        $this->assertSame('60', $rateLimitResponse->headers->get('Retry-After'));
        $this->assertSame('1700000000', $rateLimitResponse->headers->get('X-RateLimit-Reset'));

        // 8. Unexpected Exception -> 500 safe response, no SQL leak, server log context verified
        Log::spy();
        $unexpectedResponse = $testController->trigger('unexpected_sql');
        $this->assertSame(500, $unexpectedResponse->getStatusCode());
        $unexpectedData = $unexpectedResponse->getData(true);
        $this->assertFalse($unexpectedData['status']);
        $this->assertSame('Internal Server Error', $unexpectedData['message']);
        $this->assertArrayNotHasKey('errors', $unexpectedData);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($unexpectedData));
        $this->assertStringNotContainsString('user_id', json_encode($unexpectedData));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Sanitization trigger failed')
                    && str_contains($message, 'SQLSTATE')
                    && isset($context['exception'])
                    && $context['exception'] === \RuntimeException::class;
            });
    }
}
