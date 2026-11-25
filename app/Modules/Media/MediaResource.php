<?php

namespace App\Modules\Media;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Media */
final class MediaResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toISOString(),
            'variants' => Media::describe($this->variants ?? [], '/api/v1/restaurants/'.$this->restaurant_id.'/media/'.$this->id.'/variants'),
            'failureCode' => $this->failure_code,
        ];
    }
}
