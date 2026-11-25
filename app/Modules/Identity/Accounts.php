<?php

namespace App\Modules\Identity;

use App\Shared\Http\ApiProblem;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use Laravel\Sanctum\NewAccessToken;

final class Accounts
{
    public function register(string $name, string $email, string $password): void
    {
        $user = User::query()->createOrFirst(['email' => $email], [
            'name' => $name, 'password' => Hash::make($password),
        ]);
        if ($user->wasRecentlyCreated) {
            PrivateMail::enqueue(new AccountDelivery($user->id));
        }
    }

    public function authenticate(string $email, string $password): NewAccessToken
    {
        $user = User::query()->where('email', $email)->first();
        $hash = $user?->getAuthPassword() ?? (string) config('hashing.dummy');
        $valid = Hash::check($password, $hash);
        if (! $valid || $user === null) {
            throw new ApiProblem(401, 'INVALID_CREDENTIALS');
        }

        return DB::transaction(function () use ($user): NewAccessToken {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked === null || $locked->session_version !== $user->session_version
                || $locked->getAuthPassword() !== $user->getAuthPassword()) {
                throw new ApiProblem(401, 'INVALID_CREDENTIALS');
            }

            return DashboardToken::issue($locked, now()->addHours(12));
        });
    }

    public function verify(User $user, string $id, string $hash, int $expires, string $signature): void
    {
        if (! VerificationProof::accepts($user, $id, $hash, $expires, $signature)) {
            throw new ApiProblem(422, 'VERIFICATION_INVALID');
        }

        $verified = User::query()->whereKey($user->id)->where('email', $user->email)
            ->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        if ($verified === 1) {
            event(new Verified($user->refresh()));
        }
    }

    public function sendReset(string $email): void
    {
        $job = DB::transaction(function () use ($email): ?AccountDelivery {
            if (User::query()->where('email', $email)->lockForUpdate()->first() === null) {
                return (new Timebox)->call(static fn (): null => null, 200000);
            }

            $job = null;
            Password::sendResetLink(['email' => $email], function (User $user, string $token) use (&$job): void {
                $job = new AccountDelivery($user->id, $token);
            });

            return $job;
        });

        if ($job !== null) {
            PrivateMail::enqueue($job);
        }
    }

    public function reset(string $email, string $token, string $password): User
    {
        $result = DB::transaction(function () use ($email, $token, $password): ?User {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                return (new Timebox)->call(static fn (): null => null, 200000);
            }

            $status = Password::reset([
                'email' => $email,
                'token' => $token,
                'password' => $password,
                'password_confirmation' => $password,
            ], function (User $locked, string $newPassword): void {
                $locked->password = Hash::make($newPassword);
                $locked->session_version++;
                $locked->save();
                $locked->tokens()->delete();
            });

            return $status === Password::PASSWORD_RESET ? $user->refresh() : null;
        });
        if ($result === null) {
            throw new ApiProblem(422, 'RESET_INVALID');
        }
        event(new PasswordReset($result));

        return $result;
    }

    public function changeSession(User $actor, string $password, ?string $replacement): NewAccessToken
    {
        return DB::transaction(function () use ($actor, $password, $replacement): NewAccessToken {
            $user = self::stepUp($actor, $password);
            $active = $user->tokens()->whereKey($actor->currentAccessToken()->getKey())->first();
            if ($active === null || $active->expires_at === null || ! DashboardToken::valid($active, true)) {
                throw new ApiProblem(401, 'AUTH_REQUIRED');
            }

            if ($replacement !== null) {
                $user->password = Hash::make($replacement);
                Password::deleteToken($user);
            }
            $user->session_version++;
            $user->save();
            $user->tokens()->delete();

            return DashboardToken::issue($user, $active->expires_at);
        });
    }

    public static function stepUp(User $actor, string $password): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ($user->session_version !== $actor->session_version) {
            throw new ApiProblem(401, 'AUTH_REQUIRED');
        }
        if (! Hash::check($password, $user->password)) {
            throw new ApiProblem(403, 'CURRENT_PASSWORD_INVALID');
        }

        return $user;
    }
}
