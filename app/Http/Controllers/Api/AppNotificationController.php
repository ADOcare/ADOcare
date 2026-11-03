<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at');
        $total = (clone $query)->count();
        $notifications = $query
            ->latest('occurred_at')
            ->latest('id')
            ->cursorPaginate(
                min(max($request->integer('per_page', 10), 1), 25),
            );

        return $this->success([
            'notifications' => $notifications->items(),
            'pagination' => [
                'total' => $total,
                'has_more' => $notifications->hasMorePages(),
                'next_cursor' => $notifications->nextCursor()?->encode(),
            ],
        ]);
    }

    public function dismiss(Request $request, AppNotification $notification): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return $this->notFound('Upozornenie nebolo nájdené.');
        }

        if ((bool) data_get($notification->data, 'persistent', false)) {
            return $this->error('Akčné upozornenie zostane aktívne, kým sa problém nevyrieši.', 422);
        }

        $notification->update([
            'dismissed_at' => now(),
        ]);

        return $this->success(null, 'Upozornenie bolo skryté.');
    }
}
