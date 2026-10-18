<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotifications;
use App\Models\JobFunction;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\ShiftApplication;
use App\Models\ShiftRequest;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 9c: the whole V1 journey on a brand-new client, only through the HTTP routes people use, then
 * the report, the notifications and the audit trail reconciled against what happened.
 */
class JourneyTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -23.561234;

    private const LNG = -46.655678;

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    /** The emailed password link confirms the address; here the confirmation is applied directly. */
    private function confirmed(string $email): User
    {
        $user = User::where('email', $email)->sole();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_the_whole_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        $this->at('2026-11-01 12:00');

        // 1. Provisioning and structure (network admin).
        $this->artisan('tenant:provision', ['name' => 'Rede Jornada', '--admin-email' => 'admin@jornada.example', '--admin-name' => 'Admin Jornada'])->assertSuccessful();
        $admin = $this->confirmed('admin@jornada.example');
        $this->actingAs($admin)->post(route('units.store'), ['name' => 'Hospital Jornada', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        $unit = Unit::withoutTenantScope()->where('name', 'Hospital Jornada')->sole();
        $this->post(route('units.sectors.store', $unit), ['name' => 'UTI Adulto'])->assertSessionHasNoErrors();
        $this->post(route('job-functions.store'), ['name' => 'Médico plantonista'])->assertSessionHasNoErrors();
        $sector = Sector::withoutTenantScope()->where('unit_id', $unit->id)->sole();
        $function = JobFunction::withoutTenantScope()->where('tenant_id', $unit->tenant_id)->sole();

        // 2. People: coordinator and unit manager by invitation.
        $this->post(route('people.store'), ['name' => 'Coordenação', 'email' => 'coord@jornada.example', 'role' => 'shift_coordinator', 'unit_id' => $unit->id])->assertSessionHasNoErrors();
        $this->post(route('people.store'), ['name' => 'Gestão', 'email' => 'gestao@jornada.example', 'role' => 'unit_manager', 'unit_id' => $unit->id])->assertSessionHasNoErrors();
        $coordinator = $this->confirmed('coord@jornada.example');
        $manager = $this->confirmed('gestao@jornada.example');

        // 3. Professionals, approved qualifications and access by invitation.
        $professionals = [];
        foreach (['ana' => ['Ana Jornada', '11111'], 'bruno' => ['Bruno Jornada', '22222']] as $key => [$name, $number]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'crm', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@jornada.example", 'phone' => '',
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'sector_ids' => [$sector->id], 'valid_from' => '2026-01-01', 'valid_until' => '',
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $professionals[$key] = [$professional, $this->confirmed("{$key}@jornada.example")];
        }
        [[$ana, $anaUser], [$bruno, $brunoUser]] = [$professionals['ana'], $professionals['bruno']];

        // 4. Presence rules (network) and a presence point (unit manager).
        foreach (['attendance_max_accuracy' => 50, 'attendance_check_in_lead' => 30, 'attendance_check_out_grace' => 60] as $key => $value) {
            $this->actingAs($admin)->post(route('policies.publish', $key), ['expected_version' => 0, 'value' => $value, 'delegable' => false, 'reason' => 'Jornada'])->assertSessionHasNoErrors();
        }
        $this->actingAs($manager)->post(route('units.attendance-points.store', $unit), ['name' => 'Portaria', 'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_m' => 100])->assertSessionHasNoErrors();

        // 5. Schedule: Ana's shift and an open vacancy, both published (coordinator).
        $this->actingAs($coordinator);
        foreach ([[$ana->id, '2026-11-10T07:00', '2026-11-10T19:00'], [null, '2026-11-11T07:00', '2026-11-11T19:00']] as [$holder, $start, $end]) {
            $this->post(route('units.shifts.store', $unit), ['sector_id' => $sector->id, 'job_function_id' => $function->id, 'professional_id' => $holder, 'starts_at' => $start, 'ends_at' => $end])->assertSessionHasNoErrors();
            $this->post(route('shifts.publish', Shift::withoutTenantScope()->latest('id')->firstOrFail()))->assertSessionHasNoErrors();
        }
        [$anaShift, $vacancy] = Shift::withoutTenantScope()->orderBy('starts_at')->get()->all();

        // 6. Bruno applies to the vacancy and is chosen with a justification.
        $this->actingAs($brunoUser)->post(route('vacancies.apply', $vacancy))->assertSessionHasNoErrors();
        $this->actingAs($coordinator)->post(route('shift-applications.select', ShiftApplication::withoutTenantScope()->sole()), ['reason' => 'Único candidato'])->assertSessionHasNoErrors();
        $this->assertSame($bruno->id, $vacancy->fresh()->professional_id);

        // 7. Bruno transfers it to Ana: she accepts, the coordinator approves.
        $this->actingAs($brunoUser)->post(route('shifts.transfer.store', $vacancy), ['recipient_id' => $ana->id])->assertSessionHasNoErrors();
        $transfer = ShiftRequest::withoutTenantScope()->sole();
        $this->actingAs($anaUser)->post(route('requests.respond', $transfer), ['accept' => true])->assertSessionHasNoErrors();
        $this->actingAs($coordinator)->post(route('requests.decide', $transfer), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame($ana->id, $vacancy->fresh()->professional_id);

        // 8. Presence: Ana checks in at the point; her exit is adjusted by the coordinator.
        $this->at('2026-11-10 09:50');
        $this->actingAs($anaUser)->post(route('shifts.attendance', $anaShift), [
            'kind' => 'check_in', 'latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 20,
            'measured_at' => now()->getTimestampMs(), 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->at('2026-11-10 22:30');
        $this->post(route('shifts.occurrences.store', $anaShift), ['kind' => 'check_out', 'description' => 'Sem sinal na saída'])->assertSessionHasNoErrors();
        $occurrence = DB::table('attendance_occurrences')->value('id');
        $this->actingAs($coordinator)->post(route('occurrences.decide', $occurrence), ['grant' => true, 'reason' => 'Confirmado com a equipe', 'informed_at' => '2026-11-10T19:05'])->assertSessionHasNoErrors();

        // 9. Notifications reach only the people involved.
        (new DeliverNotifications)->handle();
        $titles = fn (User $user) => DB::table('user_notifications')->where('user_id', $user->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(['Novo plantão publicado para você', 'Você recebeu um pedido de transferência', 'Transferência concluída', 'Sua ocorrência de presença foi decidida'], $titles($anaUser));
        $this->assertSame(['Você foi escolhido para um plantão', 'Transferência concluída'], $titles($brunoUser));
        $this->assertSame(['Nova candidatura a uma vaga', 'Transferência aguardando aprovação', 'Nova ocorrência de presença'], $titles($coordinator));
        $this->assertSame([], $titles($manager));

        // 10. The report reconciles with what happened (unit manager reads it).
        $query = ['unit' => $unit->id, 'from' => '2026-11-01', 'to' => '2026-11-30'];
        $this->actingAs($manager)->get(route('reports.index', $query))->assertInertia(fn (Assert $page) => $page
            ->where('coverage', ['drafts' => 0, 'cancelled' => 0, 'published' => 2, 'filled' => 2, 'announced' => 0, 'open' => 0])
            ->where('requests', ['pending' => 0, 'completed' => 1, 'refused' => 0, 'cancelled' => 0, 'expired' => 0])
            ->where('attendance', ['started' => 1, 'checkedIn' => 1, 'noEntry' => 0, 'checkedOut' => 0, 'adjustedExit' => 1, 'openOccurrences' => 0])
            ->where('hours', [['id' => $ana->id, 'name' => 'Ana Jornada', 'shifts' => 2, 'hours' => 24]]));
        $presence = explode("\n", trim(substr($this->get(route('reports.attendance', ['format' => 'csv', ...$query]))->streamedContent(), 3)));
        $this->assertSame('10/11/2026;07:00;19:00;"UTI Adulto";"Médico plantonista";"Ana Jornada";"10/11/2026 06:50";"10/11/2026 19:05";Sim;0', $presence[1]);

        // 11. The audit trail holds every decision, in this client only.
        $actions = DB::table('audit_events')->where('tenant_id', $unit->tenant_id)->pluck('action')->countBy()->all();
        foreach (['shift.published' => 2, 'shift.application_created' => 1, 'shift.assigned' => 1, 'shift.transfer_requested' => 1, 'shift.transfer_accepted' => 1,
            'shift.transferred' => 1, 'attendance.occurrence_reported' => 1, 'attendance.occurrence_granted' => 1, 'report.exported' => 1] as $action => $count) {
            $this->assertSame($count, $actions[$action] ?? 0, $action);
        }
        $this->assertSame(0, DB::table('audit_events')->whereNotNull('tenant_id')->where('tenant_id', '<>', $unit->tenant_id)->count());

        // 12. The professional never sees management screens.
        $this->actingAs($anaUser)->get(route('reports.index'))->assertForbidden();
        $this->get(route('shifts.index'))->assertForbidden();
    }
}
