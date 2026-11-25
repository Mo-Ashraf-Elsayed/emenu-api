<?php

namespace App\Modules\PublicMenus;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicMenuResource extends JsonResource
{
    /** @param array<string,mixed> $menu */
    public function __construct(private readonly array $menu)
    {
        parent::__construct($menu);
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return $this->menu;
    }
}
