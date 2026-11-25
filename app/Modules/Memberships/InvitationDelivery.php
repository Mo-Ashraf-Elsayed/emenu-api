<?php

namespace App\Modules\Memberships;

use App\Modules\Identity\PrivateMail;
use App\Modules\Identity\VerificationProof;
use App\Modules\Restaurants\Restaurant;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

final class InvitationDelivery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $restaurantId,
        public readonly string $invitationId,
        public readonly string $token,
    ) {}

    public function handle(): void
    {
        $restaurant = Restaurant::query()->where('status', 'active')->find($this->restaurantId);
        $invitation = $restaurant?->invitations()->find($this->invitationId);
        if ($restaurant === null || $invitation === null || $invitation->effectiveStatus() !== 'pending'
            || ! hash_equals($invitation->token_digest, hash('sha256', $this->token))
            || ! Invitations::issuerCanInvite($restaurant, $invitation)) {
            return;
        }

        PrivateMail::send($invitation->email, 'Your Digital Menu restaurant invitation', VerificationProof::frontendLink(
            '/auth/invitations/accept', Arr::query(['token' => $this->token]),
        ));
    }
}
