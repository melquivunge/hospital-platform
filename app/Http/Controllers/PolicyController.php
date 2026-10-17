<?php

namespace App\Http\Controllers;

use App\Governance\PolicyKey;
use App\Http\Requests\Tenancy\PublishPolicyRequest;
use App\Models\PolicyVersion;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rule catalog: network value and limits, and the value in force at each unit (step 2c).
 */
class PolicyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PolicyVersion::class);

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $tenantWide = $membership->hasRole(Role::NetworkAdmin) || $membership->hasRole(Role::Auditor);

        $units = Unit::query()->visibleTo($membership)->orderBy('name')->get()
            ->filter(fn (Unit $unit) => $tenantWide || $membership->hasRole(Role::UnitManager, $unit->id));

        // ponytail: ~3 queries per key and unit; load all current rows once if the catalog or networks grow.
        $policies = collect(PolicyKey::cases())->map(function (PolicyKey $key) use ($units) {
            $network = PolicyVersion::current($key, null);
            $network?->load('author:id,name');
            [$min, $max] = $key->bounds();

            return [
                'key' => $key->value,
                'label' => $key->label(),
                'description' => $key->description(),
                'unit' => $key->unit(),
                'bounds' => ['min' => $min, 'max' => $max],
                'network' => $network === null ? null : [
                    ...$network->only('version', 'value', 'delegable', 'reason'),
                    'minValue' => $network->min_value,
                    'maxValue' => $network->max_value,
                    'effectiveFrom' => $network->effective_from->toIso8601String(),
                    'author' => $network->author?->name,
                ],
                'units' => $units->map(function (Unit $unit) use ($key) {
                    $effective = PolicyVersion::effective($key, $unit);
                    $local = PolicyVersion::current($key, $unit->id);

                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'value' => $effective?->value,
                        'origin' => $effective?->origin(),
                        'localValue' => $local?->value,
                        'localIgnored' => $effective !== null && $effective->localIgnored,
                        'localVersion' => $local->version ?? 0,
                        'canPublish' => Gate::allows('publishLocal', [PolicyVersion::class, $unit]),
                    ];
                })->values(),
            ];
        });

        return Inertia::render('policies/index', [
            'policies' => $policies,
            'canPublishNetwork' => Gate::allows('publishNetwork', PolicyVersion::class),
        ]);
    }

    public function publishNetwork(PublishPolicyRequest $request, PolicyKey $key): RedirectResponse
    {
        PolicyVersion::publish($key, app(CurrentTenant::class)->require()->id, null, $request->integer('expected_version'), [
            'value' => $request->integer('value'),
            'delegable' => $request->boolean('delegable'),
            'min_value' => self::optionalInteger($request, 'min_value'),
            'max_value' => self::optionalInteger($request, 'max_value'),
            'reason' => $request->string('reason')->value(),
            'confirm_incompatible' => $request->boolean('confirm_incompatible'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Regra da rede publicada.']);

        return to_route('policies.index');
    }

    public function publishLocal(PublishPolicyRequest $request, Unit $unit, PolicyKey $key): RedirectResponse
    {
        PolicyVersion::publish($key, $unit->tenant_id, $unit, $request->integer('expected_version'), [
            'value' => self::optionalInteger($request, 'value'),
            'reason' => $request->string('reason')->value(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Regra de {$unit->name} publicada."]);

        return to_route('policies.index');
    }

    /**
     * Empty field means "no value" (no limit, or back to the inherited value). Already validated as integer.
     */
    private static function optionalInteger(PublishPolicyRequest $request, string $field): ?int
    {
        return $request->filled($field) ? $request->integer($field) : null;
    }
}
