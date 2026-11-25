<?php

namespace App\Modules\Memberships;

use App\Modules\Identity\User;
use App\Modules\Restaurants\Restaurant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class Invitations
{
    /** @return array{Invitation,InvitationDelivery} */
    public function create(Tenant $tenant, string $email, string $role): array
    {
        Gate::authorize('tenant-invite', [$tenant, $role]);
        $pending = $tenant->restaurant->invitations()->where('email', $email)->where('status', 'pending')->first();
        if ($pending !== null) {
            if ($pending->effectiveStatus() === 'pending') {
                throw new ApiProblem(409, 'INVITATION_CONFLICT');
            }
            $pending->update(['status' => 'expired']);
        }

        $this->limitDelivery($tenant, $email);
        $token = self::token();
        $invitation = $tenant->restaurant->invitations()->create([
            'email' => $email,
            'role' => $role,
            'status' => 'pending',
            'inviter_id' => $tenant->actor->id,
            'expires_at' => now()->addHours(48),
            'token_digest' => hash('sha256', $token),
        ]);

        return [$invitation, new InvitationDelivery($tenant->restaurant->id, $invitation->id, $token)];
    }

    /** @return array{Invitation,InvitationDelivery} */
    public function resend(Tenant $tenant, string $id): array
    {
        $invitation = $this->pending($tenant, $id);
        if (! self::issuerCanInvite($tenant->restaurant, $invitation)) {
            throw new ApiProblem(409, 'INVITATION_CONFLICT');
        }
        $this->limitDelivery($tenant, $invitation->email);
        $token = self::token();
        $invitation->update(['token_digest' => hash('sha256', $token), 'expires_at' => now()->addHours(48)]);

        return [$invitation, new InvitationDelivery($tenant->restaurant->id, $invitation->id, $token)];
    }

    public function revoke(Tenant $tenant, string $id): Invitation
    {
        $invitation = $this->pending($tenant, $id);
        $invitation->update(['status' => 'revoked']);

        return $invitation;
    }

    public function accept(User $actor, string $token): Membership
    {
        $digest = hash('sha256', $token);

        return DB::transaction(function () use ($actor, $digest): Membership {
            $key = Invitation::query()->where('token_digest', $digest)->value('restaurant_id');
            $restaurant = is_string($key)
                ? Restaurant::query()->where('status', 'active')->lockForUpdate()->find($key)
                : null;
            $invite = $restaurant?->invitations()->where('token_digest', $digest)->lockForUpdate()->first();

            if ($restaurant === null || $invite === null || $invite->email !== $actor->email || $invite->expires_at->lte(now())) {
                throw new ApiProblem(422, 'INVALID_INVITATION');
            }

            $membership = $restaurant->memberships()->where('user_id', $actor->id)->first();
            if ($invite->status === 'accepted' && $invite->accepted_user_id === $actor->id && $membership?->status === 'active') {
                $latest = $restaurant->invitations()->where('accepted_user_id', $actor->id)
                    ->where('status', 'accepted')->orderByDesc('accepted_at')->orderByDesc('id')->value('id');
                if ($latest === $invite->id) {
                    return $membership;
                }
            }
            if ($invite->status !== 'pending' || ! self::issuerCanInvite($restaurant, $invite)) {
                throw new ApiProblem(422, 'INVALID_INVITATION');
            }
            if ($membership?->status === 'active') {
                throw new ApiProblem(409, 'INVITATION_CONFLICT');
            }

            $membership ??= $restaurant->memberships()->make(['user_id' => $actor->id]);
            $membership->fill(['role' => $invite->role, 'status' => 'active'])->save();
            $invite->forceFill(['status' => 'accepted', 'accepted_at' => now(), 'accepted_user_id' => $actor->id])->save();

            return $membership;
        });
    }

    public static function issuerCanInvite(Restaurant $restaurant, Invitation $invite): bool
    {
        $membership = $restaurant->memberships()->with('user')->where('user_id', $invite->inviter_id)
            ->where('status', 'active')->first();
        $issuer = $membership?->user;
        if ($membership === null || $issuer === null || ! $issuer->hasVerifiedEmail()) {
            return false;
        }

        return Gate::forUser($issuer)->allows('tenant-invite', [new Tenant($restaurant, $membership, $issuer), $invite->role]);
    }

    private function pending(Tenant $tenant, string $id): Invitation
    {
        $invitation = $tenant->restaurant->invitations()->whereKey($id)->firstOrFail();
        Gate::authorize('tenant-invite', [$tenant, $invitation->role]);
        if ($invitation->effectiveStatus() !== 'pending') {
            throw new ApiProblem(409, 'INVITATION_CONFLICT');
        }

        return $invitation;
    }

    private function limitDelivery(Tenant $tenant, string $email): void
    {
        foreach ([
            'invitation-restaurant:'.$tenant->restaurant->id => 30,
            'invitation-recipient:'.hash('sha256', $email) => 6,
        ] as $key => $limit) {
            if (RateLimiter::hit($key, 3600) > $limit) {
                throw new TooManyRequestsHttpException(max(1, RateLimiter::availableIn($key)));
            }
        }
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
