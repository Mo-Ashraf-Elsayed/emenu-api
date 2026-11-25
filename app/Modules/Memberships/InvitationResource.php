<?php

namespace App\Modules\Memberships;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invitation */
final class InvitationResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->resource->effectiveStatus(),
            'expiresAt' => $this->expires_at->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
