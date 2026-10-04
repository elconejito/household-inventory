<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidDirection;
use Spatie\QueryBuilder\Exceptions\InvalidFilterValue;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return $this->renderValidationErrors($exception);
        }

        if ($exception instanceof AuthenticationException) {
            return $this->error(401, 'unauthenticated', 'Unauthenticated', 'Authentication is required.');
        }

        if ($exception instanceof AuthorizationException) {
            return $this->error(403, 'forbidden', 'Forbidden', 'You are not allowed to perform this action.');
        }

        if ($exception instanceof ModelNotFoundException) {
            return $this->notFound();
        }

        if ($exception instanceof ActiveInventoryAlertExists) {
            return $this->error(409, 'active_inventory_alert_exists', 'Conflict', $exception->getMessage());
        }

        if ($exception instanceof InventoryArchiveBlocked) {
            return $this->error(409, 'inventory_archive_blocked', 'Conflict', $exception->getMessage());
        }

        if ($exception instanceof HouseholdAdministrationConflict) {
            return $this->error(409, $exception->errorCode, 'Conflict', $exception->detail);
        }

        if ($exception instanceof PermanentDeletionBlocked) {
            return response()->json(['errors' => [[
                'status' => '409',
                'code' => 'permanent_deletion_blocked',
                'title' => 'Conflict',
                'detail' => $exception->getMessage(),
                'meta' => ['blockers' => $exception->blockers],
            ]]], 409);
        }

        if ($exception instanceof InvalidQuery || $exception instanceof InvalidDirection || $exception instanceof InvalidFilterValue) {
            return $this->error(
                400,
                'invalid_query_parameter',
                'Bad Request',
                'One or more query parameters are invalid.',
            );
        }

        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        $response = match ($status) {
            400 => $this->error(400, 'bad_request', 'Bad Request', 'The request could not be understood.'),
            401 => $this->error(401, 'unauthenticated', 'Unauthenticated', 'Authentication is required.'),
            403 => $this->error(403, 'forbidden', 'Forbidden', 'You are not allowed to perform this action.'),
            404 => $this->notFound(),
            405 => $this->error(405, 'method_not_allowed', 'Method Not Allowed', 'The request method is not allowed.'),
            409 => $this->error(409, 'conflict', 'Conflict', 'The request conflicts with the current state.'),
            413 => $this->error(413, 'payload_too_large', 'Payload Too Large', 'The upload exceeds the server request-size limit. Choose a smaller file and try again.'),
            419 => $this->error(419, 'session_expired', 'Page Expired', 'Your session has expired. Refresh the page and try again.'),
            429 => $this->error(429, 'rate_limited', 'Too Many Requests', 'Too many requests were received.'),
            503 => $this->error(503, 'service_unavailable', 'Service Unavailable', 'The service is temporarily unavailable. Please try again later.'),
            default => $this->error(500, 'server_error', 'Server Error', 'An unexpected error occurred.'),
        };

        if ($exception instanceof HttpExceptionInterface) {
            $response->withHeaders($exception->getHeaders());
        }

        return $response;
    }

    private function renderValidationErrors(ValidationException $exception): JsonResponse
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $path = str_starts_with($field, 'data.') ? $field : 'data.'.$field;
            $pointer = implode('/', array_map(
                fn (string $segment): string => str_replace(['~', '/'], ['~0', '~1'], $segment),
                explode('.', $path),
            ));

            foreach ($messages as $message) {
                $errors[] = [
                    'status' => '422',
                    'code' => 'validation_failed',
                    'title' => 'Validation failed',
                    'detail' => $message,
                    'source' => ['pointer' => '/'.$pointer],
                ];
            }
        }

        return response()->json(['errors' => $errors], 422);
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'Not Found', 'The requested resource was not found.');
    }

    /**
     * @param  array<string, string>|null  $source
     */
    private function error(int $status, string $code, string $title, string $detail, ?array $source = null): JsonResponse
    {
        $error = [
            'status' => (string) $status,
            'code' => $code,
            'title' => $title,
            'detail' => $detail,
        ];

        if ($source !== null) {
            $error['source'] = $source;
        }

        return response()->json(['errors' => [$error]], $status);
    }
}
