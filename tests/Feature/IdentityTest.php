<?php

use App\Modules\Identity\AccountDelivery;
use App\Modules\Identity\DashboardToken;
use App\Modules\Identity\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Support\PhaseTwo;
use Tests\Support\UsesTestDatabase;

uses(UsesTestDatabase::class);

function bearer(string $token): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$token];
}

it('registers privately and verifies only the account named in the signed proof', function (): void {
    Bus::fake([AccountDelivery::class]);
    $registration = [
        'name' => 'Menu owner',
        'email' => 'Owner@Example.test',
        'password' => PhaseTwo::PASSWORD,
        'passwordConfirmation' => PhaseTwo::PASSWORD,
    ];
    $this->postJson('/api/v1/auth/register', $registration + ['isPlatformAdmin' => true])->assertUnprocessable();
    $this->postJson('/api/v1/auth/register', $registration)->assertNoContent(202);
    $this->postJson('/api/v1/auth/register', $registration)->assertNoContent(202);

    $owner = User::query()->where('email', 'owner@example.test')->sole();
    expect($owner->is_platform_admin)->toBeFalse()
        ->and($owner->password)->toStartWith('$argon2id$');
    Bus::assertDispatchedTimes(AccountDelivery::class, 1);
    Bus::assertDispatched(AccountDelivery::class, fn (AccountDelivery $job) => $job->userId === $owner->id && $job instanceof ShouldBeEncrypted);

    $login = $this->postJson('/api/v1/auth/login', ['email' => $registration['email'], 'password' => PhaseTwo::PASSWORD])
        ->assertOk()->assertJsonPath('data.user.emailVerified', false)
        ->assertJsonPath('data.tokenType', 'Bearer')->assertHeaderMissing('Set-Cookie');
    $token = $login->json('data.accessToken');
    expect($owner->tokens()->sole()->token)->toBe(hash('sha256', explode('|', $token, 2)[1]));
    $this->getJson('/api/v1/restaurants', bearer($token))->assertForbidden()->assertJsonPath('error.code', 'EMAIL_UNVERIFIED');

    Bus::dispatched(AccountDelivery::class)->sole()->handle();
    $message = Mail::getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    preg_match('~^https?://[^/]+/auth/verify-email#([^\s]+)$~m', $message->getTextBody(), $link);
    expect($link)->toHaveCount(2);
    parse_str($link[1], $proof);
    $proof['expires'] = (int) $proof['expires'];

    $other = PhaseTwo::user('other@example.test', false);
    PhaseTwo::login($this, $other);
    $this->postJson('/api/v1/auth/email/verify', $proof)->assertUnprocessable()->assertJsonPath('error.code', 'VERIFICATION_INVALID');

    PhaseTwo::login($this, $owner);
    $this->postJson('/api/v1/auth/email/verify', array_replace($proof, ['signature' => str_repeat('a', 64)]))->assertUnprocessable();
    $this->postJson('/api/v1/auth/email/verify', $proof)->assertNoContent();
    expect($owner->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('blocks common passwords and rate limits equivalent normalized login addresses', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Operator', 'email' => 'owner@example.test',
        'password' => 'passwordpassword', 'passwordConfirmation' => 'passwordpassword',
    ])->assertUnprocessable()->assertJsonValidationErrors('password', 'error.fields');

    $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'MISSING@EXAMPLE.TEST', 'password' => 'incorrect'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'incorrect'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('consumes a reset proof once and invalidates existing sessions', function (): void {
    Bus::fake([AccountDelivery::class]);
    $user = PhaseTwo::user('recover@example.test');
    $oldToken = DashboardToken::issue($user, now()->addHours(12))->plainTextToken;
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'absent@example.test'])->assertNoContent(202);
    $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertNoContent(202);
    $job = Bus::dispatched(AccountDelivery::class)->sole();
    expect(Password::tokenExists($user, $job->resetToken))->toBeTrue();

    $new = 'Seaside lamp and three paper cranes 97';
    $payload = [
        'email' => $user->email, 'token' => $job->resetToken, 'password' => $new, 'passwordConfirmation' => $new,
    ];
    $this->postJson('/api/v1/auth/password/reset', $payload)->assertNoContent();
    expect(Hash::check($new, $user->fresh()->password))->toBeTrue();
    $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'RESET_INVALID');

    $this->getJson('/api/v1/auth/me', bearer($oldToken))
        ->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_REQUIRED');
});

