<?php

namespace App\Modules\Identity;

use App\Shared\Http\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class IdentityController
{
    public function register(IdentityRequest $request, Accounts $accounts): Response
    {
        $accounts->register($request->value('name'), $request->value('email'), $request->value('password'));

        return response()->noContent(202);
    }

    public function login(IdentityRequest $request, Accounts $accounts): AccessTokenResource
    {
        return new AccessTokenResource($accounts->authenticate($request->value('email'), $request->value('password')));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource(DashboardToken::user($request));
    }

    public function logout(Request $request): Response
    {
        DashboardToken::user($request)->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function resend(Request $request): Response
    {
        $user = DashboardToken::user($request);
        if (! $user->hasVerifiedEmail()) {
            PrivateMail::enqueue(new AccountDelivery($user->id));
        }

        return response()->noContent(202);
    }

    public function verify(IdentityRequest $request, Accounts $accounts): Response
    {
        $user = DashboardToken::user($request);
        $accounts->verify($user, $request->value('userId'), $request->value('hash'), (int) $request->validated('expires'), $request->value('signature'));

        return response()->noContent();
    }

    public function forgot(IdentityRequest $request, Accounts $accounts): Response
    {
        $accounts->sendReset($request->value('email'));

        return response()->noContent(202);
    }

    public function reset(IdentityRequest $request, Accounts $accounts): Response
    {
        $user = $accounts->reset($request->value('email'), $request->value('token'), $request->value('password'));
        Audit::record($request, $user->id, 'identity.password_reset');

        return response()->noContent();
    }

    public function change(IdentityRequest $request, Accounts $accounts): AccessTokenResource
    {
        return $this->rotate($request, $accounts, $request->value('password'));
    }

    public function invalidate(IdentityRequest $request, Accounts $accounts): AccessTokenResource
    {
        return $this->rotate($request, $accounts, null);
    }

    private function rotate(IdentityRequest $request, Accounts $accounts, ?string $password): AccessTokenResource
    {
        $user = DashboardToken::user($request);
        $token = $accounts->changeSession($user, $request->value('currentPassword'), $password);
        Audit::record($request, $user->id, $password === null ? 'identity.sessions_invalidated' : 'identity.password_changed');

        return new AccessTokenResource($token);
    }
}
