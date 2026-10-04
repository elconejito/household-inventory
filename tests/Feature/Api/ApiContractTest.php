<?php

namespace Tests\Feature\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
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
            abort(429, 'Internal limiter details', ['Retry-After' => '60']);
        });

        $this->get('/api/contract-rate-limited')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertExactJson([
                'errors' => [[
                    'status' => '429',
                    'code' => 'rate_limited',
                    'title' => 'Too Many Requests',
                    'detail' => 'Too many requests were received.',
                ]],
            ]);
    }

    public function test_returns_419_for_an_expired_csrf_token_without_exposing_exception_details(): void
    {
        Route::post('/api/contract-csrf-expired', function (): never {
            throw new TokenMismatchException('Internal session details');
        });

        $this->post('/api/contract-csrf-expired')
            ->assertStatus(419)
            ->assertExactJson(['errors' => [[
                'status' => '419',
                'code' => 'session_expired',
                'title' => 'Page Expired',
                'detail' => 'Your session has expired. Refresh the page and try again.',
            ]]]);
    }

    public function test_returns_413_for_a_request_exceeding_the_server_upload_limit(): void
    {
        Route::post('/api/contract-too-large', function (): never {
            throw new PostTooLargeException('Internal upload configuration');
        });

        $this->post('/api/contract-too-large')
            ->assertStatus(413)
            ->assertExactJson(['errors' => [[
                'status' => '413',
                'code' => 'payload_too_large',
                'title' => 'Payload Too Large',
                'detail' => 'The upload exceeds the server request-size limit. Choose a smaller file and try again.',
            ]]]);
    }

    public function test_returns_503_and_retry_header_during_temporary_service_unavailability(): void
    {
        Route::get('/api/contract-unavailable', function (): never {
            abort(503, 'Internal infrastructure details', ['Retry-After' => '120']);
        });

        $this->get('/api/contract-unavailable')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '120')
            ->assertExactJson(['errors' => [[
                'status' => '503',
                'code' => 'service_unavailable',
                'title' => 'Service Unavailable',
                'detail' => 'The service is temporarily unavailable. Please try again later.',
            ]]]);
    }

    public function test_returns_405_with_the_allowed_methods_header(): void
    {
        Route::post('/api/contract-post-only', fn (): JsonResponse => response()->json(['data' => null]));

        $this->get('/api/contract-post-only')
            ->assertMethodNotAllowed()
            ->assertHeader('Allow', 'POST')
            ->assertJsonPath('errors.0.code', 'method_not_allowed');
    }

    public function test_returns_a_generic_500_without_exposing_debug_exception_details(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/contract-unexpected', function (): never {
            throw new \RuntimeException('Sensitive internal failure details');
        });

        $this->get('/api/contract-unexpected')
            ->assertInternalServerError()
            ->assertExactJson(['errors' => [[
                'status' => '500',
                'code' => 'server_error',
                'title' => 'Server Error',
                'detail' => 'An unexpected error occurred.',
            ]]]);
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
