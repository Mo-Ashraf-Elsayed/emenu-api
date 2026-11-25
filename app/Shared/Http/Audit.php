<?php

namespace App\Shared\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class Audit
{
    public static function record(Request $request, string $actor, string $action, ?string $restaurant = null): void
    {
        Log::info('emenu.security', [
            'actor' => $actor,
            'action' => $action,
            'restaurant' => $restaurant,
            'result' => 'succeeded',
            'request_id' => ApiErrors::requestId($request),
        ]);
    }
}
