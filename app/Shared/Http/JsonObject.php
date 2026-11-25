<?php

namespace App\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

final class JsonObject
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->isJson(), 415);
        try {
            $body = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiProblem(400, 'MALFORMED_JSON');
        }
        if (! $body instanceof stdClass) {
            throw new ApiProblem(400, 'MALFORMED_JSON');
        }

        return $next($request);
    }
}
