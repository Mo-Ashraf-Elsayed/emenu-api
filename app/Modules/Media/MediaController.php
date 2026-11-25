<?php

namespace App\Modules\Media;

use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\ListRequest;
use App\Shared\Http\ScopedCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController
{
    public function index(ListRequest $request): JsonResponse
    {
        $tenant = ResolveTenant::current($request);
        Gate::authorize('menu-edit', $tenant);
        $cursor = new ScopedCursor($request, $tenant->actor->id, $tenant->restaurant->id);
        $query = $tenant->restaurant->media()->where('status', '<>', 'deleted');
        if (($status = $request->filter('status')) !== null) {
            $query->where('status', $status);
        }
        $page = $query->orderBy('created_at', $request->direction())->orderBy('id', $request->direction())
            ->cursorPaginate($request->perPage(), cursor: $cursor->current());

        return response()->json(['data' => MediaResource::collection($page->getCollection()), 'meta' => $cursor->metadata($page)]);
    }

    public function store(MediaRequest $request, MediaWorkflow $workflow): JsonResponse
    {
        Gate::authorize('menu-edit', ResolveTenant::current($request));
        $bytes = $request->bytes();
        $metadata = $workflow->inspectUpload($bytes);
        $media = ResolveTenant::write($request, function (Tenant $tenant) use ($workflow, $bytes, $metadata): Media {
            Gate::authorize('menu-edit', $tenant);

            return $workflow->stage($tenant->restaurant, $bytes, $metadata);
        });

        MediaWorkflow::enqueue($media);

        return (new MediaResource($media))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $restaurant, string $media): MediaResource
    {
        return new MediaResource($this->find(ResolveTenant::current($request), $media));
    }

    public function variant(Request $request, string $restaurant, string $media, string $variant): StreamedResponse
    {
        return MediaStorage::variant($this->find(ResolveTenant::current($request), $media), $variant);
    }

    public function destroy(Request $request, string $restaurant, string $media, MediaWorkflow $workflow): Response
    {
        $deleted = ResolveTenant::write($request, function (Tenant $tenant) use ($workflow, $media): Media {
            $model = $this->find($tenant, $media);
            $workflow->markDeleted($tenant->restaurant, $model);

            return $model;
        });
        MediaWorkflow::enqueue($deleted);

        return response()->noContent();
    }

    private function find(Tenant $tenant, string $id): Media
    {
        $media = $tenant->restaurant->media()->where('status', '<>', 'deleted')->findOrFail($id);
        Gate::authorize('menu-edit', $tenant);

        return $media;
    }
}
