<?php

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class HealthController
{
    public function live(): JsonResponse
    {
        return $this->status('ok');
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection('pgsql')->selectOne('select 1');
            Redis::connection()->command('ping');
        } catch (Throwable) {
            return $this->status('unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->status('ready');
    }

    private function status(string $status, int $code = Response::HTTP_OK): JsonResponse
    {
        return response()->json(['status' => $status], $code, ['Cache-Control' => 'no-store']);
    }
}
