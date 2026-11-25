<?php

namespace App\Modules\Memberships;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class InvitationRequest extends StrictRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->json('email');
        if (is_string($email)) {
            $this->json()->set('email', mb_strtolower(trim($email)));
        }
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return $this->route()?->getName() === 'invitations.accept'
            ? ['token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D']]
            : ['email' => ['required', 'string', 'max:254', 'email:rfc'], 'role' => ['required', 'string', Rule::in(['manager', 'staff'])]];
    }
}
