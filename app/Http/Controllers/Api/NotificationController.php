<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::where('user_id', auth()->id())
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest();

        return response()->json([
            ...$query->paginate($this->perPage())->toArray(),
            'unread_count' => AppNotification::where('user_id', auth()->id())->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json(['count' => AppNotification::where('user_id', auth()->id())->whereNull('read_at')->count()]);
    }

    public function markRead(AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === auth()->id(), 404);
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => $notification]);
    }

    public function markAllRead(): JsonResponse
    {
        AppNotification::where('user_id', auth()->id())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All caught up.']);
    }
}
