<?php

namespace App\Modules\Identity;

use App\Shared\Http\StrictRequest;
use Closure;

final class IdentityRequest extends StrictRequest
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
        $email = ['required', 'string', 'max:254', 'email:rfc'];
        $secret = ['required', 'string', 'max:128'];
        $newPassword = [
            'password' => [
                'required', 'string', 'min:15', 'max:128', 'confirmed:passwordConfirmation',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && in_array(mb_strtolower($value), [
                        '123456789012345', '1234567890123456', '12345678901234567890',
                        'qwertyuiopasdfgh', 'qwertyuiopasdfghjkl', 'qwerty1234567890',
                        'passwordpassword', 'passwordpassword1', 'password12345678',
                        'administrator123', 'letmeinletmeinletmein', 'changemechangeme',
                        'iloveyouiloveyou', 'correct horse battery staple', 'my password is password',
                    ], true)) {
                        $fail('Choose a password that is harder to guess.');
                    }
                },
            ],
            'passwordConfirmation' => ['required', 'string', 'min:15', 'max:128'],
        ];

        return match ($this->route()?->getName()) {
            'identity.register' => ['name' => ['required', 'string', 'max:120'], 'email' => $email] + $newPassword,
            'identity.login' => ['email' => $email, 'password' => $secret],
            'identity.forgot' => ['email' => $email],
            'identity.reset' => ['email' => $email, 'token' => ['required', 'string', 'max:256']] + $newPassword,
            'identity.change' => ['currentPassword' => $secret] + $newPassword,
            'identity.invalidate' => ['currentPassword' => $secret],
            'identity.verify' => [
                'userId' => ['required', 'uuid'],
                'hash' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/D'],
                'expires' => ['required', 'integer:strict', 'between:1,9007199254740991'],
                'signature' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            ],
            default => [],
        };
    }
}
