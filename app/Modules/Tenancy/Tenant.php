<?php

namespace App\Modules\Tenancy;

use App\Modules\Identity\User;
use App\Modules\Memberships\Membership;
use App\Modules\Restaurants\Restaurant;

final readonly class Tenant
{
    public function __construct(
        public Restaurant $restaurant,
        public Membership $membership,
        public User $actor,
    ) {}
}
