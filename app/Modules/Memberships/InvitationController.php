<?php

namespace App\Modules\Memberships;

use App\Modules\Identity\DashboardToken;
use App\Modules\Identity\PrivateMail;
use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\Audit;
use App\Shared\Http\ListRequest;
use App\Shared\Http\ScopedCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class InvitationController
{
    public function index(ListRequest $request): JsonResponse
    {
        $tenant = ResolveTenant::current($request);
        Gate::authorize('tenant-invite', [$tenant, 'staff']);
        $requestTime = now();
        $cursor = new ScopedCursor($request, $tenant->actor->id, $tenant->restaurant->id.':'.$tenant->membership->role);
        $query = $tenant->restaurant->invitations();
        if ($tenant->membership->role === 'manager') {
            $query->where('role', 'staff');
        }
        if (($role = $request->filter('role')) !== null) {
            $query->where('role', $role);
        }
        $status = $request->filter('status');
        if ($status === 'pending') {
            $query->where('status', 'pending')->where('expires_at', '>', $requestTime);
        } elseif ($status === 'expired') {
            $query->where(fn (Builder $filter) => $filter->where('status', 'expired')
                ->orWhere(fn (Builder $pending) => $pending->where('status', 'pending')->where('expires_at', '<=', $requestTime)));
        } elseif ($status !== null) {
            $query->where('status', $status);
        }
        $page = $query->orderBy('created_at', $request->direction())->orderBy('id', $request->direction())
            ->cursorPaginate($request->perPage(), cursor: $cursor->current());

        return response()->json([
            'data' => InvitationResource::collection($page->getCollection()),
            'meta' => $cursor->metadata($page),
        ]);
    }

    public function store(InvitationRequest $request, Invitations $invitations): JsonResponse
    {
        [$model, $job] = ResolveTenant::write($request, fn (Tenant $tenant) => $invitations->create(
            $tenant, $request->value('email'), $request->value('role'),
        ));
        PrivateMail::enqueue($job);
        Audit::record($request, ResolveTenant::current($request)->actor->id, 'invitation.created', $model->restaurant_id);

        return (new InvitationResource($model))->response()->setStatusCode(201);
    }

    public function resend(Request $request, string $restaurant, string $invitation, Invitations $invitations): InvitationResource
    {
        [$model, $job] = ResolveTenant::write($request, fn (Tenant $tenant) => $invitations->resend($tenant, $invitation));
        PrivateMail::enqueue($job);
        Audit::record($request, ResolveTenant::current($request)->actor->id, 'invitation.resent', $model->restaurant_id);

        return new InvitationResource($model);
    }

    public function revoke(Request $request, string $restaurant, string $invitation, Invitations $invitations): Response
    {
        $model = ResolveTenant::write($request, fn (Tenant $tenant) => $invitations->revoke($tenant, $invitation));
        Audit::record($request, ResolveTenant::current($request)->actor->id, 'invitation.revoked', $model->restaurant_id);

        return response()->noContent();
    }

    public function accept(InvitationRequest $request, Invitations $invitations): JsonResponse
    {
        $actor = DashboardToken::user($request);
        $membership = $invitations->accept($actor, $request->value('token'));
        Audit::record($request, $actor->id, 'invitation.accepted', $membership->restaurant_id);

        return response()->json(['data' => [
            'restaurantId' => $membership->restaurant_id,
            'membershipId' => $membership->id,
            'role' => $membership->role,
        ]]);
    }
}
