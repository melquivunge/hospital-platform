<?php

namespace App\Http\Controllers;

use App\Models\ExamCriticalAlert;
use App\Models\ExamOrder;
use App\Models\ExamResult;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 5.3b (ADR-0008): the unit manager (and the network admin, who hears when a unit has no manager) sees the
 * orders open with an item not collected for more than 7 days, to chase the team or the doctor who ordered; since
 * step 5.5, also the critical results nobody acknowledged, to call the doctor. Not clinical: when, priority, where,
 * who ordered and how many items are missing; never the exams, the values or the record.
 */
class ExamOverdueController extends Controller
{
    // ponytail: oldest first and capped; paginate if a unit ever piles up more.
    private const LIMIT = 200;

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds($request->user()))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';

        $orders = ExamOrder::overdue()->where('unit_id', $unit->id)
            ->withCount(['items as pending' => fn ($items) => $items->whereNull('collected_at')])
            ->orderBy('created_at')->limit(self::LIMIT)->get();
        $places = ExamQueueController::places($orders);
        $authors = User::query()->whereKey($orders->pluck('author_user_id')->unique()->all())->pluck('name', 'id');

        $alerts = ExamCriticalAlert::query()->where('unit_id', $unit->id)->whereNull('acknowledged_at')->orderBy('created_at')->limit(self::LIMIT)->get();
        $orderOf = ExamResult::query()->whereKey($alerts->pluck('exam_result_id')->all())->pluck('exam_order_id', 'id');
        $critical = ExamOrder::query()->whereKey($orderOf->unique()->all())->get()->keyBy('id');
        $criticalPlaces = ExamQueueController::places($critical);
        $authors = $authors->union(User::query()->whereKey($critical->pluck('author_user_id')->unique()->all())->pluck('name', 'id'));

        return Inertia::render('exam-overdue/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'days' => ExamOrder::OVERDUE_DAYS,
            'priorities' => ExamOrder::PRIORITIES,
            'orders' => $orders->map(fn (ExamOrder $order) => [
                'id' => $order->id,
                'createdAt' => $order->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                'days' => (int) $order->created_at->diffInDays(now()),
                'priority' => $order->priority,
                'place' => $places[$order->id],
                'author' => $authors->get($order->author_user_id),
                'pending' => (int) $order->getAttribute('pending'),
            ])->values(),
            'critical' => $alerts->map(function (ExamCriticalAlert $alert) use ($critical, $orderOf, $criticalPlaces, $authors, $timezone) {
                $order = $critical[$orderOf[$alert->exam_result_id]];

                return [
                    'id' => $alert->id,
                    'releasedAt' => $alert->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                    'minutes' => (int) $alert->created_at->diffInMinutes(now()),
                    'escalated' => $alert->escalated_at !== null,
                    'place' => $criticalPlaces[$order->id],
                    'author' => $authors->get($order->author_user_id),
                ];
            })->values(),
        ]);
    }

    /**
     * The units whose overdue orders the user sees: every unit for the network admin, otherwise the managed ones.
     *
     * @return list<int>
     */
    public static function unitIds(User $user): array
    {
        $membership = UnitPolicy::currentMembership($user);
        if ($membership?->hasRole(Role::NetworkAdmin) ?? false) {
            return array_values(array_map('intval', Unit::query()->pluck('id')->all()));
        }

        return StockInventoryController::managerUnitIds($user);
    }
}
