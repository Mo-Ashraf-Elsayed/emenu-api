<?php

namespace App\Providers;

use App\Modules\Identity\DashboardToken;
use App\Modules\Identity\User;
use App\Modules\Tenancy\TenantPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::authenticateAccessTokensUsing(DashboardToken::valid(...));

        Gate::define('menu-edit', [TenantPolicy::class, 'editMenu']);
        Gate::define('tenant-owner', [TenantPolicy::class, 'owner']);
        Gate::define('tenant-invite', [TenantPolicy::class, 'invite']);
        Gate::define('platform-admin', fn (User $actor): bool => $actor->is_platform_admin);

        RateLimiter::for('emenu-api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown'));

        RateLimiter::for('emenu-media-upload', fn (Request $request): array => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(12)->by('actor:'.$request->user()?->getAuthIdentifier()),
        ]);

        RateLimiter::for('emenu-guest', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? hash('sha256', mb_strtolower(trim(substr($email, 0, 1024)))) : 'invalid';
            $scope = (string) $request->route()?->getName();

            return [
                Limit::perMinute(24)->by($scope.':ip:'.$request->ip()),
                Limit::perMinute(6)->by($scope.':email:'.$identity),
            ];
        });

        RateLimiter::for('emenu-sensitive', fn (Request $request): array => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(8)->by('account:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);
    }
}
