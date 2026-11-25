<?php

namespace App\Modules\PublicMenus;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class LimitPublicMenu
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $maximum): Response
    {
        $key = 'menu-public:'.$request->route()?->getName().':'.hash('sha256', $this->clientAddress($request));
        try {
            $retry = $this->retryAfter(app(RateLimiter::class), $key, (int) $maximum);
        } catch (Throwable) {
            $retry = $this->retryAfter(new RateLimiter(Cache::store('file')), $key, (int) $maximum);
        }
        if ($retry > 0) {
            throw new TooManyRequestsHttpException($retry);
        }

        return $next($request);
    }

    private function clientAddress(Request $request): string
    {
        $peer = $this->packIp($request->server->get('REMOTE_ADDR'));
        if ($peer === null) {
            return '';
        }
        $forwarded = $this->packIp($request->headers->get('X-Emenu-Client-IP'));
        if ($forwarded !== null) {
            foreach (config()->array('public_menu.trusted_proxy_ips') as $proxy) {
                if ($peer === $this->packIp($proxy)) {
                    return $forwarded;
                }
            }
        }

        return $peer;
    }

    private function packIp(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_IP)) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }

        return str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") ? substr($packed, 12) : $packed;
    }

    private function retryAfter(RateLimiter $limiter, string $key, int $maximum): int
    {
        return $limiter->hit($key, 60) > $maximum ? max(1, $limiter->availableIn($key)) : 0;
    }
}
