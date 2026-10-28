<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\ClinicalNote;
use App\Models\Insurer;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 3.8: the whole outpatient journey on a brand-new client, only through the HTTP routes people use,
 * then the record, the read trail, the notifications and the audit reconciled against what happened.
 */
class OutpatientJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

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

    public function test_the_outpatient_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        Storage::fake('local');
        $this->at('2026-11-13 12:00');

        // 1. Provisioning and structure (network admin): units, sector, function, insurer.
        $this->artisan('tenant:provision', ['name' => 'Rede Ambulatório', '--admin-email' => 'admin@amb.example', '--admin-name' => 'Admin Amb'])->assertSuccessful();
        $admin = $this->confirmed('admin@amb.example');
        foreach (['Clínica Centro', 'Pronto Atendimento Sul'] as $name) {
            $this->actingAs($admin)->post(route('units.store'), ['name' => $name, 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        }
        [$clinic, $emergencyUnit] = Unit::withoutTenantScope()->orderBy('id')->get()->all();
        foreach ([$clinic, $emergencyUnit] as $unit) {
            $this->post(route('units.sectors.store', $unit), ['name' => 'Consultórios'])->assertSessionHasNoErrors();
        }
        $this->post(route('job-functions.store'), ['name' => 'Médico assistente'])->assertSessionHasNoErrors();
        $this->post(route('insurers.store'), ['name' => 'Saúde Jornada', 'ans_code' => '123456'])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->where('tenant_id', $clinic->tenant_id)->sole();
        $insurer = Insurer::withoutTenantScope()->sole();

        // 2. People by invitation: reception, unit managers and auditor.
        $this->post(route('people.store'), ['name' => 'Recepção', 'email' => 'recepcao@amb.example', 'role' => 'reception', 'unit_id' => $clinic->id])->assertSessionHasNoErrors();
        $this->post(route('people.store'), ['name' => 'Gestão', 'email' => 'gestao@amb.example', 'role' => 'unit_manager', 'unit_id' => $clinic->id])->assertSessionHasNoErrors();
        $this->post(route('people.store'), ['name' => 'Gestão Sul', 'email' => 'gestaosul@amb.example', 'role' => 'unit_manager', 'unit_id' => $emergencyUnit->id])->assertSessionHasNoErrors();
        $this->post(route('people.store'), ['name' => 'Auditoria', 'email' => 'auditoria@amb.example', 'role' => 'auditor'])->assertSessionHasNoErrors();
        $reception = $this->confirmed('recepcao@amb.example');
        $manager = $this->confirmed('gestao@amb.example');
        $southManager = $this->confirmed('gestaosul@amb.example');
        $auditor = $this->confirmed('auditoria@amb.example');

        // 3. Two professionals with approved qualifications and access: the doctor in the clinic, the nurse in the south unit.
        $people = [];
        foreach (['doutora' => ['Dra. Clara', $clinic, '11111'], 'enfermeira' => ['Enf. Dora', $emergencyUnit, '22222']] as $key => [$name, $unit, $number]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'crm', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@amb.example", 'phone' => '',
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $sector = Sector::withoutTenantScope()->where('unit_id', $unit->id)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'sector_ids' => [$sector->id], 'valid_from' => '2026-01-01', 'valid_until' => '',
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $people[$key] = [$professional, $this->confirmed("{$key}@amb.example")];
        }
        [[$doctor, $doctorUser], [$nurse, $nurseUser]] = [$people['doutora'], $people['enfermeira']];

        // 4. Schedule grid (unit manager): Mondays 08:00–12:00, 20 minutes.
        $this->actingAs($manager)->post(route('schedules.rules.store', $clinic), ['professional_id' => $doctor->id, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00', 'slot_minutes' => 20])->assertSessionHasNoErrors();

        // 5. Reception: a provisional registration without documents, then a near duplicate merged by the manager.
        $this->actingAs($reception)->post(route('patients.store'), ['registered_unit_id' => $clinic->id, 'name' => 'Helena Souza Lima'])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->assertSame('provisional', $patient->status);
        $this->put(route('patients.update', $patient), ['registered_unit_id' => $clinic->id, 'name' => 'Helena Souza Lima', 'birth_date' => '1985-03-04', 'sex' => 'female', 'cpf' => '52998224725'])->assertSessionHasNoErrors();
        $this->assertSame('complete', $patient->fresh()->status);
        $this->post(route('patients.store'), ['registered_unit_id' => $clinic->id, 'name' => 'Helena Lima', 'birth_date' => '1985-03-04', 'confirm_new' => true])->assertSessionHasNoErrors();
        $duplicate = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($manager)->post(route('patient-merges.store'), ['a_id' => $patient->id, 'b_id' => $duplicate->id, 'primary_id' => $patient->id, 'reason' => 'Mesma pessoa, mesmo nascimento'])->assertSessionHasNoErrors();

        // 6. Booking with the duplicate id lands on the primary; then a reschedule.
        $this->actingAs($reception)->post(route('appointments.store', $clinic), ['professional_id' => $doctor->id, 'patient_id' => $duplicate->id, 'starts_at' => '2026-11-16T08:20'])->assertSessionHasNoErrors();
        $first = Appointment::withoutTenantScope()->sole();
        $this->assertSame($patient->id, $first->patient_id);
        $this->post(route('appointments.store', $clinic), ['professional_id' => $doctor->id, 'patient_id' => $patient->id, 'starts_at' => '2026-11-16T09:00', 'rescheduled_from_id' => $first->id, 'reason' => 'Pedido da paciente'])->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->where('status', 'scheduled')->sole();

        // 7. Monday: arrival by insurance at the desk, the visit, a signed note with an addendum, a document.
        $this->at('2026-11-16 11:50');
        $this->post(route('appointments.arrive', $appointment), ['modality' => 'insurance', 'insurer_id' => $insurer->id, 'insurance_card_number' => '0099 8877', 'insurance_card_valid_until' => '2027-06-30'])->assertSessionHasNoErrors();
        $this->get(route('appointments.queue'))->assertInertia(fn (Assert $page) => $page->where('appointments.1.status', 'arrived')->where('appointments.1.insurer', 'Saúde Jornada'));
        $this->actingAs($doctorUser)->post(route('visits.open', $appointment))->assertRedirect();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Cefaleia tensional. Orientada.'])->assertSessionHasNoErrors();
        $note = ClinicalNote::withoutTenantScope()->sole();
        $this->post(route('clinical-notes.sign', $note), ['body' => 'Cefaleia tensional. Orientada.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Adendo: retorno em 30 dias.', 'addendum_of_id' => $note->id])->assertSessionHasNoErrors();
        $addendum = ClinicalNote::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('clinical-notes.sign', $addendum), ['body' => 'Adendo: retorno em 30 dias.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('clinical-documents.store', $visit), ['file' => UploadedFile::fake()->createWithContent('Exame.pdf', self::PDF)])->assertSessionHasNoErrors();
        $document = ClinicalDocument::withoutTenantScope()->sole();
        $this->get(route('clinical-documents.download', $document))->assertOk();
        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->has('record', 1)->has('record.0.addenda', 1)->has('documents', 1));
        $this->post(route('visits.close', $visit), ['cid_principal' => 'J18.9'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $appointment->fresh()->status);

        // 8. Neither reception nor management read the record; the nurse in the other unit reads it in an emergency.
        $this->actingAs($reception)->get(route('clinical-record.show', $patient))->assertForbidden();
        $this->actingAs($manager)->get(route('clinical-record.show', $patient))->assertForbidden();
        $this->actingAs($nurseUser)->post(route('clinical-record.emergency.store', $patient), ['unit_id' => $emergencyUnit->id, 'justification' => 'Paciente chegou desacordada ao pronto atendimento.'])->assertSessionHasNoErrors();
        $this->get(route('clinical-record.show', $patient))->assertInertia(fn (Assert $page) => $page->component('clinical/record')
            ->where('record.0.body', 'Cefaleia tensional. Orientada.')->has('documents', 1));

        // 9. Reconciliation: read trail, notifications, access log and audit without clinical text.
        $reads = DB::table('clinical_reads')->orderBy('id')->get(['user_id', 'reason']);
        $this->assertSame([['visit', $doctorUser->id], ['visit', $doctorUser->id], ['emergency', $nurseUser->id]], $reads->map(fn ($read) => [$read->reason, (int) $read->user_id])->all());
        (new DeliverNotifications)->handle();
        $this->assertTrue(DB::table('user_notifications')->where('user_id', $southManager->id)->where('title', 'Acesso de emergência a um prontuário')->exists());
        $this->assertFalse(DB::table('user_notifications')->where('user_id', $manager->id)->where('title', 'Acesso de emergência a um prontuário')->exists());
        $this->actingAs($auditor)->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page->has('emergencies', 1)->has('reads', 3));

        $audit = (string) json_encode(AuditEvent::where('tenant_id', $clinic->tenant_id)->get(['action', 'context']));
        foreach (['Cefaleia', 'retorno em 30', '0099 8877', 'desacordada', '52998224725', 'Helena'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $audit, "Audit carries \"{$sensitive}\"");
        }
        foreach (['patient.created', 'patient.updated', 'patient.merged', 'appointment.created', 'appointment.cancelled', 'appointment.arrived', 'visit.opened', 'clinical_note.signed', 'clinical_document.attached', 'clinical_document.downloaded', 'visit.closed', 'clinical.emergency_access'] as $action) {
            $this->assertTrue(AuditEvent::where('tenant_id', $clinic->tenant_id)->where('action', $action)->exists(), "Missing audit event {$action}");
        }
    }
}
