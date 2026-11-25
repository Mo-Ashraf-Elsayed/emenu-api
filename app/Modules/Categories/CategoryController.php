<?php

namespace App\Modules\Categories;

use App\Modules\PublicMenus\MenuSettings;
use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\ApiProblem;
use App\Shared\Http\CatalogList;
use App\Shared\Http\ListRequest;
use App\Shared\Http\ScopedCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class CategoryController
{
    public function index(ListRequest $request): JsonResponse
    {
        $tenant = ResolveTenant::current($request);
        $cursor = new ScopedCursor($request, $tenant->actor->id, $tenant->restaurant->id);
        $page = CatalogList::page($tenant->restaurant->categories()->getQuery(), $request, $cursor);

        return response()->json(['data' => CategoryResource::collection($page->getCollection()), 'meta' => $cursor->metadata($page)]);
    }

    public function show(Request $request, string $restaurant, string $category): CategoryResource
    {
        return new CategoryResource(ResolveTenant::current($request)->restaurant->categories()->findOrFail($category));
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $model = ResolveTenant::write($request, function (Tenant $tenant) use ($request): Category {
            Gate::authorize('menu-edit', $tenant);
            if ($tenant->restaurant->categories()->count() >= 50) {
                throw new ApiProblem(409, 'CAPACITY_REACHED');
            }
            $model = $tenant->restaurant->categories()->create($request->attributesForDraft());
            MenuSettings::advance($tenant->restaurant);

            return $model;
        });

        return (new CategoryResource($model))->response()->setStatusCode(201)
            ->header('Location', route('categories.show', ['restaurant' => $model->restaurant_id, 'category' => $model->id], absolute: false));
    }

    public function update(CategoryRequest $request, string $restaurant, string $category): CategoryResource
    {
        $model = ResolveTenant::write($request, function (Tenant $tenant) use ($request, $category): Category {
            $model = $tenant->restaurant->categories()->findOrFail($category);
            Gate::authorize('menu-edit', $tenant);
            $model->fill($request->attributesForDraft());
            if ($model->isDirty()) {
                $model->save();
                MenuSettings::advance($tenant->restaurant);
            }

            return $model;
        });

        return new CategoryResource($model);
    }

    public function destroy(Request $request, string $restaurant, string $category): Response
    {
        ResolveTenant::write($request, function (Tenant $tenant) use ($category): void {
            $model = $tenant->restaurant->categories()->findOrFail($category);
            Gate::authorize('menu-edit', $tenant);
            if ($model->products()->exists()) {
                throw new ApiProblem(409, 'RESOURCE_IN_USE');
            }
            $model->delete();
            MenuSettings::advance($tenant->restaurant);
        });

        return response()->noContent();
    }
}
