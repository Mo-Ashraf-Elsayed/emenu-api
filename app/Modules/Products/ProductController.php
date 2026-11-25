<?php

namespace App\Modules\Products;

use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\CatalogList;
use App\Shared\Http\ListRequest;
use App\Shared\Http\ScopedCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class ProductController
{
    public function index(ListRequest $request): JsonResponse
    {
        $tenant = ResolveTenant::current($request);
        $cursor = new ScopedCursor($request, $tenant->actor->id, $tenant->restaurant->id);
        $query = $tenant->restaurant->products();
        if (($category = $request->filter('categoryId')) !== null) {
            $query->where('category_id', Products::categoryId($tenant->restaurant, $category));
        }
        if (($available = $request->filter('isAvailable')) !== null) {
            $query->where('is_available', $available === 'true');
        }
        $query->with('media');
        $page = CatalogList::page($query->getQuery(), $request, $cursor);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Product $product) => new ProductResource($product, $tenant->restaurant->currency)),
            'meta' => $cursor->metadata($page),
        ]);
    }

    public function show(Request $request, string $restaurant, string $product): ProductResource
    {
        $tenant = ResolveTenant::current($request);

        return new ProductResource($tenant->restaurant->products()->with('media')->findOrFail($product), $tenant->restaurant->currency);
    }

    public function store(ProductRequest $request, Products $products): JsonResponse
    {
        $resource = ResolveTenant::write($request, function (Tenant $tenant) use ($request, $products): ProductResource {
            Gate::authorize('menu-edit', $tenant);
            $product = $products->save($tenant->restaurant, $tenant->restaurant->products()->make(), $request->changes());

            return new ProductResource($product, $tenant->restaurant->currency);
        });
        $product = $resource->resource;
        assert($product instanceof Product);

        return $resource->response()->setStatusCode(201)
            ->header('Location', route('products.show', ['restaurant' => $product->restaurant_id, 'product' => $product->id], absolute: false));
    }

    public function update(ProductRequest $request, string $restaurant, string $product, Products $products): ProductResource
    {
        return ResolveTenant::write($request, function (Tenant $tenant) use ($request, $product, $products): ProductResource {
            $model = $tenant->restaurant->products()->findOrFail($product);
            if (! $request->routeIs('products.availability')) {
                Gate::authorize('menu-edit', $tenant);
            }

            return new ProductResource($products->save($tenant->restaurant, $model, $request->changes()), $tenant->restaurant->currency);
        });
    }

    public function destroy(Request $request, string $restaurant, string $product, Products $products): Response
    {
        ResolveTenant::write($request, function (Tenant $tenant) use ($product, $products): void {
            $model = $tenant->restaurant->products()->findOrFail($product);
            Gate::authorize('menu-edit', $tenant);
            $products->remove($tenant->restaurant, $model);
        });

        return response()->noContent();
    }
}
