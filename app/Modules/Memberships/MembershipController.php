<?php

namespace App\Modules\Memberships;

use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\Audit;
use App\Shared\Http\ListRequest;
use App\Shared\Http\ScopedCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class MembershipController
{
    public function index(ListRequest $request): JsonResponse
    {
        $tenant = ResolveTenant::current($request);
        Gate::authorize('tenant-owner', $tenant);
        $cursor = new ScopedCursor($request, $tenant->actor->id, $tenant->restaurant->id);
        $query = $tenant->restaurant->memberships()->with('user')->where('status', 'active');
        if (($role = $request->filter('role')) !== null) {
            $query->where('role', $role);
        }
        $page = $query->orderBy('created_at', $request->direction())->orderBy('id', $request->direction())
            ->cursorPaginate($request->perPage(), cursor: $cursor->current());

        return response()->json([
            'data' => MembershipResource::collection($page->getCollection()),
            'meta' => $cursor->metadata($page),
        ]);
    }

    public function update(MembershipRequest $request, string $restaurant, string $membership, Memberships $memberships): MembershipResource
    {
        $result = ResolveTenant::write($request, fn (Tenant $tenant) => $memberships->change($tenant, $membership, $request->value('role')));
        Audit::record($request, ResolveTenant::current($request)->actor->id, 'membership.role_changed', $result->restaurant_id);

        return new MembershipResource($result);
    }

    public function revoke(Request $request, string $restaurant, string $membership, Memberships $memberships): Response
    {
        $result = ResolveTenant::write($request, fn (Tenant $tenant) => $memberships->change($tenant, $membership, null));
        Audit::record($request, ResolveTenant::current($request)->actor->id, 'membership.revoked', $result->restaurant_id);

        return response()->noContent();
    }

    public function transfer(MembershipRequest $request, Memberships $memberships): MembershipResource
    {
        $result = ResolveTenant::write($request, fn (Tenant $tenant) => $memberships->transfer(
            $tenant, $request->value('membershipId'), $request->value('currentPassword'),
        ));
        Audit::record($request, ResolveTenant::current($request)->actor->id, 'membership.ownership_transferred', $result->restaurant_id);

        return new MembershipResource($result);
    }
}
