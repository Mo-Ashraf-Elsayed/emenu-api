<?php

namespace App\Modules\Identity;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Password;

final class AccountDelivery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $userId, public readonly ?string $resetToken = null) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        if ($this->resetToken !== null) {
            if (Password::tokenExists($user, $this->resetToken)) {
                PrivateMail::send($user->email, 'Reset your Digital Menu password', VerificationProof::frontendLink(
                    '/auth/reset-password',
                    Arr::query(['token' => $this->resetToken, 'email' => $user->email]),
                ));
            }
        } elseif (! $user->hasVerifiedEmail()) {
            PrivateMail::send($user->email, 'Verify your Digital Menu email', VerificationProof::link($user));
        }
    }
}
