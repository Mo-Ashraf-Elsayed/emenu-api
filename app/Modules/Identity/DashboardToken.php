<?php

namespace App\Modules\Identity;

use App\Shared\Http\ApiProblem;
use DateTimeInterface;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class DashboardToken
{
    private const ABILITY = 'dashboard';

    public static function issue(User $user, DateTimeInterface $expiresAt): NewAccessToken
    {
        $token = $user->createToken(self::ABILITY, [self::ABILITY], $expiresAt);
        $token->accessToken->setRelation('tokenable', $user);

        return $token;
    }

    public static function valid(PersonalAccessToken $token, bool $sanctumValid): bool
    {
        return $sanctumValid && $token->can(self::ABILITY) && $token->expires_at?->isFuture()
            && ($token->last_used_at ?? $token->created_at)?->gt(now()->subHours(2));
    }

    public static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiProblem(401, 'AUTH_REQUIRED');
        }

        return $user;
    }
}
