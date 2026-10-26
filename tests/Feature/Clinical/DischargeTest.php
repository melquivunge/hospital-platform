<?php

namespace Tests\Feature\Clinical;

use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\ClinicalNote;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4.7: discharge (signed summary, or time and author for death and evasion) and the bed census.
 */
class DischargeTest extends ClinicalTestCase
{
    private Membership $bedManager;

    private Membership $wardNurse;

    private Sector $ward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $this->bedManager->grant(Role::BedManagement, $this->unit);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->ward = Sector::query()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->wardNurse = Membership::factory()->for($this->unit->tenant)->create();
        $this->wardNurse->grant(Role::Professional, $this->unit);
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Enf. Carla']);
        $professional->forceFill(['membership_id' => $this->wardNurse->id])->save();
        Qualification::request($professional, $this->unit, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$this->ward->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
    }

    private function bed(string $code, ?Unit $unit = null, string $type = 'ward'): Bed
    {
        $unit ??= $this->unit;
        $sector = $unit->is($this->unit) ? $this->ward : Sector::withoutGlobalScopes()->where('unit_id', $unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => $code, 'type' => $type])->assertSessionHasNoErrors();

        return Bed::withoutGlobalScopes()->where('code', $code)->sole();
    }

    private function admitted(Bed $bed, string $modality = 'sus'): Admission
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => $modality]);
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['outcome' => 'admission_requested', 'requested_sector_id' => $bed->sector_id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();

        return $admission->fresh();
    }

    public function test_the_admission_keeps_the_emergency_account_and_the_discharge_bills_it(): void
    {
        $admission = $this->admitted($this->bed('A-1'));
        $account = BillingAccount::withoutTenantScope()->sole();
        $this->assertSame([$admission->emergency_episode_id, 'sus'], [$account->emergency_episode_id, $account->modality]);
        // SUS generated nothing on the visit's close; with a billable modality, the discharge bills what is pending.
        $this->assertSame(0, BillingAccountItem::withoutTenantScope()->count());
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('billing_accounts')->where('id', $account->id)->update(['modality' => 'private']);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Alta.', 'password' => 'password'])->assertSessionHasNoErrors();
        // The emergency visit and, admitted and discharged the same day, one daily rate (no billable item yet: pending).
        $this->assertSame([[$account->id, 'visit', 'Consulta de urgência'], [$account->id, 'admission_day', 'Diária Enfermaria']],
            BillingAccountItem::withoutTenantScope()->orderBy('id')->get()->map(fn ($item) => [$item->billing_account_id, $item->origin_type, $item->name])->all());
        $this->assertSame(1, BillingAccount::withoutTenantScope()->count());
    }

    public function test_each_day_of_the_admission_is_one_daily_rate_by_the_bed_type(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $daily = BillingItem::query()->create(['kind' => 'daily', 'bed_type' => 'ward', 'name' => 'Diária de enfermaria', 'code' => '60000775']);
        $table = PriceTable::query()->create(['name' => 'Particular', 'private' => true]);
        PriceEntry::query()->create(['price_table_id' => $table->id, 'billing_item_id' => $daily->id, 'price_cents' => 48000, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $days = fn () => BillingAccountItem::withoutTenantScope()->where('origin_type', 'admission_day')->orderBy('performed_on')->get()
            ->map(fn ($item) => [$item->performed_on->toDateString(), $item->code, $item->unit_price_cents])->all();

        // Admitted on Friday morning (São Paulo): no day has ended yet, so none is billed.
        $admission = $this->admitted($this->bed('A-1'), 'private');
        $account = BillingAccount::withoutTenantScope()->sole();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(0, $account->generate());

        // Moved to an ICU bed on Saturday afternoon: Saturday is billed by the ICU, the bed at the end of the day.
        app(CurrentTenant::class)->set(null);
        $icu = $this->bed('U-1', null, 'icu');
        $this->travelTo('2026-11-14 18:00:00');
        $this->actingAs($this->bedManager->user)->post(route('admissions.move', $admission), ['bed_id' => $icu->id])->assertSessionHasNoErrors();
        // On Sunday, Friday and Saturday have ended: two days, each billed once however often it runs.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->travelTo('2026-11-15 13:00:00');
        $this->assertSame(2, $account->generate());
        $this->assertSame(0, $account->generate());
        app(CurrentTenant::class)->set(null);
        // The discharge on Sunday bills no day for Sunday.
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Alta.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame([['2026-11-13', '60000775', 48000], ['2026-11-14', null, null]], $days());
        $this->assertSame('Diária UTI', BillingAccountItem::withoutTenantScope()->where('performed_on', '2026-11-14')->value('name'));

        // Admitted and discharged on the same day: one day.
        $again = $this->admitted($this->bed('A-2'), 'private');
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $again), ['type' => 'improved', 'summary' => 'Alta.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-15', BillingAccountItem::withoutTenantScope()->where('origin_type', 'admission_day')->where('origin_id', $again->id)->sole()->performed_on->toDateString());
    }

    public function test_the_signed_summary_discharges_frees_the_bed_and_ends_the_ward_access(): void
    {
        $bed = $this->bed('A-1');
        $admission = $this->admitted($bed);
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertOk();

        // Only the responsible doctor discharges.
        $this->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Estável.', 'password' => 'password'])->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))
            ->assertInertia(fn (Assert $page) => $page->where('discharge.otherDrafts', 0)->has('discharge.types', 5));

        // Refused without the summary, and with a wrong password: nothing changes.
        $this->post(route('ward.discharge', $admission), ['type' => 'improved', 'password' => 'password'])->assertSessionHasErrors('summary');
        $this->post(route('ward.discharge', $admission), ['type' => 'at_request', 'summary' => 'Estável.', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(['admitted', 'occupied'], [$admission->fresh()->status, $bed->fresh()->status]);

        $this->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Alta por melhora. Retorno em 7 dias.', 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('ward.index'));
        $admission = $admission->fresh();
        $summary = ClinicalNote::withoutTenantScope()->findOrFail($admission->discharge_summary_id);
        $this->assertSame(['discharged', 'improved', 'cleaning'], [$admission->status, $admission->discharge_type, $bed->fresh()->status]);
        $this->assertSame(['signed', $admission->id, 'Alta por melhora. Retorno em 7 dias.'], [$summary->status, $summary->admission_id, $summary->body]);
        $this->assertSame(['occupied', 'cleaning'], [DB::table('bed_events')->where('bed_id', $bed->id)->latest('id')->value('from_status'), DB::table('bed_events')->where('bed_id', $bed->id)->latest('id')->value('to_status')]);
        // The audit keeps ids only: never the discharge type.
        $this->assertArrayNotHasKey('type', AuditEvent::query()->where('action', 'admission.discharged')->sole()->context);

        // Access ends with the admission: neither the team nor the doctor reads or writes by it.
        $this->get(route('ward.show', $admission))->assertForbidden();
        $this->post(route('ward.notes.store', $admission), ['body' => 'Depois da alta.'])->assertForbidden();
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $admission), ['type' => 'death', 'occurred_at' => now($this->unit->timezone ?? 'UTC')->format('Y-m-d\TH:i')])->assertSessionHasErrors('type');

        // The summary is immutable, like every signed note.
        $this->expectException(QueryException::class);
        DB::table('clinical_notes')->where('id', $summary->id)->update(['body' => 'Alterado.']);
    }

    public function test_death_and_evasion_record_the_time_without_a_summary(): void
    {
        $bed = $this->bed('A-1');
        $admission = $this->admitted($bed);
        $timezone = $this->unit->timezone ?? 'UTC';
        $this->travel(10)->minutes();
        $this->actingAs($this->doctorAccount->user);

        $this->post(route('ward.discharge', $admission), ['type' => 'death', 'occurred_at' => now($timezone)->addHour()->format('Y-m-d\TH:i')])->assertSessionHasErrors('occurred_at');
        $this->post(route('ward.discharge', $admission), ['type' => 'death', 'occurred_at' => now($timezone)->subDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('occurred_at');
        $at = now($timezone)->subMinutes(1)->startOfMinute();
        $this->post(route('ward.discharge', $admission), ['type' => 'death', 'occurred_at' => $at->format('Y-m-d\TH:i'), 'summary' => 'ignorado'])->assertSessionHasNoErrors();
        $admission = $admission->fresh();
        $this->assertSame(['discharged', 'death', null, $this->doctorAccount->user_id], [$admission->status, $admission->discharge_type, $admission->discharge_summary_id, $admission->discharged_by_user_id]);
        $this->assertTrue($admission->discharged_at->equalTo($at));
        $this->assertSame(0, ClinicalNote::withoutTenantScope()->where('admission_id', $admission->id)->count());

        // The database refuses a summary type without the summary.
        $this->expectException(QueryException::class);
        DB::table('admissions')->where('id', $admission->id)->update(['discharge_type' => 'improved']);
    }

    public function test_own_drafts_and_open_transfers_block_the_discharge_and_other_drafts_are_kept(): void
    {
        $admission = $this->admitted($this->bed('A-1'));
        $this->actingAs($this->wardNurse->user)->post(route('ward.notes.store', $admission), ['body' => 'Rascunho da enfermagem.'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.notes.store', $admission), ['body' => 'Rascunho do médico.'])->assertSessionHasNoErrors();
        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('discharge.otherDrafts', 1));

        $discharge = ['type' => 'evasion', 'occurred_at' => now($this->unit->timezone ?? 'UTC')->format('Y-m-d\TH:i')];
        $this->post(route('ward.discharge', $admission), $discharge)->assertSessionHasErrors('type');
        $draft = ClinicalNote::withoutTenantScope()->where('author_user_id', $this->doctorAccount->user_id)->sole();
        $this->post(route('clinical-notes.sign', $draft), ['body' => $draft->body, 'password' => 'password'])->assertSessionHasNoErrors();

        // An open transfer between units must be cancelled first.
        $destination = $this->bed('D-1', $this->other);
        $this->actingAs($this->doctorAccount->user)->post(route('transfers.store', $admission), ['to_unit_id' => $this->other->id, 'requested_sector_id' => $destination->sector_id, 'responsible_professional_id' => $this->nurse->id])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('discharge', null));
        $this->post(route('ward.discharge', $admission), $discharge)->assertSessionHasErrors('type');
        $this->post(route('transfers.cancel', DB::table('admission_transfers')->value('id')), ['reason' => 'Vaga local.'])->assertSessionHasNoErrors();

        $this->post(route('ward.discharge', $admission), $discharge)->assertSessionHasNoErrors();
        $this->assertSame('discharged', $admission->fresh()->status);
        // The nurse's draft is kept, unsigned, and can no longer be signed.
        $nurseDraft = ClinicalNote::withoutTenantScope()->where('author_user_id', $this->wardNurse->user_id)->sole();
        $this->assertSame('draft', $nurseDraft->status);
        $this->actingAs($this->wardNurse->user)->post(route('clinical-notes.sign', $nurseDraft), ['body' => $nurseDraft->body, 'password' => 'password'])->assertForbidden();
    }

    public function test_the_census_matches_the_bed_map_without_patient_data(): void
    {
        $occupied = $this->bed('A-1');
        $this->bed('A-2');
        $blocked = $this->bed('A-3');
        $cleaning = $this->bed('A-4');
        $admission = $this->admitted($occupied);
        $this->actingAs($this->bedManager->user)->post(route('beds.block', $blocked), ['reason' => 'Manutenção'])->assertSessionHasNoErrors();
        $this->post(route('beds.send-to-cleaning', $cleaning))->assertSessionHasNoErrors();

        $map = null;
        $this->get(route('beds.index'))->assertInertia(function (Assert $page) use (&$map) {
            $map = $page->toArray()['props']['counts'];
        });
        $this->get(route('census.index'))->assertOk()->assertDontSee('Joana Prado')->assertInertia(fn (Assert $page) => $page
            ->component('census/index')
            ->where('total.occupied', $map['occupied'])->where('total.free', $map['free'])->where('total.blocked', $map['blocked'])
            ->where('total.cleaning', $map['cleaning'])->where('total.reserved', $map['reserved'])
            ->where('total.beds', 4)->where('total.occupancy', 33.3)->where('total.meanStayDays', null)
            ->where('sectors.0.name', $this->ward->name));

        $this->travel(36)->hours();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Estável.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->get(route('census.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->where('total.occupied', 0)->where('total.cleaning', 2)->where('total.discharges', 1)->where('total.meanStayDays', 1.5));

        // A unit the member cannot see falls back to an allowed one.
        $this->actingAs($this->bedManager->user)->get(route('census.index', ['unit' => $this->other->id]))->assertInertia(fn (Assert $page) => $page->where('unit.id', $this->unit->id));

        // Managers and bed management only: not the clinical staff, the reception or housekeeping.
        $housekeeping = Membership::factory()->for($this->unit->tenant)->create();
        $housekeeping->grant(Role::Housekeeping, $this->unit);
        foreach ([$this->doctorAccount, $this->reception, $housekeeping] as $member) {
            $this->actingAs($member->user)->get(route('census.index'))->assertForbidden();
        }
    }
}