it('requires step-up and preserves the absolute expiration on session rotation', function (): void {
    $user = PhaseTwo::user('security@example.test');
    $expiresAt = now()->addHours(11)->startOfSecond();
    $current = DashboardToken::issue($user, $expiresAt)->plainTextToken;
    $other = DashboardToken::issue($user, now()->addHours(12))->plainTextToken;
    $this->postJson('/api/v1/auth/sessions/invalidate-others', ['currentPassword' => 'incorrect'], bearer($current))
        ->assertForbidden()->assertJsonPath('error.code', 'CURRENT_PASSWORD_INVALID');

    $rotated = $this->postJson('/api/v1/auth/sessions/invalidate-others', ['currentPassword' => PhaseTwo::PASSWORD], bearer($current))
        ->assertOk()->assertJsonPath('data.expiresAt', $expiresAt->toISOString())->json('data.accessToken');
    $this->getJson('/api/v1/auth/me', bearer($current))->assertUnauthorized();
    $this->getJson('/api/v1/auth/me', bearer($other))->assertUnauthorized();
    $this->getJson('/api/v1/auth/me', bearer($rotated))->assertOk()->assertJsonMissingPath('data.accessToken');
});

it('rotates the current token on password change and revokes it on logout', function (): void {
    $user = PhaseTwo::user('change@example.test');
    $current = DashboardToken::issue($user, now()->addHours(12))->plainTextToken;
    $new = 'Cedar branches under pale moonlight 75';
    $rotated = $this->postJson('/api/v1/auth/password/change', [
        'currentPassword' => PhaseTwo::PASSWORD, 'password' => $new, 'passwordConfirmation' => $new,
    ], bearer($current))->assertOk()->assertHeaderMissing('Set-Cookie')->json('data.accessToken');
    expect(Hash::check($new, $user->fresh()->password))->toBeTrue();

    $this->getJson('/api/v1/auth/me', bearer($current))->assertUnauthorized();
    $this->getJson('/api/v1/auth/me', bearer($rotated))->assertOk();
    $this->postJson('/api/v1/auth/logout', [], bearer($rotated))->assertNoContent();
    $this->getJson('/api/v1/auth/me', bearer($rotated))->assertUnauthorized();
});

it('rejects idle, expired and non-dashboard tokens and legacy sessions', function (): void {
    $user = PhaseTwo::user('expiration@example.test');
    $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertUnauthorized();
    $idle = DashboardToken::issue($user, now()->addHours(12));
    $idle->accessToken->forceFill(['last_used_at' => now()->subHours(2)])->save();
    $expired = DashboardToken::issue($user, now()->subSecond());
    $wrongAbility = $user->createToken('other', ['other'], now()->addHour());
    foreach ([$idle, $expired, $wrongAbility] as $token) {
        $this->getJson('/api/v1/auth/me', bearer($token->plainTextToken))->assertUnauthorized();
    }
});

it('requires a JSON object on authentication writes', function (): void {
    $this->post('/api/v1/auth/login', [], ['Accept' => 'application/json'])->assertUnsupportedMediaType();
    $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"email":')
        ->assertBadRequest()->assertJsonPath('error.code', 'MALFORMED_JSON');
});

it('returns a JSON authentication error without a browser Accept header', function (): void {
    $response = $this->get('/api/v1/auth/me')
        ->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_REQUIRED');
    $response->assertHeader('X-Request-ID', $response->json('requestId'));
});
