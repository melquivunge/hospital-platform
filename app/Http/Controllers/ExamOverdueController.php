<?php

namespace App\Http\Controllers;

use App\Models\ExamOrder;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 5.3b (ADR-0008): the unit manager (and the network admin, who hears when a unit has no manager) sees the
 * orders open with an item not collected for more than 7 days, to chase the team or the doctor who ordered. Not
 * clinical: when, priority, where, who ordered and how many items are missing; never the exams or the record.
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
