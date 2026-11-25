<?php

namespace App\Shared\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrors
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return null;
        }

        $status = match (true) {
            $exception instanceof ApiProblem => $exception->status,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $code = $exception instanceof ApiProblem ? $exception->errorCode : match ($status) {
            400 => 'MALFORMED_JSON',
            401 => 'AUTH_REQUIRED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'SERVER_ERROR',
        };

        $error = ['code' => $code, 'message' => self::message($code)];
        if ($exception instanceof ValidationException) {
            $error['fields'] = $exception->errors();
        }

        return response()->json(
            ['error' => $error, 'requestId' => self::requestId($request)],
            $status,
            $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
        );
    }

    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('emenu.request_id');
        if (is_string($id)) {
            return $id;
        }
        $id = (string) Str::uuid();
        $request->attributes->set('emenu.request_id', $id);

        return $id;
    }

    public static function headers(Response $response, Request $request): Response
    {
        if ($request->is('api/v1', 'api/v1/*')) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('X-Request-ID', self::requestId($request));
        }

        return $response;
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'AUTH_REQUIRED' => 'Sign in to continue.',
            'INVALID_CREDENTIALS' => 'The email or password is incorrect.',
            'EMAIL_UNVERIFIED' => 'Verify your email to continue.',
            'FORBIDDEN' => 'You do not have permission to perform this action.',
            'NOT_FOUND' => 'The requested resource was not found.',
            'CURRENT_PASSWORD_INVALID' => 'The current password is incorrect.',
            'MALFORMED_JSON' => 'Send a valid JSON object.',
            'UNSUPPORTED_MEDIA_TYPE' => 'Send the request as application/json.',
            'INVALID_INVITATION' => 'This invitation is invalid or no longer available.',
            'RESET_INVALID' => 'This password reset link is invalid or expired.',
            'VERIFICATION_INVALID' => 'This verification link is invalid or expired.',
            'RESOURCE_IN_USE' => 'Remove the current references before deleting this resource.',
            'CAPACITY_REACHED' => 'This restaurant has reached the limit for this operation.',
            'DRAFT_CHANGED' => 'The draft changed. Refresh it before publishing.',
            'MENU_NOT_PUBLISHABLE' => 'The menu needs a visible product and ready images before publication.',
            'PAYLOAD_TOO_LARGE' => 'The request exceeds the allowed size.',
            'SLUG_TAKEN' => 'This restaurant slug is already in use.',
            'INVITATION_CONFLICT' => 'An active membership or invitation conflicts with this request.',
            'OWNERSHIP_CONFLICT' => 'This change is incompatible with the current ownership.',
            'RATE_LIMITED' => 'Too many requests. Try again later.',
            'VALIDATION_FAILED' => 'Check the submitted fields.',
            'SERVICE_UNAVAILABLE' => 'The service is temporarily unavailable.',
            default => 'The request could not be completed.',
        };
    }
}
