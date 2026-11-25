<?php

namespace App\Modules\Identity;

use App\Shared\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifiedEmail
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DashboardToken::user($request)->hasVerifiedEmail()) {
            throw new ApiProblem(403, 'EMAIL_UNVERIFIED');
        }

        return $next($request);
    }
}
