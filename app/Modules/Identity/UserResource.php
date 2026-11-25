<?php

namespace App\Modules\Identity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'emailVerified' => $this->resource->hasVerifiedEmail(),
            'isPlatformAdmin' => $this->is_platform_admin,
        ];
    }
}
