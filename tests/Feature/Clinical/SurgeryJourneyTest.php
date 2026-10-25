<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\StockLot;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
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
 * Step 5b.7: the whole surgery journey on a brand-new client, only through the HTTP routes people use (ADR-0009):
 * the catalog, rooms and materials → an elective surgery ordered from the admission → the agenda and the team → the
 * consent term → the three checklist steps → the materials used and one given back → the signed description, which
 * completes it and ends the record access; then an urgent surgery without a term, with its reason, also completed. The read trail,
 * the audit, the notifications and the stock reconciled, with no clinical data where it must not be.
 */
class SurgeryJourneyTest extends TestCase
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

    private function confirmStep(Surgery $surgery, string $step): void
    {
        $answers = collect(SurgeryChecklistStep::STEPS[$step]['items'])->map(fn (array $item) => $item[1][0])->all();
        $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => $step]), ['answers' => $answers])->assertSessionHasNoErrors();
    }

    public function test_the_surgery_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        Storage::fake('local');
        $this->at('2026-11-20 12:00');

        // 1. Provisioning and structure: a unit with the emergency room and a ward, one bed, a care function.
        $this->artisan('tenant:provision', ['name' => 'Rede Cirurgia', '--admin-email' => 'admin@cx.example', '--admin-name' => 'Admin Cx'])->assertSuccessful();
        $admin = $this->confirmed('admin@cx.example');
        $this->actingAs($admin)->post(route('units.store'), ['name' => 'Hospital Norte', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        $unit = Unit::withoutTenantScope()->sole();
        foreach (['Pronto-socorro', 'Clínica cirúrgica'] as $sector) {
            $this->post(route('units.sectors.store', $unit), ['name' => $sector])->assertSessionHasNoErrors();
        }
        $ward = Sector::withoutTenantScope()->where('name', 'Clínica cirúrgica')->sole();
        $this->post(route('beds.store', $unit), ['sector_id' => $ward->id, 'room' => '2', 'code' => 'CC-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $bed = Bed::withoutTenantScope()->sole();
        $this->post(route('job-functions.store'), ['name' => 'Assistência', 'attends_patients' => true])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->sole();

        // 2. The surgical catalog by the network admin: a procedure, a room and a material.
        $this->post(route('surgical-procedures.store'), ['name' => 'Colecistectomia videolaparoscópica', 'duration_minutes' => 90, 'size' => 'medium', 'code' => '31005497'])->assertSessionHasNoErrors();
        $this->post(route('operating-rooms.store'), ['unit_id' => $unit->id, 'name' => 'Sala 1'])->assertSessionHasNoErrors();
        $this->post(route('materials.store'), ['name' => 'Clipe de titânio', 'stock_unit' => 'unidade'])->assertSessionHasNoErrors();
        [$procedure, $room, $clip] = [DB::table('surgical_procedures')->value('id'), DB::table('operating_rooms')->value('id'), DB::table('materials')->value('id')];

        // 3. People by invitation: reception, bed management, the surgical center and the pharmacy.
        $users = [];
        foreach (['recepcao' => 'reception', 'leitos' => 'bed_management', 'centro' => 'surgical_center', 'farmacia' => 'pharmacy'] as $key => $role) {
            $this->actingAs($admin)->post(route('people.store'), ['name' => ucfirst($key), 'email' => "{$key}@cx.example", 'role' => $role, 'unit_id' => $unit->id])->assertSessionHasNoErrors();
            $users[$key] = $this->confirmed("{$key}@cx.example");
        }

        // 4. Professionals with CRM, qualified in the unit: the emergency doctor and the surgeon.
        $staff = [];
        foreach (['medico' => ['Dr. Heitor', '41111', 'Pronto-socorro'], 'cirurgia' => ['Dra. Íris', '47777', 'Clínica cirúrgica']] as $key => [$name, $number, $sector]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'CRM', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@cx.example", 'phone' => '', 'cpf' => '',
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => '',
                'sector_ids' => [Sector::withoutTenantScope()->where('name', $sector)->value('id')],
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $staff[$key] = [$professional, $this->confirmed("{$key}@cx.example")];
        }
        [[$doctor, $doctorUser], [$surgeon, $surgeonUser]] = [$staff['medico'], $staff['cirurgia']];

        // 5. The emergency room and the admission to the surgical ward.
        $this->actingAs($users['recepcao'])->post(route('patients.store'), ['registered_unit_id' => $unit->id, 'name' => 'Rosalina Prado Gomes', 'birth_date' => '1961-07-09', 'sex' => 'female'])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->post(route('emergency.arrive', $unit), ['patient_id' => $patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Dor em hipocôndrio direito.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();
        $this->actingAs($users['leitos'])->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();

        // 6. The pharmacy takes in a lot of clips.
        $this->actingAs($users['farmacia'])->post(route('stock.entries.store'), ['unit_id' => $unit->id, 'material_id' => $clip, 'lot_code' => 'TI-9', 'expires_on' => '2028-01-31', 'quantity' => 40])->assertSessionHasNoErrors();
        $lot = StockLot::withoutTenantScope()->sole();

        // 7. The elective surgery, ordered from the ward page and signed with the password.
        $this->actingAs($doctorUser)->post(route('surgery-orders.admission.store', $admission), [
            'surgical_procedure_id' => $procedure, 'laterality' => 'none', 'urgency' => 'elective', 'desired_date' => '2026-11-23',
            'indication' => 'Colelitíase sintomática.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->sole();

        // 8. The surgical center sees it requested, books the room with the surgeon and adds the doctor as assistant.
        $this->actingAs($users['centro'])->get(route('surgery-schedule.index', ['date' => '2026-11-23']))->assertInertia(fn (Assert $page) => $page->has('requested', 1));
        $this->post(route('surgery-schedule.schedule', $surgery), ['operating_room_id' => $room, 'start' => '2026-11-23T08:00', 'duration_minutes' => 90, 'surgeon_id' => $surgeon->id])->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $doctor->id, 'role' => 'assistant'])->assertSessionHasNoErrors();
        $this->get(route('surgery-schedule.index', ['date' => '2026-11-23']))->assertInertia(fn (Assert $page) => $page->has('requested', 0)->where('rooms.0.surgeries.0.status', 'scheduled'));
        // The scheduled surgeon finds it and reads the record for it.
        $this->actingAs($surgeonUser)->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 1));
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('open', true));

        // 9. The day of the surgery: the term, then the three steps by the center; the surgery is in progress.
        $this->at('2026-11-23 11:00');
        $this->actingAs($users['centro'])->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('open', true));
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->confirmStep($surgery, 'sign_in');
        $this->assertSame('in_progress', $surgery->fresh()->status);
        $this->confirmStep($surgery, 'time_out');
        $this->confirmStep($surgery, 'sign_out');

        // 10. The materials used: six clips, one given back.
        $this->post(route('surgery-materials.store', $surgery), ['stock_lot_id' => $lot->id, 'quantity' => 6])->assertSessionHasNoErrors();
        $this->post(route('surgery-materials.return', [$surgery, $lot]), ['quantity' => 1])->assertSessionHasNoErrors();
        $this->assertSame(35, $lot->fresh()->balance);

        // 11. The surgeon signs the description: the surgery is completed and the record closes for the center and the team.
        $this->actingAs($surgeonUser)->post(route('surgery-description.store', $surgery), [
            'findings' => 'Vesícula com cálculos, paredes espessadas.', 'technique' => 'Videolaparoscopia com quatro portais.', 'complications' => 'Nenhuma.',
            'specimens' => 'Vesícula biliar.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', $surgery->fresh()->status);
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('open', false)->where('surgery.canAddendum', true)->where('surgery.indication', null));
        $this->actingAs($users['centro'])->get(route('surgery-record.show', $surgery))->assertForbidden();
        // The center still corrects the materials for the addendum's days; the doctor reads the description on the ward.
        $this->get(route('surgery-schedule.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('canUseMaterials', true)->where('materials.used.0.taken', 6)->where('materials.used.0.returned', 1));
        $this->actingAs($doctorUser)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->has('surgeryDescriptions', 1)
            ->where('surgeryDescriptions.0.procedure', 'Colecistectomia videolaparoscópica')->where('surgeryDescriptions.0.author', 'Dra. Íris'));

        // 12. An urgent surgery the same day: booked at once with the reason, started with the reason for no term.
        $this->post(route('surgery-orders.admission.store', $admission), [
            'surgical_procedure_id' => $procedure, 'laterality' => 'none', 'urgency' => 'urgent', 'desired_date' => '2026-11-23',
            'indication' => 'Sangramento pós-operatório.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $urgent = Surgery::withoutTenantScope()->where('urgency', 'urgent')->sole();
        $this->actingAs($users['centro'])->post(route('surgery-schedule.schedule', $urgent), [
            'operating_room_id' => $room, 'start' => '2026-11-23T08:00', 'duration_minutes' => 60, 'surgeon_id' => $surgeon->id, 'urgent_reason' => 'Instabilidade hemodinâmica.',
        ])->assertSessionHasNoErrors();
        $this->post(route('surgery-checklist.waiver', $urgent), ['reason' => 'Paciente sedada, sem responsável presente.'])->assertSessionHasNoErrors();
        $this->confirmStep($urgent, 'sign_in');
        $this->assertSame('in_progress', $urgent->fresh()->status);
        $this->confirmStep($urgent, 'time_out');
        $this->confirmStep($urgent, 'sign_out');
        $this->actingAs($surgeonUser)->get(route('surgery-record.show', $urgent))->assertInertia(fn (Assert $page) => $page
            ->where('surgery.urgentReason', 'Instabilidade hemodinâmica.')->where('surgery.consent.waiverReason', 'Paciente sedada, sem responsável presente.'));
        $this->post(route('surgery-description.store', $urgent), ['findings' => 'Sangramento em leito hepático.', 'technique' => 'Revisão de hemostasia.', 'complications' => 'Nenhuma.', 'password' => 'password'])->assertSessionHasNoErrors();
        // Completed: the restricted view keeps neither reason (free texts that may be clinical).
        $this->get(route('surgery-record.show', $urgent))->assertInertia(fn (Assert $page) => $page->where('open', false)
            ->where('surgery.urgentReason', null)->where('surgery.consent.waiverReason', null)->where('surgery.consent.kind', 'waiver'));

        // 13. The pharmacy's movement report shows the surgery movements by type only.
        $this->actingAs($users['farmacia'])->get(route('stock.movements.index', ['from' => '2026-11-20', 'to' => '2026-11-23']))->assertInertia(fn (Assert $page) => $page
            ->where('summary.0.surgery', -6)->where('summary.0.surgery_return', 1)->where('summary.0.entry', 40)
            ->where('movements.0.type', 'Estorno de cirurgia')->where('movements.0.reason', '')
            ->where('movements.0', fn ($row) => array_keys($row->all()) === ['id', 'at', 'medication', 'controlled', 'lot', 'expiresOn', 'type', 'in', 'out', 'reason', 'user']));

        // 14. Reconciliation: surgery reads by the center and the team only; the doctor hears of the completion; ids in
        // the audit and the notices, never clinical data.
        $this->assertEqualsCanonicalizing([$users['centro']->id, $surgeonUser->id], DB::table('clinical_reads')->where('reason', 'surgery')->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        (new DeliverNotifications)->handle();
        $this->assertContains('Uma cirurgia que você pediu foi concluída', DB::table('user_notifications')->where('user_id', $doctorUser->id)->pluck('title')->all());
        $this->assertSame(2, AuditEvent::where('action', 'surgery.completed')->count());
        $this->assertSame([-6, 1], AuditEvent::whereIn('action', ['stock.surgery_consumption', 'stock.surgery_return'])->orderBy('id')->get()
            ->map(fn (AuditEvent $event) => $event->action === 'stock.surgery_consumption' ? -$event->context['quantity'] : $event->context['quantity'])->all());
        $trail = AuditEvent::query()->pluck('context')->toJson().DB::table('user_notifications')->get(['title', 'url'])->toJson();
        foreach (['Rosalina', 'Colecistectomia', 'Colelitíase', 'Vesícula', 'Sangramento', 'sedada', 'hemodinâmica', 'hipocôndrio', 'hepático', 'hemostasia'] as $clinical) {
            $this->assertStringNotContainsStringIgnoringCase($clinical, $trail);
        }
        foreach (DB::table('stock_lots')->get() as $each) {
            $this->assertSame((int) DB::table('stock_movements')->where('stock_lot_id', $each->id)->sum('quantity'), $each->balance);
        }
    }
}
