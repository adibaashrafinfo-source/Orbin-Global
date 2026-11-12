<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** FR-109 In-app notifications (own user or own role). Polled by the client every 30–60 s. */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $query = Notification::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($r) => $r->whereNull('user_id')->where('role', $user->role)))
            ->orderByDesc('created_at')
            ->limit(50);
        if ($request->boolean('unread')) {
            $query->where('is_read', false);
        }
        $items = $query->get();
        $unread = Notification::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($r) => $r->whereNull('user_id')->where('role', $user->role)))
            ->where('is_read', false)
            ->count();

        return NotificationResource::collection($items)->additional(['meta' => ['unread' => $unread]]);
    }

    public function markRead(Notification $notification): NotificationResource
    {
        Gate::authorize('update', $notification);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return new NotificationResource($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = Notification::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($r) => $r->whereNull('user_id')->where('role', $user->role)))
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['data' => ['updated' => $count]]);
    }
}
