<?php

namespace App\Shared\Http;

use Illuminate\Validation\Rule;

final class ListRequest extends QueryRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $visibility = ['isVisible' => ['sometimes', Rule::in(['true', 'false'])]];
        $search = ['q' => ['sometimes', 'string', 'min:2', 'max:120']];
        $filters = match ($this->route()?->getName()) {
            'restaurants.index' => $search,
            'platform.restaurants.index' => $search + ['status' => ['sometimes', Rule::in(['active', 'suspended'])]],
            'memberships.index' => ['role' => ['sometimes', Rule::in(['owner', 'manager', 'staff'])]],
            'invitations.index' => [
                'role' => ['sometimes', Rule::in(['manager', 'staff'])],
                'status' => ['sometimes', Rule::in(['pending', 'accepted', 'revoked', 'expired'])],
            ],
            'categories.index' => $search + $visibility,
            'products.index' => $search + $visibility + [
                'categoryId' => ['sometimes', 'uuid'],
                'isAvailable' => ['sometimes', Rule::in(['true', 'false'])],
            ],
            'media.index' => ['status' => ['sometimes', Rule::in(['pending', 'ready', 'failed'])]],
            default => [],
        };

        return [
            'perPage' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'sort' => ['sometimes', Rule::in($this->catalog() ? ['position', '-position', 'createdAt', '-createdAt'] : ['createdAt', '-createdAt'])],
        ] + $filters;
    }

    public function perPage(): int
    {
        return (int) $this->validated('perPage', 20);
    }

    /** @return 'asc'|'desc' */
    public function direction(): string
    {
        return str_starts_with($this->sortKey(), '-') ? 'desc' : 'asc';
    }

    public function sortKey(): string
    {
        return $this->filter('sort') ?? ($this->catalog() ? 'position' : '-createdAt');
    }

    public function orderColumn(): string
    {
        return str_ends_with($this->sortKey(), 'position') ? 'sort_order' : 'created_at';
    }

    private function catalog(): bool
    {
        return $this->routeIs('categories.index', 'products.index');
    }
}
