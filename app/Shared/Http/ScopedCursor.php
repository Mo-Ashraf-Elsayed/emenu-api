<?php

namespace App\Shared\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class ScopedCursor
{
    private readonly string $scope;

    public function __construct(private readonly ListRequest $request, string $actor, string $scope = '')
    {
        $filters = $request->validated();
        unset($filters['cursor']);
        $filters['perPage'] = $request->perPage();
        $filters['sort'] = $request->sortKey();
        ksort($filters);
        $this->scope = hash('sha256', json_encode(
            [$request->route()?->getName(), $actor, $scope, $filters],
            JSON_THROW_ON_ERROR,
        ));
    }

    public function current(): ?Cursor
    {
        $value = $this->request->filter('cursor');
        if ($value === null) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($value), true, 8, JSON_THROW_ON_ERROR);
            if (is_array($payload) && ($payload['scope'] ?? null) === $this->scope && is_string($payload['cursor'] ?? null)) {
                $cursor = Cursor::fromEncoded($payload['cursor']);
                if ($cursor !== null) {
                    return $cursor;
                }
            }
        } catch (DecryptException|JsonException) {
            // Treat malformed, tampered and out-of-scope cursors alike.
        }

        throw new ApiProblem(422, 'VALIDATION_FAILED');
    }

    /**
     * @template TModel of Model
     *
     * @param  CursorPaginator<int,TModel>  $page
     * @return array{perPage:int,nextCursor:?string,previousCursor:?string}
     */
    public function metadata(CursorPaginator $page): array
    {
        return [
            'perPage' => $this->request->perPage(),
            'nextCursor' => $this->encode($page->nextCursor()),
            'previousCursor' => $this->encode($page->previousCursor()),
        ];
    }

    private function encode(?Cursor $cursor): ?string
    {
        return $cursor === null ? null : Crypt::encryptString(json_encode(
            ['scope' => $this->scope, 'cursor' => $cursor->encode()],
            JSON_THROW_ON_ERROR,
        ));
    }
}
