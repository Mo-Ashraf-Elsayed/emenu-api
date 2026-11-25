<?php

namespace App\Shared\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\CursorPaginator;

final class CatalogList
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return CursorPaginator<int,TModel>
     */
    public static function page(Builder $query, ListRequest $request, ScopedCursor $cursor): CursorPaginator
    {
        $visibility = $request->filter('isVisible');
        if ($visibility !== null) {
            $query->where('is_visible', $visibility === 'true');
        }
        $term = $request->filter('q');
        if ($term !== null) {
            $pattern = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn (Builder $search) => $search->whereRaw("name_en ILIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("name_ar ILIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query->orderBy($request->orderColumn(), $request->direction())
            ->orderBy('id', $request->direction())->cursorPaginate($request->perPage(), cursor: $cursor->current());
    }
}
