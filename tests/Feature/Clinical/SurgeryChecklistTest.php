<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\OperatingRoom;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
use App\Models\SurgeryConsent;
use App\Models\SurgicalProcedure;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The WHO safe surgery checklist and the consent term (step 5b.4, ADR-0009): the unit's Surgical center or the team
 * attaches the term (or, when urgent, the reason to go without), then confirms the three steps in order, once each,
 * never changed; the first puts the surgery in progress, which ends cancel and reschedule.
 */
class SurgeryChecklistTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Membership $center;

    private Membership $surgeonAccount;

    private Professional $surgeon;

    private OperatingRoom $room;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $tenant = $this->patient->tenant;
        $this->center = Membership::factory()->for($tenant)->create();
        $this->center->grant(Role::SurgicalCenter, $this->unit);
        $this->surgeonAccount = Membership::factory()->for($tenant)->create();
        $this->surgeonAccount->grant(Role::Professional, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        SurgicalProcedure::query()->create(['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium']);
        $this->room = OperatingRoom::query()->create(['unit_id' => $this->unit->id, 'name' => 'Sala 1']);
        $this->surgeon = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dr. Bruno']);
        $this->surgeon->forceFill(['membership_id' => $this->surgeonAccount->id])->save();
        Qualification::request($this->surgeon, $this->unit, ['job_function_id' => JobFunction::query()->value('id'), 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->unit->id)->value('id')])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dor.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function scheduled(string $urgency = 'elective', string $start = '2026-11-13T10:00'): Surgery
    {
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => SurgicalProcedure::withoutTenantScope()->value('id'), 'laterality' => 'right', 'urgency' => $urgency,
            'desired_date' => '2026-11-13', 'indication' => 'Indicação.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $surgery), array_filter([
            'operating_room_id' => $this->room->id, 'start' => $start, 'duration_minutes' => 60, 'surgeon_id' => $this->surgeon->id,
            'urgent_reason' => $urgency === 'urgent' ? 'Abdome agudo.' : null,
        ]))->assertSessionHasNoErrors();

        return $surgery->fresh();
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function confirm(Surgery $surgery, string $step, array $overrides = []): TestResponse
    {
        $answers = collect(SurgeryChecklistStep::STEPS[$step]['items'])->map(fn (array $item) => $item[1][0])->all();

        return $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => $step]), ['answers' => [...$answers, ...$overrides]]);
    }

    /**
     * @param  list<\Closure(): mixed>  $writes
     */
    private function assertRefused(array $writes): void
    {
        foreach ($writes as $write) {
            try {
                DB::transaction($write);
                $this->fail('The database must refuse this write.');
            } catch (QueryException $e) {
                // TRUNCATE is not even granted to the application role (42501); the trigger refuses the rest (23514).
                $this->assertContains($e->getCode(), ['23514', '42501'], $e->getMessage());
            }
        }
    }

    public function test_the_term_comes_first_then_the_three_steps_in_order_and_the_surgery_starts(): void
    {
        $surgery = $this->scheduled();
        $this->actingAs($this->center->user);
        $this->confirm($surgery, 'sign_in')->assertSessionHasErrorsIn('step-sign_in', ['step' => 'Anexe o termo de consentimento assinado antes da primeira etapa.']);
        $this->post(route('surgery-checklist.waiver', $surgery), ['reason' => 'Sem tempo.'])->assertForbidden();
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', '<html>not a pdf</html>')])
            ->assertSessionHasErrorsIn('consent', ['file' => 'O conteúdo do arquivo não é um PDF válido.']);
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertSessionHasNoErrors();
        // A new term supersedes the previous one, which is kept; up to five.
        for ($i = 2; $i <= 5; $i++) {
            $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent("termo-{$i}.pdf", self::PDF)])->assertSessionHasNoErrors();
        }
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo-6.pdf', self::PDF)])
            ->assertSessionHasErrorsIn('consent', ['file' => 'Esta cirurgia já tem 5 termos registrados.']);
        $this->assertCount(5, Storage::disk('local')->allFiles("clinical/{$surgery->tenant_id}"));
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('surgery.consent.name', 'termo-5.pdf'));
        $consent = SurgeryConsent::withoutTenantScope()->orderBy('id')->firstOrFail();
        $this->assertSame(['document', 'application/pdf', 'termo.pdf'], [$consent->kind, $consent->mime_type, $consent->original_name]);

        $this->confirm($surgery, 'time_out')->assertSessionHasErrorsIn('step-time_out', 'step');
        $this->confirm($surgery, 'sign_in', ['identity' => 'no'])->assertSessionHasErrorsIn('step-sign_in', 'answers.identity');
        $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => 'sign_in']), ['answers' => ['identity' => 'yes']])->assertSessionHasErrorsIn('step-sign_in', 'answers.oximeter');
        $this->assertSame('scheduled', $surgery->fresh()->status);

        $this->confirm($surgery, 'sign_in', ['allergy' => 'yes'])->assertSessionHasNoErrors();
        $surgery->refresh();
        $this->assertSame(['in_progress', '2026-11-13 12:00:00'], [$surgery->status, $surgery->started_at?->utc()->toDateTimeString()]);
        $this->confirm($surgery, 'sign_in')->assertSessionHasErrorsIn('step-sign_in', 'step');

        // In progress: no cancel, no reschedule, no new term; the team goes on.
        $this->post(route('surgery-schedule.cancel', $surgery), ['reason' => 'X'])->assertSessionHasErrorsIn('cancel', 'reason');
        $this->post(route('surgery-schedule.reschedule', $surgery), ['operating_room_id' => $this->room->id, 'start' => '2026-11-14T10:00', 'duration_minutes' => 60, 'reason' => 'X'])->assertSessionHasErrorsIn('reschedule', 'operating_room_id');
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('outro.pdf', self::PDF)])->assertSessionHasErrorsIn('consent', 'file');
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.cancel', $surgery), ['reason' => 'X'])->assertSessionHasErrorsIn("cancel{$surgery->id}", 'reason');

        $this->actingAs($this->surgeonAccount->user);
        $this->confirm($surgery, 'sign_out')->assertSessionHasErrorsIn('step-sign_out', ['step' => 'Confirme a etapa anterior primeiro.']);
        $this->confirm($surgery, 'time_out', ['antibiotic' => 'na'])->assertSessionHasNoErrors();
        $this->confirm($surgery, 'sign_out', ['equipment' => 'no'])->assertSessionHasNoErrors();
        $this->assertSame(['sign_in', 'time_out', 'sign_out'], DB::table('surgery_checklist_steps')->orderBy('id')->pluck('step')->all());
        $signIn = SurgeryChecklistStep::withoutTenantScope()->where('step', 'sign_in')->sole();
        $this->assertSame(['yes', 'yes'], [$signIn->answers['allergy'], $signIn->answers['identity']]);
        $this->assertEquals(['unit_id' => $this->unit->id, 'step' => 'time_out'], AuditEvent::where('action', 'surgery.checklist_confirmed')->where('context->step', 'time_out')->sole()->context);
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page
            ->where('surgery.status', 'in_progress')->where('surgery.checklist.sign_out.by', $this->surgeonAccount->user->name)->where('surgery.consent.kind', 'document'));

        // Never changed, never removed, and only in the right status (database).
        $this->assertRefused([
            fn () => DB::table('surgery_checklist_steps')->update(['answers' => '{}']),
            fn () => DB::table('surgery_checklist_steps')->where('step', 'sign_out')->delete(),
            fn () => DB::statement('TRUNCATE surgery_checklist_steps'),
            fn () => DB::table('surgery_consents')->update(['original_name' => 'x']),
            fn () => DB::statement('TRUNCATE surgery_consents CASCADE'),
            fn () => DB::table('surgery_consents')->insert(['tenant_id' => $surgery->tenant_id, 'surgery_id' => $surgery->id, 'kind' => 'document', 'path' => 'clinical/x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'created_by_user_id' => $this->center->user_id, 'created_at' => now()]),
        ]);
    }

    public function test_an_urgent_surgery_goes_with_a_waiver_and_the_room_stays_taken_while_in_progress(): void
    {
        $surgery = $this->scheduled('urgent', '2026-11-13T08:45');
        $this->actingAs($this->surgeonAccount->user);
        $this->confirm($surgery, 'sign_in')->assertSessionHasErrorsIn('step-sign_in', ['step' => 'Anexe o termo de consentimento ou registre o motivo da urgência sem termo.']);
        $this->post(route('surgery-checklist.waiver', $surgery), ['reason' => ' '])->assertSessionHasErrorsIn('waiver', 'reason');
        $this->post(route('surgery-checklist.waiver', $surgery), ['reason' => 'Paciente inconsciente, sem responsável.'])->assertSessionHasNoErrors();
        $this->confirm($surgery, 'sign_in')->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $surgery->fresh()->status);

        // The database refuses a waiver for an elective, a first step without a term and a step out of order.
        $elective = $this->scheduled('elective', '2026-11-14T10:00');
        $row = fn (Surgery $target, array $values) => ['tenant_id' => $target->tenant_id, 'surgery_id' => $target->id, ...$values];
        $this->assertRefused([
            fn () => DB::table('surgery_consents')->insert($row($elective, ['kind' => 'waiver', 'waiver_reason' => 'X', 'created_by_user_id' => $this->center->user_id, 'created_at' => now()])),
            fn () => DB::table('surgery_checklist_steps')->insert($row($elective, ['step' => 'sign_in', 'answers' => '{}', 'confirmed_by_user_id' => $this->center->user_id, 'confirmed_at' => now()])),
            fn () => DB::table('surgery_checklist_steps')->insert($row($surgery, ['step' => 'sign_out', 'answers' => '{}', 'confirmed_by_user_id' => $this->center->user_id, 'confirmed_at' => now()])),
        ]);

        // The room of a surgery in progress is not booked again.
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => SurgicalProcedure::withoutTenantScope()->value('id'), 'laterality' => 'none', 'urgency' => 'urgent',
            'desired_date' => '2026-11-13', 'indication' => 'Indicação.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $another = Surgery::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $another), [
            'operating_room_id' => $this->room->id, 'start' => '2026-11-13T09:00', 'duration_minutes' => 30, 'surgeon_id' => $this->surgeon->id, 'urgent_reason' => 'Outra urgência.',
        ])->assertSessionHasErrorsIn('schedule', ['start' => 'A sala já está ocupada neste horário. Escolha outro horário ou outra sala.']);
    }

    public function test_only_the_center_of_the_unit_or_the_team_confirms_and_the_term_is_read_with_the_trail(): void
    {
        $surgery = $this->scheduled();
        $this->actingAs($this->center->user)->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertSessionHasNoErrors();
        $consent = SurgeryConsent::withoutTenantScope()->sole();

        $this->actingAs($this->nurseAccount->user);
        $this->confirm($surgery, 'sign_in')->assertForbidden();
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertForbidden();
        $this->get(route('surgery-checklist.consent.download', $consent))->assertForbidden();
        $elsewhere = Membership::factory()->for($this->patient->tenant)->create();
        $elsewhere->grant(Role::SurgicalCenter, $this->other);
        $this->actingAs($elsewhere->user);
        $this->confirm($surgery, 'sign_in')->assertForbidden();
        // The ordering doctor attends but is not on the team: reads the term, does not confirm.
        $this->actingAs($this->doctorAccount->user);
        $this->confirm($surgery, 'sign_in')->assertForbidden();
        $this->get(route('surgery-checklist.consent.download', $consent))->assertOk();
        $this->assertSame('visit', DB::table('clinical_reads')->where('user_id', $this->doctorAccount->user_id)->orderByDesc('id')->value('reason'));

        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-checklist.consent.download', $consent))->assertOk();
        $this->assertSame([['surgery', $surgery->id]], DB::table('clinical_reads')->where('user_id', $this->surgeonAccount->user_id)->get()->map(fn ($row) => [$row->reason, $row->surgery_id])->all());
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('surgery.canConfirm', true)->where('steps.sign_in.label', 'Antes da indução anestésica'));
        // An expired qualification ends the team's right to confirm.
        DB::table('qualifications')->where('professional_id', $this->surgeon->id)->update(['valid_until' => '2026-11-12']);
        $this->confirm($surgery, 'sign_in')->assertForbidden();
    }
}
