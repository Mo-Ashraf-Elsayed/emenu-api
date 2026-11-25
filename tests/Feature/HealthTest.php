<?php

use Illuminate\Database\Connection;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('serves liveness without connecting to infrastructure or opening a session', function (): void {
    DB::shouldReceive('getConnections')->andReturn([]);
    DB::shouldReceive('connection')->never();
    Redis::shouldReceive('connection')->never();

    $this->withHeader('Origin', 'http://localhost:4200')
        ->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok'])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('Set-Cookie');
});

it('returns ready after PostgreSQL and Redis both respond', function (): void {
    $database = Mockery::mock(Connection::class);
    $database->shouldReceive('selectOne')->with('select 1')->once()->andReturn((object) ['ready' => 1]);
    DB::shouldReceive('getConnections')->andReturn([]);
    DB::shouldReceive('connection')->with('pgsql')->once()->andReturn($database);

    $redis = Mockery::mock(RedisConnection::class);
    $redis->shouldReceive('command')->with('ping')->once()->andReturn('PONG');
    Redis::shouldReceive('connection')->once()->andReturn($redis);

    $this->getJson('/api/ready')->assertOk()->assertExactJson(['status' => 'ready']);
});

it('reports unavailable without exposing dependency errors', function (string $dependency): void {
    $database = Mockery::mock(Connection::class);
    DB::shouldReceive('getConnections')->andReturn([]);
    DB::shouldReceive('connection')->with('pgsql')->once()->andReturn($database);

    if ($dependency === 'postgres') {
        $database->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('Private database connection detail'));
        Redis::shouldReceive('connection')->never();
    } else {
        $database->shouldReceive('selectOne')->once()->andReturn((object) ['ready' => 1]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('Private Redis connection detail'));
    }

    $this->getJson('/api/ready')->assertServiceUnavailable()->assertExactJson(['status' => 'unavailable']);
})->with(['postgres', 'redis']);

it('does not expose the legacy Sanctum cookie endpoint', function (): void {
    $this->getJson('/sanctum/csrf-cookie')->assertNotFound()->assertHeaderMissing('Set-Cookie');
});

it('keeps browser API access on the configured origin', function (): void {
    $this->withHeader('Origin', 'https://example.org')
        ->getJson('/api/health')
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('accepts configured Angular preflight requests for JSON and bearer headers', function (): void {
    config(['cors.allowed_origins' => ['http://localhost:4200', 'http://127.0.0.1:4200']]);

    $preflight = $this->withHeaders([
        'Origin' => 'http://localhost:4200',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization,content-type',
    ])->options('/api/v1/restaurants')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    expect(strtolower((string) $preflight->headers->get('Access-Control-Allow-Headers')))
        ->toContain('authorization', 'content-type');
    $this->withHeader('Origin', 'http://127.0.0.1:4201')
        ->options('/api/v1/restaurants')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
