<?php

namespace App\Modules\Identity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/** @property NewAccessToken $resource */
final class AccessTokenResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'accessToken' => $this->resource->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $this->resource->accessToken->expires_at?->toISOString(),
            'user' => new UserResource($this->resource->accessToken->tokenable),
        ];
    }
}
