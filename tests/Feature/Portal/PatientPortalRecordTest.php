<?php

namespace Tests\Feature\Portal;

use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamAnalyte;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\ExamResult;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Visit;
use App\Notifications\PortalAccessLink;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.3 (ADR-0012): the patient's signed results (current version only, with values, references and the PDF;
 * a critical one says to see the doctor) and coming appointments, for the patient and the guardian of a linked minor.
 * Each view goes to the access trail; anyone else gets 404 and the staff never gets in.
 */
class PatientPortalRecordTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $slug;

    private ExamOrderItem $item;

    /** @var array<string, ExamAnalyte> */
    private array $analytes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'joana@example.com'])->save();

        // A blood count, ordered in an emergency visit, collected and released with a PDF, then rectified as critical.
        app(CurrentTenant::class)->set($this->patient->tenant);
        $blood = Exam::query()->create(['type' => 'laboratory', 'name' => 'Hemograma', 'specimen' => 'Sangue']);
        $this->analytes['hb'] = $blood->analytes()->create(['name' => 'Hemoglobina', 'unit' => 'g/dL', 'reference_min' => 12, 'reference_max' => 16, 'position' => 1]);
        $this->analytes['hiv'] = $blood->analytes()->create(['name' => 'Anti-HIV', 'reference_text' => 'Não reagente', 'position' => 2]);
        app(CurrentTenant::class)->set(null);
        $lab = Membership::factory()->for($this->unit->tenant)->create();
        $lab->grant(Role::Laboratory, $this->unit);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('exam-orders.visit.store', $visit), ['exam_ids' => [$blood->id], 'priority' => 'routine', 'indication' => 'Febre.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->item = ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', ExamOrder::withoutTenantScope()->sole()->id)->sole();
        $this->actingAs($lab->user)->post(route('exam-queue.collect', $this->item))->assertSessionHasNoErrors();
        $values = [$this->analytes['hb']->id => '13,5', $this->analytes['hiv']->id => 'Não reagente'];
        $this->post(route('exam-results.release', $this->item), ['version' => 1, 'password' => 'password', 'values' => $values, 'file' => UploadedFile::fake()->createWithContent('v1.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $this->item), ['version' => 2, 'password' => 'password', 'reason' => 'Hemoglobina digitada errado.', 'critical' => '1',
            'values' => [$this->analytes['hb']->id => '6,1', $this->analytes['hiv']->id => 'Não reagente'], 'file' => UploadedFile::fake()->createWithContent('v2.pdf', self::PDF)])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();

        $this->activate($this->patient);
    }

    private function activate(Patient $patient): PatientAccount
    {
        $patient->forceFill(['status' => 'complete'])->save();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $patient), ['document_checked' => '1'])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();
        $token = '';
        Notification::assertSentOnDemand(PortalAccessLink::class, function (PortalAccessLink $notification, array $channels, $notifiable) use (&$token, $patient) {
            if ($notifiable->routes['mail'] === $patient->email) {
                $token = basename($notification->url);
            }

            return true;
        });
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $token]), ['password' => 'Senha-forte-123', 'password_confirmation' => 'Senha-forte-123']);
        auth()->guard('patient')->logout();

        return PatientAccount::withoutTenantScope()->where('patient_id', $patient->id)->sole();
    }

    private function as(PatientAccount $account): void
    {
        auth()->guard('web')->logout();
        $this->actingAs($account, 'patient')->withSession(['portal.last_seen' => now()->getTimestamp()]);
    }

    private function joana(): PatientAccount
    {
        return PatientAccount::withoutTenantScope()->where('patient_id', $this->patient->id)->sole();
    }

    /**
     * @return array{0: ExamResult, 1: ExamResult}
     */
    private function versions(): array
    {
        $versions = ExamResult::withoutTenantScope()->orderBy('version')->get();

        return [$versions[0], $versions[1]];
    }

    private function pdf(ExamResult $result): string
    {
        return route('portal.result.pdf', ['portal' => $this->slug, 'result' => $result->id]);
    }

    private function home(): string
    {
        return route('portal.home', ['portal' => $this->slug]);
    }

    public function test_the_patient_sees_the_current_version_with_values_references_and_the_critical_note(): void
    {
        [$first, $current] = $this->versions();
        $this->as($this->joana());

        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->component('portal/home')
            ->has('results', 1)
            ->where('results.0.id', $current->id)
            ->where('results.0.exam', 'Hemograma')
            ->where('results.0.rectified', true)
            ->where('results.0.critical', true)
            ->where('results.0.hasPdf', true)
            ->where('results.0.values.0.name', 'Hemoglobina')
            ->where('results.0.values.0.reference', '12 a 16')
            ->where('results.0.values.0.flag', 'low')
            ->where('results.0.values.1.reference', 'Não reagente'));

        $this->get($this->pdf($current))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        // The replaced version is gone from the portal.
        $this->get($this->pdf($first))->assertNotFound();

        // Each view and download goes to the access trail, as the portal account.
        $reads = DB::table('clinical_reads')->where('reason', 'portal')->get();
        $this->assertCount(2, $reads);
        $this->assertSame([$this->joana()->id], $reads->pluck('patient_account_id')->unique()->values()->all());
        $this->assertSame([null], $reads->pluck('user_id')->unique()->values()->all());
    }

    public function test_a_stranger_gets_404_and_the_staff_never_gets_in(): void
    {
        [, $current] = $this->versions();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Rui Costa', 'birth_date' => '1970-01-01', 'sex' => 'male'])->assertSessionHasNoErrors();
        $rui = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $rui->forceFill(['cpf' => '11144477735', 'email' => 'rui@example.com'])->save();
        $ruiAccount = $this->activate($rui);

        $this->as($ruiAccount);
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('results', []));
        $this->get($this->pdf($current))->assertNotFound();
        auth()->guard('patient')->logout();

        $this->actingAs($this->doctorAccount->user, 'web')->get($this->pdf($current))->assertRedirect(route('portal.login', ['portal' => $this->slug]));
    }

    public function test_the_guardian_sees_a_linked_minors_results(): void
    {
        [, $current] = $this->versions();
        // Joana is now a minor, linked to Rui.
        $this->patient->forceFill(['birth_date' => '2012-01-02'])->save();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Rui Costa', 'birth_date' => '1970-01-01', 'sex' => 'male'])->assertSessionHasNoErrors();
        $rui = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $rui->forceFill(['cpf' => '11144477735', 'email' => 'rui@example.com'])->save();
        $ruiAccount = $this->activate($rui);
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-guardians.store', $this->patient), ['email' => 'rui@example.com', 'document_checked' => '1'])->assertSessionHasNoErrors();

        $this->as($ruiAccount);
        $this->get(route('portal.child', ['portal' => $this->slug, 'patient' => $this->patient->id]))
            ->assertInertia(fn (Assert $page) => $page->component('portal/child')->where('results.0.id', $current->id));
        $this->get($this->pdf($current))->assertOk();
        // Rui's own page shows only Rui's record.
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('results', []));
        $this->assertSame([$this->patient->id], DB::table('clinical_reads')->where('patient_account_id', $ruiAccount->id)->whereNotNull('patient_id')->distinct()->pluck('patient_id')->diff([$rui->id])->values()->all());
    }

    public function test_after_a_merge_the_kept_record_shows_what_was_merged_into_it(): void
    {
        [, $current] = $this->versions();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female', 'confirm_new' => '1'])->assertSessionHasNoErrors();
        $kept = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $joana = $this->joana();
        $previous = app(CurrentTenant::class)->get();
        app(CurrentTenant::class)->set($this->unit->tenant);
        Patient::merge($kept, $this->patient->fresh() ?? $this->patient, 'Cadastro duplicado.');
        app(CurrentTenant::class)->set($previous);

        $this->as($joana);
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('results.0.id', $current->id));
        $this->get($this->pdf($current))->assertOk();
        $this->assertSame([$kept->id], DB::table('clinical_reads')->where('reason', 'portal')->distinct()->pluck('patient_id')->all());
    }

    public function test_coming_appointments_are_listed(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-16T09:00'])->assertSessionHasNoErrors();

        $this->as($this->joana());
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->has('appointments', 1)
            ->where('appointments.0.unit', $this->unit->name)
            ->where('appointments.0.professional', $this->doctor->name));

        // Once it has passed, it is no longer "coming".
        $this->travelTo('2026-11-17 12:00:00');
        $this->as($this->joana());
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('appointments', []));
    }
}
