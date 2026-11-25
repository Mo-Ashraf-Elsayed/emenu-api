<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PhaseTwo;
use Tests\Support\UsesCommittedTestDatabase;

uses(UsesCommittedTestDatabase::class);

it('locks the tenant during ownership transfer and denies a competing transfer by the former owner', function (): void {
    $owner = PhaseTwo::user('owner@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    $first = PhaseTwo::member($restaurant, PhaseTwo::user('first@example.test'), 'staff');
    $second = PhaseTwo::member($restaurant, PhaseTwo::user('second@example.test'), 'staff');

    config(['database.connections.contender' => config('database.connections.pgsql')]);
    $contender = DB::connection('contender');
    $lockResult = null;
    DB::listen(function (QueryExecuted $query) use ($contender, $restaurant, &$lockResult): void {
        if ($lockResult !== null || ! str_starts_with($query->sql, 'update "restaurant_memberships"')) {
            return;
        }

        // Probe from a separate PostgreSQL session while the first request is still writing.
        try {
            $contender->selectOne('select id from restaurants where id = ? for update nowait', [$restaurant->id]);
            $lockResult = 'unlocked';
        } catch (QueryException $exception) {
            $lockResult = (string) $exception->getCode();
        }
    });

    try {
        $url = '/api/v1/restaurants/'.$restaurant->id.'/ownership-transfer';
        PhaseTwo::login($this, $owner);
        $this->postJson($url, ['membershipId' => $first->id, 'currentPassword' => PhaseTwo::PASSWORD])->assertOk();
        expect($lockResult)->toBe('55P03');
        $this->postJson($url, ['membershipId' => $second->id, 'currentPassword' => PhaseTwo::PASSWORD])->assertForbidden();

        expect($restaurant->memberships()->where('role', 'owner')->sole()->id)->toBe($first->id)
            ->and($second->fresh()->role)->toBe('staff');
    } finally {
        DB::purge('contender');
    }
});
