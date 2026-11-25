<?php

namespace App\Modules\Tenancy;

use App\Modules\Identity\User;

final class TenantPolicy
{
    public function owner(User $user, Tenant $tenant): bool
    {
        return $tenant->membership->user_id === $user->id && $tenant->membership->role === 'owner';
    }

    public function editMenu(User $user, Tenant $tenant): bool
    {
        return $tenant->membership->user_id === $user->id
            && in_array($tenant->membership->role, ['owner', 'manager'], true);
    }

    public function invite(User $user, Tenant $tenant, string $role): bool
    {
        return $tenant->membership->user_id === $user->id && (
            $tenant->membership->role === 'owner' && in_array($role, ['manager', 'staff'], true)
            || $tenant->membership->role === 'manager' && $role === 'staff'
        );
    }
}
