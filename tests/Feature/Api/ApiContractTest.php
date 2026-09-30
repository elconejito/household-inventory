<?php

namespace Tests\Feature\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    public function test_returns_the_health_resource_in_the_data_envelope(): void
    {
        $this->get('/api/health')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'type' => 'health',
                    'id' => 'api',
                    'status' => 'ok',
                ],
            ]);
    }

    public function test_returns_a_404_error_in_the_api_errors_envelope(): void
    {
        $this->get('/api/missing-resource')
            ->assertNotFound()
            ->assertExactJson([
                'errors' => [[
                    'status' => '404',
                    'code' => 'not_found',
                    'title' => 'Not Found',
                    'detail' => 'The requested resource was not found.',
                ]],
            ]);
    }

    public function test_returns_a_404_error_when_a_model_cannot_be_found(): void
    {
        Route::get('/api/contract-model-not-found', function (): never {
            throw (new ModelNotFoundException)->setModel('App\\Models\\Item', ['42']);
        });

        $this->get('/api/contract-model-not-found')
            ->assertNotFound()
            ->assertExactJson([
                'errors' => [[
                    'status' => '404',
                    'code' => 'not_found',
                    'title' => 'Not Found',
                    'detail' => 'The requested resource was not found.',
                ]],
            ]);
    }

    public function test_returns_a_401_error_for_an_unauthenticated_api_request(): void
    {
        Route::get('/api/contract-unauthenticated', fn (): JsonResponse => response()->json(['authenticated' => true]))
            ->middleware('auth:sanctum');

        $this->get('/api/contract-unauthenticated')
            ->assertUnauthorized()
            ->assertExactJson([
                'errors' => [[
                    'status' => '401',
                    'code' => 'unauthenticated',
                    'title' => 'Unauthenticated',
                    'detail' => 'Authentication is required.',
                ]],
            ]);
    }

    public function test_returns_a_403_error_for_an_unauthorized_api_request(): void
    {
        Route::get('/api/contract-forbidden', function (): never {
            throw new AuthorizationException;
        });

        $this->get('/api/contract-forbidden')
            ->assertForbidden()
            ->assertExactJson([
                'errors' => [[
                    'status' => '403',
                    'code' => 'forbidden',
                    'title' => 'Forbidden',
                    'detail' => 'You are not allowed to perform this action.',
                ]],
            ]);
    }

    public function test_returns_a_400_error_for_an_invalid_query_parameter(): void
    {
        Route::get('/api/contract-invalid-query', function (): never {
            throw InvalidIncludeQuery::includesNotAllowed(collect(['secret']), collect());
        });

        $this->get('/api/contract-invalid-query')
            ->assertBadRequest()
            ->assertExactJson([
                'errors' => [[
                    'status' => '400',
                    'code' => 'invalid_query_parameter',
                    'title' => 'Bad Request',
                    'detail' => 'One or more query parameters are invalid.',
                ]],
            ]);
    }

    public function test_returns_a_429_error_for_a_rate_limited_api_request(): void
    {
        Route::get('/api/contract-rate-limited', function (): never {
            abort(429);
        });

        $this->get('/api/contract-rate-limited')
            ->assertTooManyRequests()
            ->assertExactJson([
                'errors' => [[
                    'status' => '429',
                    'code' => 'rate_limited',
                    'title' => 'Too Many Requests',
                    'detail' => 'Too many requests were received.',
                ]],
            ]);
    }

    public function test_returns_a_422_validation_error_in_the_api_errors_envelope_without_accept_header(): void
    {
        Route::post('/api/contract-validation', function (Request $request): JsonResponse {
            $validated = $request->validate(
                ['data.name' => ['required']],
                [],
                ['data.name' => 'name'],
            );

            return response()->json($validated);
        });

        $this->post('/api/contract-validation', ['data' => []])
            ->assertUnprocessable()
            ->assertExactJson([
                'errors' => [[
                    'status' => '422',
                    'code' => 'validation_failed',
                    'title' => 'Validation failed',
                    'detail' => 'The name field is required.',
                    'source' => ['pointer' => '/data/name'],
                ]],
            ]);
    }
}
