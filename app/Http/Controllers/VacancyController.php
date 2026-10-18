<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use App\Models\Shift;
use App\Models\ShiftApplication;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Scheduling\ApplicationStatus;
use App\Scheduling\ShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Open vacancies for the professional linked to this access (step 6b, references IMG_0928 to 0932):
 * list, details, apply and withdraw. Selection belongs to the coordinator (ShiftController).
 */
class VacancyController extends Controller
{
    public function index(Request $request): Response
    {
        $professional = self::professional($request);

        $vacancies = self::openFor(Shift::query(), $professional)
            ->where('starts_at', '>', now())
            ->with(['unit:id,name,timezone', 'sector:id,name', 'jobFunction:id,name', 'applications' => fn ($query) => $query->where('professional_id', $professional->id)->latest('id')])
            ->orderBy('starts_at')
            ->limit(200)
            ->get()
            ->map(fn (Shift $shift) => self::vacancy($shift))
            ->values();

        return Inertia::render('vacancies/index', ['professional' => $professional->name, 'vacancies' => $vacancies]);
    }

    public function show(Request $request, Shift $shift): Response
    {
        $professional = self::professional($request);
        $shift->load(['unit:id,name,timezone', 'sector:id,name', 'jobFunction:id,name', 'applications' => fn ($query) => $query->where('professional_id', $professional->id)->latest('id')]);
        $mine = $shift->applications->first();
        $open = self::openFor(Shift::query()->whereKey($shift->id), $professional)->exists();

        // Same visibility as the list, or a vacancy this professional applied to (to see the outcome).
        abort_unless($open || $mine !== null, 404);
        $open = $open && $shift->starts_at->isFuture();

        return Inertia::render('vacancies/show', [
            'vacancy' => self::vacancy($shift),
            'canApply' => $open && $mine?->status !== ApplicationStatus::Active,
            'canWithdraw' => $open && $mine?->status === ApplicationStatus::Active,
        ]);
    }

    public function apply(Request $request, Shift $shift): RedirectResponse
    {
        $shift->apply(self::professional($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Candidatura enviada. O coordenador fará a escolha.']);

        return to_route('vacancies.show', $shift);
    }

    public function withdraw(Request $request, Shift $shift): RedirectResponse
    {
        $shift->withdraw(self::professional($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Remoção da candidatura realizada com sucesso.']);

        return to_route('vacancies.show', $shift);
    }

    /**
     * Open vacancies in units and functions where the professional holds a non-revoked qualification.
     * Full eligibility is checked when applying and again on selection.
     *
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    private static function openFor(Builder $query, Professional $professional): Builder
    {
        return $query
            ->where('status', ShiftStatus::Published)
            ->whereNull('professional_id')
            ->whereExists(fn (QueryBuilder $query) => $query->from('qualifications')
                ->whereColumn('qualifications.unit_id', 'shifts.unit_id')
                ->whereColumn('qualifications.job_function_id', 'shifts.job_function_id')
                ->where('qualifications.professional_id', $professional->id)
                ->where('qualifications.status', '<>', QualificationStatus::Revoked->value));
    }

    private static function professional(Request $request): Professional
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $professional = $membership === null ? null : Professional::query()->where('membership_id', $membership->id)->first();
        abort_if($professional === null, 403);

        return $professional;
    }

    /**
     * @return array<string, mixed>
     */
    private static function vacancy(Shift $shift): array
    {
        $timezone = $shift->unit->timezone ?? 'UTC';
        $start = $shift->starts_at->setTimezone($timezone);
        $end = $shift->ends_at->setTimezone($timezone);
        /** @var ShiftApplication|null $mine */
        $mine = $shift->applications->first();

        return [
            'id' => $shift->id,
            'unit' => $shift->unit->name,
            'date' => $start->toDateString(),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'endDate' => $end->toDateString(),
            'sector' => $shift->sector?->name,
            'function' => $shift->jobFunction?->name,
            // User decision (17/10/2026): day shift starts 07:00–18:59 local, night otherwise.
            'period' => $start->hour >= 7 && $start->hour < 19 ? 'day' : 'night',
            'application' => $mine === null ? null : ['status' => $mine->status->value, 'label' => $mine->status->label()],
        ];
    }
}
