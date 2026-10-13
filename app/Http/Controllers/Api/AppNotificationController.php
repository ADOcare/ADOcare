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
        $notifications = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->latest('occurred_at')
            ->get();

        return $this->success([
            'notifications' => $notifications,
        ]);
    }

    public function dismiss(Request $request, AppNotification $notification): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return $this->notFound('Upozornenie nebolo nájdené.');
        }

        $notification->update([
            'dismissed_at' => now(),
        ]);

        return $this->success(null, 'Upozornenie bolo skryté.');
    }
}
