<?php

namespace App\Modules\Memberships;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class MembershipRequest extends StrictRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return $this->route()?->getName() === 'memberships.transfer'
            ? ['membershipId' => ['required', 'uuid'], 'currentPassword' => ['required', 'string', 'max:128']]
            : ['role' => ['required', 'string', Rule::in(['manager', 'staff'])]];
    }
}
