<?php

namespace Tests\Feature\Clinical;

use App\Http\Controllers\StockController;
use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Material;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\OperatingRoom;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\StockLot;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
use App\Models\SurgeryDescription;
use App\Models\SurgeryTeamMember;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The surgical description (step 5b.5, ADR-0009): a surgeon of the team signs it with the password after the three
 * checklist steps, which completes the surgery and tells who ordered it; the record closes for the center and the
 * team, the surgeons sign addenda for 30 days, and nothing signed changes. Step 5b.6c: the surgical center records the
 * materials the surgery used and gives back what it did not.
 */
class SurgeryDescriptionTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const DESCRIPTION = ['findings' => 'Apêndice inflamado.', 'technique' => 'Videolaparoscopia.', 'complications' => 'Nenhuma.', 'specimens' => 'Apêndice.', 'password' => 'password'];

    private Membership $center;

    private Membership $surgeonAccount;

    private Membership $assistantAccount;

    private Professional $surgeon;

    private Professional $assistant;

    private Visit $visit;

    private Membership $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $tenant = $this->patient->tenant;
        $this->center = Membership::factory()->for($tenant)->create();
        $this->center->grant(Role::SurgicalCenter, $this->unit);
        $this->surgeonAccount = Membership::factory()->for($tenant)->create();
        $this->surgeonAccount->grant(Role::Professional, $this->unit);
        $this->assistantAccount = Membership::factory()->for($tenant)->create();
        $this->assistantAccount->grant(Role::Professional, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        SurgicalProcedure::query()->create(['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium']);
        OperatingRoom::query()->create(['unit_id' => $this->unit->id, 'name' => 'Sala 1']);
        $this->surgeon = $this->qualified($this->surgeonAccount, 'Dr. Bruno');
        $this->assistant = $this->qualified($this->assistantAccount, 'Dra. Carla');
        app(CurrentTenant::class)->set(null);
        // A professional of another client, created before the first request.
        $this->stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->stranger->grant(Role::Professional, Unit::factory()->for($this->stranger->tenant)->create());

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dor.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function qualified(Membership $account, string $name): Professional
    {
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => $name]);
        $professional->forceFill(['membership_id' => $account->id])->save();
        Qualification::request($professional, $this->unit, ['job_function_id' => JobFunction::query()->value('id'), 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->unit->id)->value('id')])
            ->transitionTo(QualificationStatus::Approved, null);

        return $professional;
    }

    /**
     * Ordered by the doctor, scheduled with the surgeon and an assistant, the term attached and the steps confirmed.
     */
    private function started(int $steps = 3): Surgery
    {
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => SurgicalProcedure::withoutTenantScope()->value('id'), 'laterality' => 'right', 'urgency' => 'elective',
            'desired_date' => '2026-11-13', 'indication' => 'Indicação.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $surgery), [
            'operating_room_id' => OperatingRoom::withoutTenantScope()->value('id'), 'start' => '2026-11-13T10:00', 'duration_minutes' => 60, 'surgeon_id' => $this->surgeon->id,
        ])->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->assistant->id, 'role' => 'assistant'])->assertSessionHasNoErrors();
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertSessionHasNoErrors();
        foreach (array_slice(array_keys(SurgeryChecklistStep::STEPS), 0, $steps) as $step) {
            $answers = collect(SurgeryChecklistStep::STEPS[$step]['items'])->map(fn (array $item) => $item[1][0])->all();
            $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => $step]), ['answers' => $answers])->assertSessionHasNoErrors();
        }

        return $surgery->fresh();
    }

    public function test_a_surgeon_of_the_team_signs_after_the_three_steps_and_the_surgery_is_completed(): void
    {
        $surgery = $this->started(2);
        // Not the center, not the assistant: only a surgeon of the team.
        $this->actingAs($this->center->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertForbidden();
        $this->actingAs($this->assistantAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertForbidden();
        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)
            ->assertSessionHasErrorsIn('description', ['description' => 'Confirme as três etapas do checklist antes de assinar a descrição.']);
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('surgery.canDescribe', false)->where('surgery.description', null));

        $answers = collect(SurgeryChecklistStep::STEPS['sign_out']['items'])->map(fn (array $item) => $item[1][0])->all();
        $this->actingAs($this->center->user)->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => 'sign_out']), ['answers' => $answers])->assertSessionHasNoErrors();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('surgery.canDescribe', true));
        $this->post(route('surgery-description.store', $surgery), [...self::DESCRIPTION, 'password' => 'wrong'])
            ->assertSessionHasErrorsIn('description', ['password' => 'Senha incorreta. A descrição não foi assinada.']);
        $this->post(route('surgery-description.store', $surgery), [...self::DESCRIPTION, 'findings' => ' '])->assertSessionHasErrorsIn('description', ['findings']);
        $this->assertSame('in_progress', $surgery->fresh()->status);

        $this->post(route('surgery-description.store', $surgery), [...self::DESCRIPTION, 'specimens' => ' '])->assertSessionHasNoErrors();
        $surgery->refresh();
        $this->assertSame('completed', $surgery->status);
        $this->assertNotNull($surgery->completed_at);
        $description = SurgeryDescription::withoutTenantScope()->sole();
        $this->assertSame(['Apêndice inflamado.', null, $this->surgeonAccount->user->id, $this->surgeon->id, 'password'],
            [$description->findings, $description->specimens, $description->author_user_id, $description->professional_id, $description->signature_method]);
        $this->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertSessionHasErrorsIn('description', ['description' => 'A descrição desta cirurgia já foi assinada.']);
        $this->assertSame(1, SurgeryDescription::withoutTenantScope()->count());

        // Ids only in the audit; the author hears of it with nothing clinical in the text.
        $event = AuditEvent::where('action', 'surgery.completed')->sole();
        $this->assertEquals(['unit_id' => $this->unit->id, 'author_user_id' => $this->doctorAccount->user->id, 'patient_id' => $this->patient->id, 'signer_user_id' => $this->surgeonAccount->user->id], $event->context);
        $this->assertSame(1, AuditEvent::where('action', 'surgery.description_sign_failed')->count());
        (new DeliverNotifications)->handle();
        $this->assertSame([[$this->doctorAccount->user->id, 'Uma cirurgia que você pediu foi concluída', "/patients/{$this->patient->id}/record"]],
            DB::table('user_notifications')->get()->map(fn ($row) => [$row->user_id, $row->title, $row->url])->all());

        // The description is in the record of who attends.
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))
            ->assertInertia(fn (Assert $page) => $page->has('surgeryDescriptions', 1)->where('surgeryDescriptions.0.technique', 'Videolaparoscopia.')->where('surgeryDescriptions.0.addenda', []));
    }

    public function test_the_completed_surgery_is_billed_with_the_visit_when_it_closes(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $table = PriceTable::query()->create(['name' => 'Particular', 'private' => true]);
        $billable = BillingItem::query()->create(['kind' => 'procedure', 'surgical_procedure_id' => SurgicalProcedure::query()->value('id'), 'name' => 'Apendicectomia videolaparoscópica', 'code' => '31003079']);
        PriceEntry::query()->create(['price_table_id' => $table->id, 'billing_item_id' => $billable->id, 'price_cents' => 450000, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $surgery = $this->started();
        // In progress: closing the visit bills the consultation only.
        $this->actingAs($this->doctorAccount->user)->post(route('visits.close', $this->visit), ['cid_principal' => 'J18.9', 'outcome' => 'discharged'])->assertSessionHasNoErrors();
        $this->assertSame(['visit'], BillingAccountItem::withoutTenantScope()->pluck('origin_type')->all());

        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertSessionHasNoErrors();
        // The completion bills it at once.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(0, BillingAccount::query()->sole()->generate());
        $billed = BillingAccountItem::query()->where('origin_type', 'surgery')->sole();
        $this->assertSame([$surgery->id, '31003079', 450000, '2026-11-13'], [$billed->origin_id, $billed->code, $billed->unit_price_cents, $billed->performed_on->toDateString()]);
    }

    public function test_a_surgery_left_in_progress_past_its_window_is_still_described_without_the_record(): void
    {
        $surgery = $this->started();
        $this->travel(Surgery::IN_PROGRESS_ACCESS_DAYS + 1)->days();
        // The record is closed for the center and the team; the surgeon keeps the restricted view to sign the description.
        $this->actingAs($this->center->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->actingAs($this->assistantAccount->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 1));
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('open', false)->where('record', [])
            ->where('surgery.indication', null)->where('surgery.canDescribe', true));
        $this->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertSessionHasNoErrors();
        $this->assertSame('completed', $surgery->fresh()->status);
    }

    public function test_after_the_completion_the_record_closes_and_the_surgeons_sign_addenda_for_30_days(): void
    {
        $surgery = $this->started();
        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertSessionHasNoErrors();

        // The center and the assistant lose the record; the surgeon keeps the surgery, without the record.
        $this->actingAs($this->center->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->actingAs($this->assistantAccount->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->post(route('surgery-description.addendum', $surgery), ['body' => 'Correção.', 'password' => 'password'])->assertForbidden();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page
            ->where('open', false)->where('record', [])->where('allergies', [])->where('prescriptions', null)->where('examResults', [])
            ->where('surgery.status', 'completed')->where('surgery.canAddendum', true)->where('surgery.canConfirm', false)
            ->where('surgery.description.findings', 'Apêndice inflamado.'));
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 1)->where('surgeries.0.status', 'completed')->where('canUseMySurgeries', true));

        $this->post(route('surgery-description.addendum', $surgery), ['body' => 'Correção.', 'password' => 'wrong'])
            ->assertSessionHasErrorsIn('addendum', ['password' => 'Senha incorreta. O adendo não foi assinado.']);
        $this->post(route('surgery-description.addendum', $surgery), ['body' => 'Dreno retirado no fim.', 'password' => 'password'])->assertSessionHasNoErrors();
        $original = SurgeryDescription::withoutTenantScope()->whereNull('addendum_of_id')->sole();
        $this->assertSame(['Apêndice inflamado.', 'Dreno retirado no fim.'], [$original->fresh()->findings, SurgeryDescription::withoutTenantScope()->where('addendum_of_id', $original->id)->sole()->body]);
        $this->assertSame(1, AuditEvent::where('action', 'surgery.description_addendum')->count());
        (new DeliverNotifications)->handle();
        $this->assertSame(['Uma cirurgia que você pediu foi concluída', 'A descrição de uma cirurgia que você pediu recebeu um adendo'], DB::table('user_notifications')->orderBy('id')->pluck('title')->all());
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))
            ->assertInertia(fn (Assert $page) => $page->where('surgeryDescriptions.0.addenda.0.body', 'Dreno retirado no fim.')
                ->where('surgeryDescriptions.0.author', $this->surgeonAccount->user->name)->where('surgeryDescriptions.0.addenda.0.author', $this->surgeonAccount->user->name));

        // Past 30 days, the surgery leaves the surgeon too.
        $this->travel(SurgeryDescription::ADDENDUM_DAYS + 1)->days();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->post(route('surgery-description.addendum', $surgery), ['body' => 'Tarde.', 'password' => 'password'])->assertForbidden();
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 0));
    }

    public function test_only_a_surgeon_still_on_the_team_and_qualified_signs_and_the_addenda_have_a_bound(): void
    {
        $surgery = $this->started();
        // Another client does not even find the surgery.
        $this->actingAs($this->stranger->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertNotFound();
        $this->post(route('surgery-description.addendum', $surgery), ['body' => 'Fora.', 'password' => 'password'])->assertNotFound();
        // Before the completion there is no addendum.
        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-description.addendum', $surgery), ['body' => 'Cedo.', 'password' => 'password'])->assertForbidden();
        // A surgeon taken off the team no longer signs; the one who stays does.
        $this->actingAs($this->center->user)->post(route('surgery-schedule.team.remove', SurgeryTeamMember::withoutTenantScope()->where('surgery_id', $surgery->id)->whereNull('removed_at')->where('professional_id', $this->assistant->id)->sole()), ['reason' => 'Vira cirurgiã.'])->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->assistant->id, 'role' => 'surgeon'])->assertSessionHasNoErrors();
        $member = SurgeryTeamMember::withoutTenantScope()->where('surgery_id', $surgery->id)->whereNull('removed_at')->where('professional_id', $this->surgeon->id)->sole();
        $this->post(route('surgery-schedule.team.remove', $member), ['reason' => 'Troca.'])->assertSessionHasNoErrors();
        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertForbidden();
        // A lapsed qualification ends the signature too.
        DB::table('qualifications')->where('professional_id', $this->assistant->id)->update(['valid_until' => '2026-11-12']);
        $this->actingAs($this->assistantAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertForbidden();
        DB::table('qualifications')->where('professional_id', $this->assistant->id)->update(['valid_until' => null]);
        $this->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertSessionHasNoErrors();
        $this->assertSame($this->assistantAccount->user->id, SurgeryDescription::withoutTenantScope()->sole()->author_user_id);

        // At most MAX_ADDENDA addenda.
        app(CurrentTenant::class)->set($this->patient->tenant);
        $original = SurgeryDescription::query()->sole();
        foreach (range(1, SurgeryDescription::MAX_ADDENDA) as $n) {
            $original->addenda()->create(['tenant_id' => $original->tenant_id, 'surgery_id' => $surgery->id, 'body' => "Adendo $n.", 'author_user_id' => $this->assistantAccount->user->id,
                'professional_id' => $this->assistant->id, 'signature_method' => 'password', 'signed_at' => now()]);
        }
        $this->post(route('surgery-description.addendum', $surgery), ['body' => 'Mais um.', 'password' => 'password'])
            ->assertSessionHasErrorsIn('addendum', ['body' => 'Esta descrição já tem '.SurgeryDescription::MAX_ADDENDA.' adendos.']);
    }

    public function test_the_database_keeps_the_description_as_signed(): void
    {
        $surgery = $this->started(2);
        $row = fn (array $values = []) => fn () => DB::table('surgery_descriptions')->insert([
            'tenant_id' => $surgery->tenant_id, 'surgery_id' => $surgery->id, 'findings' => 'A.', 'technique' => 'B.', 'complications' => 'C.',
            'author_user_id' => $this->surgeonAccount->user->id, 'professional_id' => $this->surgeon->id, 'signature_method' => 'password', 'signed_at' => now(), ...$values,
        ]);
        $addendum = fn (int $of) => $row(['findings' => null, 'technique' => null, 'complications' => null, 'body' => 'Adendo.', 'addendum_of_id' => $of]);
        app(CurrentTenant::class)->set($this->patient->tenant);
        // Before the last checklist step, no description.
        $this->assertRefused([$row()]);
        // Nor a completed surgery without one.
        $this->assertRefused([fn () => DB::table('surgeries')->where('id', $surgery->id)->update(['status' => 'completed', 'completed_at' => now()])]);

        $answers = collect(SurgeryChecklistStep::STEPS['sign_out']['items'])->map(fn (array $item) => $item[1][0])->all();
        $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => 'sign_out']), ['answers' => $answers])->assertSessionHasNoErrors();
        DB::transaction($row());
        $original = (int) DB::table('surgery_descriptions')->value('id');
        // No addendum while the surgery is not completed; one description per surgery.
        $this->assertRefused([$addendum($original)]);
        try {
            DB::transaction($row());
            $this->fail('A second description must be refused.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode(), $e->getMessage());
        }
        // A completed surgery has its completion time.
        $this->assertRefused([fn () => DB::table('surgeries')->where('id', $surgery->id)->update(['status' => 'completed'])]);
        DB::table('surgeries')->where('id', $surgery->id)->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertRefused([
            fn () => DB::table('surgery_descriptions')->where('id', $original)->update(['findings' => 'Outro.']),
            fn () => DB::table('surgery_descriptions')->where('id', $original)->delete(),
            fn () => DB::statement('TRUNCATE surgery_descriptions'),
            // An addendum has only its text.
            $row(['body' => 'Adendo.', 'addendum_of_id' => $original]),
            // The completed surgery stays as it was.
            fn () => DB::table('surgeries')->where('id', $surgery->id)->update(['status' => 'in_progress', 'completed_at' => null]),
            fn () => DB::table('surgeries')->where('id', $surgery->id)->update(['indication' => 'Outra.']),
            fn () => DB::table('surgeries')->where('id', $surgery->id)->delete(),
        ]);
        DB::transaction($addendum($original));
        $this->assertSame(2, DB::table('surgery_descriptions')->count());
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
                // TRUNCATE is not even granted to the application role (42501); checks and the trigger refuse the rest, never
                // the row policy.
                $this->assertSame(str_contains($e->getMessage(), 'TRUNCATE') ? '42501' : '23514', $e->getCode(), $e->getMessage());
            }
        }
    }

    public function test_the_surgical_center_records_the_materials_used_and_gives_back_what_was_not(): void
    {
        app(CurrentTenant::class)->set($this->patient->tenant);
        $gauze = Material::query()->create(['name' => 'Compressa cirúrgica', 'stock_unit' => 'pacote']);
        $enter = fn (int $unitId, $item, string $code, string $expiresOn) => DB::transaction(fn () => StockController::enter($unitId, $item, $code, $expiresOn, null, 20, $this->center->user))->stock_lot_id;
        $g1 = $enter($this->unit->id, $gauze, 'G1', '2027-06-30');
        $old = $enter($this->unit->id, $gauze, 'OLD', '2026-01-31');
        $soon = $enter($this->unit->id, $gauze, 'SOON', '2026-11-15');
        $elsewhere = $enter($this->other->id, $gauze, 'G1', '2027-06-30');
        $drug = $this->stockLot($this->unit->id, Medication::query()->create(['active_ingredient' => 'Cefazolina', 'form' => 'Frasco-ampola', 'strength' => '1 g', 'dose_unit' => 'g', 'routes' => ['iv']]), 20, 'C1', '2027-06-30')->id;
        app(CurrentTenant::class)->set(null);
        $surgery = $this->started();
        $use = fn (int $lot, int $quantity) => $this->post(route('surgery-materials.store', $surgery), ['stock_lot_id' => $lot, 'quantity' => $quantity]);

        // Only the surgical center of the unit: not the surgeon, not the center of another unit, not another client.
        $elsewhereCenter = Membership::factory()->for($this->patient->tenant)->create();
        $elsewhereCenter->grant(Role::SurgicalCenter, $this->other);
        $this->actingAs($elsewhereCenter->user)->post(route('surgery-materials.store', $surgery), ['stock_lot_id' => $g1, 'quantity' => 1])->assertForbidden();
        $this->actingAs($this->stranger->user)->post(route('surgery-materials.store', $surgery), ['stock_lot_id' => $g1, 'quantity' => 1])->assertNotFound();
        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-materials.store', $surgery), ['stock_lot_id' => $g1, 'quantity' => 1])->assertForbidden();
        $this->actingAs($this->center->user)->get(route('surgery-schedule.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('canUseMaterials', true)
            ->where('materials.used', [])->where('materials.lots', fn ($lots) => collect($lots)->pluck('id')->all() === [$soon, $g1]));
        $use($g1, 5)->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Consumo registrado.');
        // The same consumption sent twice in a row is recorded once.
        $use($g1, 5)->assertSessionHasErrorsIn('material', ['quantity' => 'Este consumo acabou de ser registrado. Se foram mais, registre de novo em instantes.']);
        $use($g1, 16)->assertSessionHasErrorsIn('material', ['quantity' => 'O lote tem 15 em saldo.']);
        foreach ([$old => 'Lote vencido não é consumido.', $elsewhere => 'Escolha um lote de material desta unidade.', $drug => 'Escolha um lote de material desta unidade.'] as $lot => $message) {
            $use($lot, 1)->assertSessionHasErrorsIn('material', ['stock_lot_id' => $message]);
        }
        $this->assertSame(15, StockLot::withoutTenantScope()->find($g1)->balance);
        $this->assertEquals(['unit_id' => $this->unit->id, 'surgery_id' => $surgery->id, 'material_id' => $gauze->id, 'stock_lot_id' => $g1, 'quantity' => 5], AuditEvent::where('action', 'stock.surgery_consumption')->sole()->context);

        // A correction is a return, up to what the surgery took from the lot.
        $back = fn (int $lot, int $quantity) => $this->post(route('surgery-materials.return', [$surgery, $lot]), ['quantity' => $quantity]);
        $back($g1, 2)->assertSessionHasNoErrors();
        $back($g1, 4)->assertSessionHasErrorsIn("materialReturn{$g1}", ['quantity' => 'O máximo a estornar deste lote é 3.']);
        $back($elsewhere, 1)->assertSessionHasErrorsIn("materialReturn{$elsewhere}", ['quantity' => 'Não há consumo deste lote a estornar nesta cirurgia.']);
        $this->assertSame(17, StockLot::withoutTenantScope()->find($g1)->balance);
        $this->get(route('surgery-schedule.show', $surgery))->assertInertia(fn (Assert $page) => $page
            ->where('materials.used.0.taken', 5)->where('materials.used.0.returned', 2)->where('materials.used.0.code', 'G1')->where('materials.used.0.name', 'Compressa cirúrgica'));
        // Step 6.2b: the account bills the consumption and the return (negative), by the material's name while it has no
        // billable item (pending, no price).
        $this->assertSame([['stock_movement', 5, 'Compressa cirúrgica', null], ['stock_movement', -2, 'Compressa cirúrgica', null]],
            BillingAccountItem::withoutTenantScope()->orderBy('id')->get()->map(fn ($item) => [$item->origin_type, $item->quantity, $item->name, $item->unit_price_cents])->all());

        // What comes back to a lot that expired in the meantime is discarded at once.
        $use($soon, 2)->assertSessionHasNoErrors();
        $this->travel(5)->days();
        $back($soon, 2)->assertSessionHasNoErrors();
        $this->assertSame([18, [['surgery', -2], ['surgery_return', 2], ['discard', -2]]], [StockLot::withoutTenantScope()->find($soon)->balance,
            DB::table('stock_movements')->where('stock_lot_id', $soon)->where('type', '<>', 'entry')->orderBy('id')->get()->map(fn ($row) => [$row->type, (int) $row->quantity])->all()]);
        $this->assertEquals(['unit_id' => $this->unit->id, 'surgery_id' => $surgery->id, 'material_id' => $gauze->id, 'stock_lot_id' => $soon, 'quantity' => 2, 'discarded' => 2],
            AuditEvent::where('action', 'stock.surgery_return')->latest('id')->firstOrFail()->context);
        $this->assertSame('expired', DB::table('stock_movements')->where('stock_lot_id', $soon)->where('type', 'discard')->value('reason'));

        // The database keeps it too: no surgery movement from a medication lot, no return beyond what was taken.
        app(CurrentTenant::class)->set($this->patient->tenant);
        foreach ([[$drug, 'surgery', -1], [$g1, 'surgery_return', 4]] as [$lot, $type, $quantity]) {
            try {
                DB::transaction(fn () => DB::table('stock_movements')->insert(['tenant_id' => $surgery->tenant_id, 'stock_lot_id' => $lot, 'type' => $type, 'quantity' => $quantity, 'surgery_id' => $surgery->id, 'user_id' => $this->center->user->id, 'created_at' => now()]));
                $this->fail('The database accepted a surgery movement it must refuse.');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->getCode(), $e->getMessage());
            }
        }
        app(CurrentTenant::class)->set(null);

        // After the completion, for the addendum's days, then no more.
        $this->actingAs($this->surgeonAccount->user)->post(route('surgery-description.store', $surgery), self::DESCRIPTION)->assertSessionHasNoErrors();
        $this->actingAs($this->center->user);
        $use($g1, 1)->assertSessionHasNoErrors();
        $this->travel(SurgeryDescription::ADDENDUM_DAYS + 1)->days();
        $use($g1, 1)->assertForbidden();
        $back($g1, 1)->assertForbidden();
        $this->get(route('surgery-schedule.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('canUseMaterials', false)->where('materials.lots', [])
            ->where('materials.used', fn ($used) => collect($used)->firstWhere('code', 'G1')['taken'] === 6));
        foreach (DB::table('stock_lots')->get() as $lot) {
            $this->assertSame((int) DB::table('stock_movements')->where('stock_lot_id', $lot->id)->sum('quantity'), $lot->balance);
        }
    }
}
