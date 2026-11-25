<?php

use App\Modules\Memberships\Invitation;
use App\Modules\Memberships\InvitationDelivery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\PhaseTwo;
use Tests\Support\UsesTestDatabase;

uses(UsesTestDatabase::class);

it('creates a restaurant atomically and binds list cursors to the actor and filters', function (): void {
    $owner = PhaseTwo::user('owner@example.test');
    PhaseTwo::login($this, $owner);

    $payload = ['name' => 'Orchard cafe', 'slug' => 'orchard', 'currency' => 'AED'];
    $this->postJson('/api/v1/restaurants', $payload + ['restaurant_id' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson('/api/v1/restaurants', array_replace($payload, ['currency' => 'XXX']))
        ->assertUnprocessable()->assertJsonValidationErrors('currency', 'error.fields');
    $response = $this->postJson('/api/v1/restaurants', $payload)->assertCreated()->assertJsonPath('data.role', 'owner');
    $first = $response->json('data.id');
    $response->assertHeader('Location', '/api/v1/restaurants/'.$first)->assertHeader('X-Request-ID');
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    $this->assertDatabaseHas('restaurant_branding', ['restaurant_id' => $first]);
    $this->assertDatabaseHas('public_menu_settings', ['restaurant_id' => $first, 'publication_revision' => 0]);
    $this->postJson('/api/v1/restaurants', $payload)->assertConflict()->assertJsonPath('error.code', 'SLUG_TAKEN');
    PhaseTwo::restaurant($owner, 'orchard-two');

    $cursor = $this->getJson('/api/v1/restaurants?perPage=1')->assertOk()->assertJsonCount(1, 'data')->json('meta.nextCursor');
    expect($cursor)->toBeString();
    $url = '/api/v1/restaurants?perPage=1&cursor='.urlencode($cursor);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($url.'&q=orchard')->assertUnprocessable();
    $this->getJson('/api/v1/restaurants?perPage=1&perPage=2')->assertUnprocessable();

    $stranger = PhaseTwo::user('other@example.test');
    PhaseTwo::login($this, $stranger);
    $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/restaurants/'.$first)->assertNotFound();
    $this->getJson($url)->assertUnprocessable();
});

it('scopes membership mutation and prevents removing the active owner', function (): void {
    $actor = PhaseTwo::user('owner@example.test');
    $recipient = PhaseTwo::user('recipient@example.test');
    $restaurant = PhaseTwo::restaurant($actor, 'orchard');
    $another = PhaseTwo::restaurant($actor, 'elm-cafe');
    $member = PhaseTwo::member($restaurant, $recipient, 'staff');
    $foreign = PhaseTwo::member($another, $recipient, 'manager');
    $owner = $restaurant->memberships()->where('role', 'owner')->sole();
    $base = '/api/v1/restaurants/'.$restaurant->id;

    PhaseTwo::login($this, $actor);
    $this->patchJson($base.'/memberships/'.$foreign->id, ['role' => 'staff'])->assertNotFound();
    $this->deleteJson($base.'/memberships/'.$owner->id)->assertConflict();
    $this->patchJson($base.'/memberships/'.$member->id, ['role' => 'owner'])->assertUnprocessable();
    $this->postJson($base.'/ownership-transfer', ['membershipId' => $foreign->id, 'currentPassword' => PhaseTwo::PASSWORD])
        ->assertUnprocessable();
    $this->postJson($base.'/ownership-transfer', ['membershipId' => $member->id, 'currentPassword' => 'incorrect'])
        ->assertForbidden();
    $this->postJson($base.'/ownership-transfer', ['membershipId' => $member->id, 'currentPassword' => PhaseTwo::PASSWORD])
        ->assertOk()->assertJsonPath('data.role', 'owner');
    expect($owner->fresh()->role)->toBe('manager')
        ->and($restaurant->memberships()->where('role', 'owner')->where('status', 'active')->count())->toBe(1);
    $this->getJson($base.'/memberships')->assertForbidden();
});

it('uses the PostgreSQL unique index as a second line of protection for the active owner', function (): void {
    $owner = PhaseTwo::user('owner@example.test');
    $other = PhaseTwo::user('other@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');

    expect(fn () => DB::transaction(fn () => PhaseTwo::member($restaurant, $other, 'owner')))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($restaurant->memberships()->where('role', 'owner')->count())->toBe(1);
});

it('restricts invitation roles and rotates a proof before a matching account can redeem it', function (): void {
    Bus::fake([InvitationDelivery::class]);
    $owner = PhaseTwo::user('owner@example.test');
    $manager = PhaseTwo::user('manager@example.test');
    $invitee = PhaseTwo::user('invitee@example.test');
    $other = PhaseTwo::user('other@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    PhaseTwo::member($restaurant, $manager, 'manager');
    $base = '/api/v1/restaurants/'.$restaurant->id.'/invitations';

    PhaseTwo::login($this, $owner);
    $private = $this->postJson($base, ['email' => 'future-manager@example.test', 'role' => 'manager'])->assertCreated()->json('data.id');

    PhaseTwo::login($this, $manager);
    $this->postJson($base, ['email' => $other->email, 'role' => 'manager'])->assertForbidden();
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson($base.'/'.$private)->assertForbidden();
    $created = $this->postJson($base, ['email' => strtoupper($invitee->email), 'role' => 'staff'])
        ->assertCreated()->assertJsonMissingPath('data.token')->json('data.id');
    $previous = Bus::dispatched(InvitationDelivery::class, fn ($job) => $job->invitationId === $created)->sole();
    expect($previous)->toBeInstanceOf(ShouldBeEncrypted::class);
    $this->postJson($base.'/'.$created.'/resend')->assertOk();
    $current = Bus::dispatched(InvitationDelivery::class, fn ($job) => $job->invitationId === $created)->last();
    expect($current->token)->not->toBe($previous->token)
        ->and(Invitation::query()->findOrFail($created)->token_digest)->toBe(hash('sha256', $current->token));

    PhaseTwo::login($this, $other);
    $this->postJson('/api/v1/invitations/accept', ['token' => $current->token])->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_INVITATION');

    PhaseTwo::login($this, $invitee);
    $this->postJson('/api/v1/invitations/accept', ['token' => $previous->token])->assertUnprocessable();
    $this->postJson('/api/v1/invitations/accept', ['token' => $current->token])->assertOk()->assertJsonPath('data.role', 'staff');
    $this->postJson('/api/v1/invitations/accept', ['token' => $current->token])->assertOk();
    expect($restaurant->memberships()->where('user_id', $invitee->id)->count())->toBe(1);
});

it('rejects an expired invite and blocks redemption after the issuer loses authority', function (): void {
    Bus::fake([InvitationDelivery::class]);
    $owner = PhaseTwo::user('owner@example.test');
    $manager = PhaseTwo::user('manager@example.test');
    $invitee = PhaseTwo::user('invitee@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    $membership = PhaseTwo::member($restaurant, $manager, 'manager');
    $base = '/api/v1/restaurants/'.$restaurant->id;

    PhaseTwo::login($this, $manager);
    $expired = $this->postJson($base.'/invitations', ['email' => $invitee->email, 'role' => 'staff'])->assertCreated()->json('data.id');
    $job = Bus::dispatched(InvitationDelivery::class, fn ($job) => $job->invitationId === $expired)->sole();
    Invitation::query()->whereKey($expired)->update(['expires_at' => now()->subSecond()]);

    PhaseTwo::login($this, $invitee);
    $this->postJson('/api/v1/invitations/accept', ['token' => $job->token])->assertUnprocessable();
    PhaseTwo::login($this, $manager);
    $this->getJson($base.'/invitations?status=expired')->assertOk()->assertJsonPath('data.0.status', 'expired');
    $fresh = $this->postJson($base.'/invitations', ['email' => $invitee->email, 'role' => 'staff'])->assertCreated()->json('data.id');
    $newInvite = Bus::dispatched(InvitationDelivery::class, fn ($job) => $job->invitationId === $fresh)->sole();
    PhaseTwo::login($this, $owner);
    $this->deleteJson($base.'/memberships/'.$membership->id)->assertNoContent();

    PhaseTwo::login($this, $invitee);
    $this->postJson('/api/v1/invitations/accept', ['token' => $newInvite->token])->assertUnprocessable();
    $this->assertDatabaseMissing('restaurant_memberships', ['restaurant_id' => $restaurant->id, 'user_id' => $invitee->id]);
});

it('keeps platform powers separate and clears publication on suspension and archive', function (): void {
    $owner = PhaseTwo::user('owner@example.test');
    $platform = PhaseTwo::user('admin@example.test', platform: true);
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    $tenantUrl = '/api/v1/restaurants/'.$restaurant->id;
    $platformUrl = '/api/v1/platform/restaurants/'.$restaurant->id;

    DB::table('public_menu_settings')->where('restaurant_id', $restaurant->id)->update([
        'published_source_revision' => 1, 'publication_revision' => 1, 'published_at' => now(), 'snapshot' => '{}',
    ]);

    PhaseTwo::login($this, $owner);
    $this->getJson($platformUrl)->assertForbidden();
    PhaseTwo::login($this, $platform);
    $this->getJson('/api/v1/platform/restaurants?status=active')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($tenantUrl)->assertNotFound();
    $this->patchJson($platformUrl.'/status', ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->assertDatabaseHas('public_menu_settings', ['restaurant_id' => $restaurant->id, 'publication_revision' => 2, 'snapshot' => null]);

    PhaseTwo::login($this, $owner);
    $this->getJson($tenantUrl)->assertNotFound();
    PhaseTwo::login($this, $platform);
    $this->patchJson($platformUrl.'/status', ['status' => 'active'])->assertOk();
    PhaseTwo::login($this, $owner);
    $this->deleteJson($tenantUrl)->assertNoContent();
    $this->getJson($tenantUrl)->assertNotFound();
    $this->postJson('/api/v1/restaurants', ['name' => 'New orchard', 'slug' => 'orchard', 'currency' => 'AED'])->assertConflict();
});

it('rejects invitation membership references from another restaurant in PostgreSQL', function (): void {
    Bus::fake([InvitationDelivery::class]);
    $owner = PhaseTwo::user('owner@example.test');
    $foreignOwner = PhaseTwo::user('other-owner@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    PhaseTwo::restaurant($foreignOwner, 'seaside');
    PhaseTwo::login($this, $owner);
    $id = $this->postJson('/api/v1/restaurants/'.$restaurant->id.'/invitations', [
        'email' => $foreignOwner->email, 'role' => 'staff',
    ])->assertCreated()->json('data.id');

    expect(fn () => DB::transaction(fn () => Invitation::query()->whereKey($id)->update([
        'inviter_id' => $foreignOwner->id,
    ])))->toThrow(QueryException::class, 'invitations_inviter_membership');

    expect(fn () => DB::transaction(fn () => Invitation::query()->whereKey($id)->update([
        'status' => 'accepted', 'accepted_user_id' => $foreignOwner->id, 'accepted_at' => now(),
    ])))->toThrow(QueryException::class, 'invitations_recipient_membership');
});
