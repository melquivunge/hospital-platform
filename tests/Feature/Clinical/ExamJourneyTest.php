<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\ExamResult;
use App\Models\JobFunction;
use App\Models\Membership;
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
 * Step 5.6: the whole exam journey on a brand-new client, only through the HTTP routes people use (ADR-0008):
 * an urgent order in the emergency room → the laboratory queue → collection → a critical result → the urgent
 * notice, the manager's escalation and the acknowledgement; then an imaging order in the admission → the report by
 * a radiologist with CRM → a rectification; the exam team's access to the record ending with each result. Then the
 * read trail, the audit and the notifications reconciled, with no clinical data where it must not be.
 */
class ExamJourneyTest extends TestCase
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

    /**
     * @return array<int, list<array{string, bool}>> user id => [title, urgent]
     */
    private function inbox(): array
    {
        (new DeliverNotifications)->handle();

        return DB::table('user_notifications')->orderBy('id')->get()->groupBy('user_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [$row->title, (bool) $row->urgent])->all())->all();
    }

    public function test_the_exam_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        Storage::fake('local');
        $this->at('2026-11-20 12:00');

        // 1. Provisioning and structure: a unit with the emergency room and a ward, one bed, a care function.
        $this->artisan('tenant:provision', ['name' => 'Rede Exames', '--admin-email' => 'admin@ex.example', '--admin-name' => 'Admin Ex'])->assertSuccessful();
        $admin = $this->confirmed('admin@ex.example');
        $this->actingAs($admin)->post(route('units.store'), ['name' => 'Hospital Norte', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        $unit = Unit::withoutTenantScope()->sole();
        foreach (['Pronto-socorro', 'Clínica médica'] as $sector) {
            $this->post(route('units.sectors.store', $unit), ['name' => $sector])->assertSessionHasNoErrors();
        }
        $ward = Sector::withoutTenantScope()->where('name', 'Clínica médica')->sole();
        $this->post(route('beds.store', $unit), ['sector_id' => $ward->id, 'room' => '1', 'code' => 'CM-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $bed = Bed::withoutTenantScope()->sole();
        $this->post(route('job-functions.store'), ['name' => 'Assistência', 'attends_patients' => true])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->sole();

        // 2. The exam catalog and the queue targets, by the network admin.
        $this->post(route('exams.store'), ['type' => 'laboratory', 'name' => 'Potássio sérico', 'specimen' => 'Sangue', 'preparation' => '', 'code' => '',
            'analytes' => [['name' => 'Potássio', 'unit' => 'mEq/L', 'reference_min' => '3,5', 'reference_max' => '5,1', 'reference_text' => '']]])->assertSessionHasNoErrors();
        $this->post(route('exams.store'), ['type' => 'imaging', 'name' => 'Tomografia de crânio', 'specimen' => 'Crânio', 'preparation' => '', 'code' => '', 'analytes' => []])->assertSessionHasNoErrors();
        $this->put(route('exams.targets'), ['exam_target_urgent_minutes' => 30, 'exam_target_routine_minutes' => 720])->assertSessionHasNoErrors();
        $exams = DB::table('exams')->pluck('id', 'type');

        // 3. People by invitation: reception, bed management, the laboratory and the unit manager.
        $users = [];
        foreach (['recepcao' => 'reception', 'leitos' => 'bed_management', 'laboratorio' => 'laboratory', 'gestor' => 'unit_manager'] as $key => $role) {
            $this->actingAs($admin)->post(route('people.store'), ['name' => ucfirst($key), 'email' => "{$key}@ex.example", 'role' => $role, 'unit_id' => $unit->id])->assertSessionHasNoErrors();
            $users[$key] = $this->confirmed("{$key}@ex.example");
        }

        // 4. Professionals: the emergency doctor and the radiologist, both with CRM; the radiologist also holds Imaging.
        $staff = [];
        foreach (['medico' => ['Dr. Heitor', '41111', 'Pronto-socorro'], 'radio' => ['Dra. Lia', '45555', 'Clínica médica']] as $key => [$name, $number, $sector]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'CRM', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@ex.example", 'phone' => '', 'cpf' => '',
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => '',
                'sector_ids' => [Sector::withoutTenantScope()->where('name', $sector)->value('id')],
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $staff[$key] = [$professional, $this->confirmed("{$key}@ex.example")];
        }
        [[$doctor, $doctorUser], [, $radiologist]] = [$staff['medico'], $staff['radio']];
        $this->actingAs($admin)->post(route('people.roles.store', Membership::withoutGlobalScopes()->where('user_id', $radiologist->id)->sole()), ['role' => 'imaging', 'unit_id' => $unit->id])->assertSessionHasNoErrors();

        // 5. The emergency room: arrival, triage, the visit, and an urgent potassium signed with the password.
        $this->actingAs($users['recepcao'])->post(route('patients.store'), ['registered_unit_id' => $unit->id, 'name' => 'Benedita Faria Nunes', 'birth_date' => '1948-03-02', 'sex' => 'female'])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->post(route('emergency.arrive', $unit), ['patient_id' => $patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Fraqueza e palpitações.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('clinical-documents.store', $visit), ['file' => UploadedFile::fake()->createWithContent('ecg.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->post(route('exam-orders.visit.store', $visit), ['exam_ids' => [$exams['laboratory']], 'priority' => 'urgent', 'indication' => 'Suspeita de hipercalemia.', 'password' => 'password'])->assertSessionHasNoErrors();
        $labOrder = ExamOrder::withoutTenantScope()->sole();
        $potassium = ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', $labOrder->id)->sole();

        // 6. The laboratory: the queue without a read, late after the network's 30 minutes; the opening reads the record.
        $this->at('2026-11-20 12:40');
        $this->actingAs($users['laboratorio'])->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page
            ->has('orders', 1)->where('orders.0.late', true)->where('orders.0.place', 'Urgência'));
        $this->assertSame(0, DB::table('clinical_reads')->where('user_id', $users['laboratorio']->id)->count());
        $this->get(route('exam-queue.show', $labOrder))->assertInertia(fn (Assert $page) => $page->where('open', true)->has('documents', 1));
        $this->post(route('exam-queue.collect', $potassium))->assertSessionHasNoErrors();
        // Collected: the doctor no longer cancels.
        $this->actingAs($doctorUser)->post(route('exam-orders.cancel', $labOrder), ['reason' => 'Engano.'])->assertSessionHasErrorsIn("cancel{$labOrder->id}", ['reason']);

        // 7. The critical result: 7,1 above the range, signed and marked critical; the laboratory loses the record.
        $this->at('2026-11-20 13:00');
        $analyte = (int) DB::table('exam_analytes')->value('id');
        $this->actingAs($users['laboratorio'])->post(route('exam-results.release', $potassium), ['version' => 1, 'values' => [$analyte => '7,1'], 'report' => 'Amostra sem hemólise.', 'critical' => '1', 'password' => 'password'])->assertSessionHasNoErrors();
        $critical = ExamResult::withoutGlobalScopes()->sole();
        $this->assertSame('high', DB::table('exam_result_values')->value('flag'));
        $this->get(route('exam-queue.show', $labOrder))->assertInertia(fn (Assert $page) => $page->where('open', false)->where('record', [])->where('items.0.results.0.critical', true));
        $this->get(route('clinical-documents.download', DB::table('clinical_documents')->value('id')))->assertForbidden();
        $this->assertSame([$doctorUser->id => [['Resultado crítico de um exame que você pediu: registre a ciência', true]]], $this->inbox());

        // 8. An hour without the acknowledgement: the manager hears it once; then the doctor acknowledges it on the visit.
        $this->at('2026-11-20 14:01');
        $this->artisan('exams:critical')->assertSuccessful();
        $this->artisan('exams:critical')->assertSuccessful();
        $this->assertSame([['Resultado crítico de exame sem ciência há mais de 60 minutos', true]], $this->inbox()[$users['gestor']->id]);
        $this->actingAs($users['gestor'])->get(route('exam-overdue.index'))->assertInertia(fn (Assert $page) => $page->has('critical', 1)->where('critical.0.author', 'Dr. Heitor'));
        $this->actingAs($doctorUser)->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->where('urgentNotifications', 1)
            ->where('examResults.0.versions.0.values.0.flag', 'high')->where('examResults.0.versions.0.canAcknowledge', true));
        $this->post(route('exam-results.acknowledge', $critical))->assertSessionHasNoErrors();
        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->where('urgentNotifications', 0)->where('examResults.0.versions.0.acknowledgedBy', 'Dr. Heitor'));
        $this->assertSame(0, DB::table('user_notifications')->where('urgent', true)->whereNull('read_at')->count());

        // 9. The admission, then a head CT ordered from the ward page.
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();
        $this->actingAs($users['leitos'])->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->post(route('exam-orders.admission.store', $admission), ['exam_ids' => [$exams['imaging']], 'priority' => 'routine', 'indication' => 'Queda no leito.', 'password' => 'password'])->assertSessionHasNoErrors();
        $ctOrder = ExamOrder::withoutTenantScope()->where('admission_id', $admission->id)->sole();
        $ct = ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', $ctOrder->id)->sole();

        // 10. The radiologist: queue by the bed, performance, the report with a PDF, then a rectification.
        $this->at('2026-11-20 16:00');
        $this->actingAs($radiologist)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('orders.0.place', 'Clínica médica · quarto 1 · leito CM-1'));
        $this->get(route('exam-queue.show', $ctOrder))->assertInertia(fn (Assert $page) => $page->where('open', true)->has('examResults', 1));
        $this->post(route('exam-queue.collect', $ct))->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $ct), ['version' => 1, 'report' => 'Sem sangramento intracraniano.', 'file' => UploadedFile::fake()->createWithContent('tc.pdf', self::PDF), 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $ct), ['version' => 2, 'reason' => 'Faltou citar o hematoma subgaleal.', 'report' => 'Sem sangramento intracraniano. Hematoma subgaleal frontal.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->get(route('exam-queue.show', $ctOrder))->assertInertia(fn (Assert $page) => $page->where('open', false)->has('items.0.results', 2));
        // The laboratory never reached the imaging order.
        $this->actingAs($users['laboratorio'])->get(route('exam-queue.show', $ctOrder))->assertForbidden();

        // 11. The doctor reads both results on the ward page: the rectification with its reason, the PDF kept.
        $this->actingAs($doctorUser)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->has('examResults', 2)
            ->where('examResults.0.name', 'Tomografia de crânio')->where('examResults.0.versions.0.reason', 'Faltou citar o hematoma subgaleal.')
            ->where('examResults.0.versions.0.attachment.name', 'tc.pdf')->where('examResults.0.versions.1.version', 1));
        $this->get(route('exam-results.download', ExamResult::withoutGlobalScopes()->where('version', 2)->sole()))->assertOk();
        $this->assertSame([
            ['Resultado crítico de um exame que você pediu: registre a ciência', true],
            ['Um resultado de exame que você pediu foi liberado', false],
            ['Um resultado de exame que você pediu foi retificado', false],
        ], $this->inbox()[$doctorUser->id]);

        // 12. Reconciliation: one exam read per opening, by the team only; ids in the audit and notices, never clinical data.
        $this->assertSame([[$users['laboratorio']->id, $labOrder->id, 2], [$radiologist->id, $ctOrder->id, 2]], DB::table('clinical_reads')->where('reason', 'exam')
            ->selectRaw('user_id, exam_order_id, count(*) as reads')->groupBy('user_id', 'exam_order_id')->orderBy('user_id')->get()
            ->map(fn ($row) => [(int) $row->user_id, (int) $row->exam_order_id, (int) $row->reads])->all());
        $this->assertSame(1, AuditEvent::where('action', 'exam_result.critical_unacknowledged')->count());
        $this->assertSame(3, AuditEvent::where('action', 'exam_result.released')->count());
        $trail = AuditEvent::query()->pluck('context')->toJson().DB::table('user_notifications')->get(['title', 'url'])->toJson();
        foreach (['Benedita', 'Potássio', 'Tomografia', 'hipercalemia', 'hematoma', '7,1', '7.1'] as $clinical) {
            $this->assertStringNotContainsStringIgnoringCase($clinical, $trail);
        }
    }
}
