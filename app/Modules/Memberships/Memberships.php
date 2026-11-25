<?php

namespace App\Modules\Memberships;

use App\Modules\Identity\Accounts;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\ApiProblem;
use Illuminate\Support\Facades\Gate;

final class Memberships
{
    public function change(Tenant $tenant, string $id, ?string $role): Membership
    {
        $membership = $this->active($tenant, $id);
        Gate::authorize('tenant-owner', $tenant);
        if ($membership->role === 'owner') {
            throw new ApiProblem(409, 'OWNERSHIP_CONFLICT');
        }

        $membership->update($role === null ? ['status' => 'revoked'] : ['role' => $role]);

        return $membership->load('user');
    }

    public function transfer(Tenant $tenant, string $id, string $password): Membership
    {
        Gate::authorize('tenant-owner', $tenant);
        Accounts::stepUp($tenant->actor, $password);
        $recipient = $tenant->restaurant->memberships()->with('user')->where('status', 'active')->whereKey($id)->first();
        if ($recipient === null) {
            throw new ApiProblem(422, 'VALIDATION_FAILED');
        }
        if ($recipient->id === $tenant->membership->id || $recipient->user?->hasVerifiedEmail() !== true) {
            throw new ApiProblem(409, 'OWNERSHIP_CONFLICT');
        }

        $tenant->membership->update(['role' => 'manager']);
        $recipient->update(['role' => 'owner']);

        return $recipient;
    }

    private function active(Tenant $tenant, string $id): Membership
    {
        return $tenant->restaurant->memberships()->where('status', 'active')->whereKey($id)->firstOrFail();
    }
}
