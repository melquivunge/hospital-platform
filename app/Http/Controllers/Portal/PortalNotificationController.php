<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PatientAccount;
use App\Models\PatientNotification;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7b.3: the portal's inbox. Opening it marks everything read; the unread ones still show as new this time.
 */
class PortalNotificationController extends Controller
{
    private const LIMIT = 50;

    public function __invoke(): Response
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);

        $notices = PatientNotification::query()->where('account_id', $account->id)->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn (PatientNotification $notice) => ['id' => $notice->id, 'title' => $notice->title, 'createdAt' => $notice->created_at->toIso8601String(), 'unread' => $notice->read_at === null])
            ->values();
        PatientNotification::query()->where('account_id', $account->id)->whereNull('read_at')->update(['read_at' => now()]);

        return Inertia::render('portal/notifications', [...PortalHomeController::shell($account), 'notices' => $notices, 'unread' => 0]);
    }

    public static function unread(PatientAccount $account): int
    {
        return PatientNotification::query()->where('account_id', $account->id)->whereNull('read_at')->count();
    }
}
