<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inbox of the signed-in user in the current client (step 9a).
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = UserNotification::query()
            ->where('user_id', $request->user()?->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (UserNotification $notification) => [
                'id' => $notification->id,
                'title' => $notification->title,
                'url' => $notification->url,
                'read' => $notification->read_at !== null,
                'urgent' => (bool) $notification->getAttribute('urgent'),
                // Items span units with different time zones: the browser shows them in the reader's own.
                'at' => $notification->created_at->toIso8601String(),
            ]);

        return Inertia::render('notifications/index', ['notifications' => $notifications]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        UserNotification::query()->where('user_id', $request->user()?->id)->whereNull('read_at')->update(['read_at' => now()]);

        return to_route('notifications.index');
    }
}
