<?php

namespace App\Modules\Tenancy;

use App\Modules\Identity\DashboardToken;
use App\Modules\Restaurants\Restaurant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(Tenant::class, self::lookup($request, false));

        return $next($request);
    }

    public static function current(Request $request): Tenant
    {
        $tenant = $request->attributes->get(Tenant::class);
        assert($tenant instanceof Tenant);

        return $tenant;
    }

    /**
     * @template TResult
     *
     * @param  Closure(Tenant): TResult  $action
     * @return TResult
     */
    public static function write(Request $request, Closure $action): mixed
    {
        return DB::transaction(fn () => $action(self::lookup($request, true)));
    }

    private static function lookup(Request $request, bool $lock): Tenant
    {
        $actor = DashboardToken::user($request);
        $key = $request->route('restaurant');
        abort_unless(is_string($key), 404);
        $query = Restaurant::query()->where('status', 'active')->whereKey($key);
        $restaurant = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $membership = $restaurant->memberships()->where('user_id', $actor->id)
            ->where('status', 'active')->firstOrFail();

        return new Tenant($restaurant, $membership, $actor);
    }
}
