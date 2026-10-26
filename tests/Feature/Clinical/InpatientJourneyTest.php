<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\ClinicalNote;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4.8: the whole emergency and inpatient journey on a brand-new client, only through the HTTP routes
 * people use: arrival → triage → emergency care → admission → sector change → transfer between units →
 * discharge. Then the record access, the bed map, the census, the read trail, the notifications and the
 * audit reconciled against what happened.
 */
class InpatientJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    /** The emailed password link sets the password and confirms the address; here both are applied directly. */
    private function confirmed(string $email): User
    {
        $user = User::where('email', $email)->sole();
        $user->forceFill(['email_verified_at' => now(), 'password' => 'password'])->save();

        return $user;
    }

    private function bed(Unit $unit, string $sector, string $code): Bed
    {
        $sectorId = Sector::withoutTenantScope()->where('unit_id', $unit->id)->where('name', $sector)->value('id');
        $this->post(route('beds.store', $unit), ['sector_id' => $sectorId, 'room' => '1', 'code' => $code, 'type' => $sector === 'UTI' ? 'icu' : 'ward'])->assertSessionHasNoErrors();

        return Bed::withoutTenantScope()->where('unit_id', $unit->id)->where('code', $code)->sole();
    }

    public function test_the_inpatient_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        $this->at('2026-11-13 12:00');

        // 1. Provisioning and structure (network admin): two units, sectors, beds, a function.
        $this->artisan('tenant:provision', ['name' => 'Rede Internação', '--admin-email' => 'admin@int.example', '--admin-name' => 'Admin Int'])->assertSuccessful();
        $admin = $this->confirmed('admin@int.example');
        foreach (['Hospital Norte', 'Hospital Sul'] as $name) {
            $this->actingAs($admin)->post(route('units.store'), ['name' => $name, 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        }
        [$north, $south] = Unit::withoutTenantScope()->orderBy('id')->get()->all();
        foreach ([[$north, ['Pronto-socorro', 'Clínica médica', 'UTI']], [$south, ['Clínica médica']]] as [$unit, $sectors]) {
            foreach ($sectors as $sector) {
                $this->post(route('units.sectors.store', $unit), ['name' => $sector])->assertSessionHasNoErrors();
            }
        }
        $wardBed = $this->bed($north, 'Clínica médica', 'CM-1');
        $icuBed = $this->bed($north, 'UTI', 'UTI-1');
        $southBed = $this->bed($south, 'Clínica médica', 'S-1');
        $this->post(route('job-functions.store'), ['name' => 'Assistência'])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->where('tenant_id', $north->tenant_id)->sole();

        // 2. People by invitation: reception, bed management of both units, housekeeping, management, auditor.
        $invites = [
            'recepcao' => ['reception', $north], 'leitos' => ['bed_management', $north], 'leitossul' => ['bed_management', $south],
            'higiene' => ['housekeeping', $north], 'gestao' => ['unit_manager', $north], 'auditoria' => ['auditor', null],
        ];
        $users = [];
        foreach ($invites as $key => [$role, $unit]) {
            $this->actingAs($admin)->post(route('people.store'), array_filter(['name' => ucfirst($key), 'email' => "{$key}@int.example", 'role' => $role, 'unit_id' => $unit?->id]))->assertSessionHasNoErrors();
            $users[$key] = $this->confirmed("{$key}@int.example");
        }

        // 3. Professionals with approved qualifications per sector and access.
        $staff = [];
        foreach ([
            'medico' => ['Dr. Paulo', $north, 'Pronto-socorro', '31111'], 'enfcm' => ['Enf. Rita', $north, 'Clínica médica', '32222'],
            'enfuti' => ['Enf. Caio', $north, 'UTI', '33333'], 'medicosul' => ['Dra. Lia', $south, 'Clínica médica', '34444'],
        ] as $key => [$name, $unit, $sector, $number]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'crm', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@int.example", 'phone' => '',
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => '',
                'sector_ids' => [Sector::withoutTenantScope()->where('unit_id', $unit->id)->where('name', $sector)->value('id')],
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $staff[$key] = [$professional, $this->confirmed("{$key}@int.example")];
        }
        [[$doctor, $doctorUser], [, $wardNurse], [, $icuNurse], [$southDoctor, $southDoctorUser]] = [$staff['medico'], $staff['enfcm'], $staff['enfuti'], $staff['medicosul']];

        // 4. Arrival at the emergency desk, triage, emergency care with a signed note, admission request.
        $this->actingAs($users['recepcao'])->post(route('patients.store'), ['registered_unit_id' => $north->id, 'name' => 'Otávio Mendes Rocha', 'birth_date' => '1960-07-08', 'sex' => 'male', 'cpf' => '52998224725'])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->post(route('emergency.arrive', $north), ['patient_id' => $patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia súbita.', 'systolic' => 150, 'diastolic' => 90, 'heart_rate' => 118, 'temperature' => 37.2, 'spo2' => 88])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Suspeita de tromboembolismo pulmonar.'])->assertSessionHasNoErrors();
        $this->post(route('clinical-notes.sign', ClinicalNote::withoutTenantScope()->sole()), ['body' => 'Suspeita de tromboembolismo pulmonar.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $wardBed->sector_id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();

        // 5. Bed management allocates the ward bed; the ward team writes, the ICU team does not read.
        $this->actingAs($users['leitos'])->post(route('admissions.allocate', $admission), ['bed_id' => $wardBed->id])->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->get(route('ward.show', $admission))->assertOk();
        $this->actingAs($wardNurse)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->has('record', 1));
        $this->post(route('ward.notes.store', $admission), ['body' => 'Dessaturação noturna.'])->assertSessionHasNoErrors();
        $wardNote = ClinicalNote::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('clinical-notes.sign', $wardNote), ['body' => 'Dessaturação noturna.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($icuNurse)->get(route('ward.show', $admission))->assertForbidden();
        foreach (['recepcao', 'leitos', 'higiene', 'gestao', 'auditoria'] as $key) {
            $this->actingAs($users[$key])->get(route('ward.show', $admission))->assertForbidden();
        }

        // 6. Sector change to the ICU: access follows the bed; the previous bed is cleaned by housekeeping.
        $this->at('2026-11-14 12:00');
        $this->actingAs($users['leitos'])->post(route('admissions.move', $admission), ['bed_id' => $icuBed->id])->assertSessionHasNoErrors();
        $this->actingAs($wardNurse)->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($icuNurse)->get(route('ward.show', $admission))->assertOk();
        $this->assertSame('cleaning', $wardBed->fresh()->status);
        $this->actingAs($users['higiene'])->post(route('beds.clean', $wardBed))->assertSessionHasNoErrors();

        // 7. Transfer to the south unit: request, accept, departure, reception.
        $this->at('2026-11-15 12:00');
        $this->actingAs($doctorUser)->post(route('transfers.store', $admission), ['to_unit_id' => $south->id, 'requested_sector_id' => $southBed->sector_id, 'responsible_professional_id' => $southDoctor->id])->assertSessionHasNoErrors();
        $transfer = AdmissionTransfer::withoutTenantScope()->sole();
        $this->actingAs($users['leitossul'])->post(route('transfers.accept', $transfer), ['bed_id' => $southBed->id])->assertSessionHasNoErrors();
        $this->actingAs($users['leitos'])->post(route('transfers.depart', $transfer))->assertSessionHasNoErrors();
        $this->actingAs($users['leitossul'])->post(route('transfers.receive', $transfer))->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($icuNurse)->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($southDoctorUser)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->has('record', 2)->has('history', 4));

        // 8. Discharge with the signed summary at the south unit; access ends, the bed goes to cleaning.
        $this->at('2026-11-16 15:00');
        $this->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Anticoagulado, estável em ar ambiente.', 'password' => 'password'])->assertSessionHasNoErrors();
        $admission = $admission->fresh();
        $this->assertSame(['discharged', $south->id, $southBed->id], [$admission->status, $admission->unit_id, $admission->bed_id]);
        $this->get(route('ward.show', $admission))->assertForbidden();

        // 9. Bed map and census agree, unit by unit, with numbers only.
        $expected = [$north->id => ['free' => 1, 'occupied' => 0, 'cleaning' => 1], $south->id => ['free' => 0, 'occupied' => 0, 'cleaning' => 1]];
        foreach ([[$north, $users['leitos']], [$south, $users['leitossul']]] as [$unit, $viewer]) {
            $map = null;
            $this->actingAs($viewer)->get(route('beds.index', ['unit' => $unit->id]))->assertInertia(function (Assert $page) use (&$map) {
                $map = $page->toArray()['props']['counts'];
            });
            $this->assertSame($expected[$unit->id], array_intersect_key($map, $expected[$unit->id]));
            $this->get(route('census.index', ['unit' => $unit->id]))->assertDontSee('Otávio')->assertInertia(fn (Assert $page) => $page
                ->where('total.free', $map['free'])->where('total.occupied', $map['occupied'])->where('total.cleaning', $map['cleaning'])
                ->where('total.blocked', $map['blocked'])->where('total.reserved', $map['reserved']));
        }
        // The stay counts once, at the unit of discharge: admitted on the 13th, discharged on the 16th.
        $this->get(route('census.index', ['unit' => $south->id]))->assertInertia(fn (Assert $page) => $page
            ->where('total.discharges', 1)->where('total.meanStayDays', 3.1));

        // 10. Reconciliation: read trail, notifications without patient data, audit without clinical text.
        $readers = DB::table('clinical_reads')->where('reason', 'admission')->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(collect([$doctorUser->id, $wardNurse->id, $icuNurse->id, $southDoctorUser->id])->sort()->values()->all(), $readers);
        $this->actingAs($users['auditoria'])->get(route('clinical-access.index'))->assertOk();

        (new DeliverNotifications)->handle();
        $titles = (string) DB::table('user_notifications')->get()->toJson();
        $this->assertGreaterThan(0, DB::table('user_notifications')->where('user_id', $users['leitossul']->id)->count());
        foreach (['Otávio', 'Dispneia', 'tromboembolismo'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $titles, "Notification carries \"{$sensitive}\"");
        }

        $audit = (string) json_encode(AuditEvent::where('tenant_id', $north->tenant_id)->get(['action', 'context']));
        foreach (['Dispneia', 'tromboembolismo', 'Dessaturação', 'Anticoagulado', '52998224725', 'Otávio', 'improved'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $audit, "Audit carries \"{$sensitive}\"");
        }
        foreach (['admission.requested', 'admission.admitted', 'admission.moved', 'admission.discharged', 'clinical_note.signed'] as $action) {
            $this->assertTrue(AuditEvent::where('tenant_id', $north->tenant_id)->where('action', $action)->exists(), "Missing audit event {$action}");
        }
        $this->assertTrue(AuditEvent::where('tenant_id', $north->tenant_id)->where('action', 'like', 'transfer.%')->count() >= 4);
    }
}
