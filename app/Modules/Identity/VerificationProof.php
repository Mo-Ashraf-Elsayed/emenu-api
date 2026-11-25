<?php

namespace App\Modules\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;

final class VerificationProof
{
    public static function link(User $user): string
    {
        $proof = URL::temporarySignedRoute('identity.verify', now()->addHour(), [
            'userId' => $user->id,
            'hash' => sha1($user->email),
        ], absolute: false);

        return self::frontendLink('/auth/verify-email', (string) parse_url($proof, PHP_URL_QUERY));
    }

    public static function accepts(User $user, string $userId, string $hash, int $expires, string $signature): bool
    {
        if ($user->id !== $userId || ! hash_equals(sha1($user->email), $hash)) {
            return false;
        }
        $path = route('identity.verify', absolute: false);
        $query = Arr::query([
            'expires' => $expires,
            'hash' => $hash,
            'userId' => $userId,
            'signature' => $signature,
        ]);

        return URL::hasValidRelativeSignature(Request::create($path.'?'.$query));
    }

    public static function frontendLink(string $path, string $fragment): string
    {
        return rtrim((string) config('app.url'), '/').$path.'#'.$fragment;
    }
}
