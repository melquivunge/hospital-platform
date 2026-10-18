<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\ClinicalNote;
use App\Models\EmergencyAccess;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class EmergencyAccessTest extends ClinicalTestCase
{
    /**
     * The doctor signs a note in a visit, so the record has content.
     */
    private function recordWithANote(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Alergia a dipirona.']);
        $this->post(route('clinical-notes.sign', ClinicalNote::withoutTenantScope()->sole()), ['body' => 'Alergia a dipirona.', 'password' => 'password'])->assertSessionHasNoErrors();
    }

    public function test_a_justified_emergency_grant_opens_the_record_read_only_for_four_hours(): void
    {
        $this->recordWithANote();

        // Without a visit the nurse is sent to the emergency form.
        $this->actingAs($this->nurseAccount->user)->get(route('clinical-record.show', $this->patient))->assertRedirect(route('clinical-record.emergency', $this->patient));
        $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Urgente'])->assertSessionHasErrors('justification');
        $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->unit->id, 'justification' => 'Paciente desacordada no pronto-socorro.'])->assertSessionHasErrors('unit_id');
        $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.'])
            ->assertRedirect(route('clinical-record.show', $this->patient));
        $grant = EmergencyAccess::withoutTenantScope()->sole();
        $this->assertSame(4 * 3600, (int) $grant->granted_at->diffInSeconds($grant->expires_at));

        $this->get(route('clinical-record.show', $this->patient))->assertInertia(fn (Assert $page) => $page->component('clinical/record')
            ->has('record', 1)->where('record.0.body', 'Alergia a dipirona.'));
        $this->assertSame(1, DB::table('clinical_reads')->where('reason', 'emergency')->where('emergency_access_id', $grant->id)->count());

        // The unit's manager is told, without patient data; the audit keeps ids, not the justification.
        (new DeliverNotifications)->handle();
        $notification = DB::table('user_notifications')->where('user_id', $this->manager->user_id)->where('title', 'Acesso de emergência a um prontuário')->sole();
        $this->assertSame('/clinical-access', $notification->url);
        $this->assertStringNotContainsString('desacordada', (string) json_encode(AuditEvent::where('action', 'clinical.emergency_access')->sole()->context));

        // After 4 hours it expires; renewing needs a new justification.
        $this->travel(4)->hours();
        $this->travel(1)->minutes();
        $this->get(route('clinical-record.show', $this->patient))->assertRedirect(route('clinical-record.emergency', $this->patient));
        $this->assertSame(1, DB::table('clinical_reads')->where('reason', 'emergency')->count());
        $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Retorno ao pronto-socorro com piora.'])->assertSessionHasNoErrors();
        $this->assertSame(2, EmergencyAccess::withoutTenantScope()->count());
    }

    public function test_with_an_open_visit_the_record_goes_to_the_visit_and_others_never_ask_for_emergency(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $visit = Visit::withoutTenantScope()->sole();
        $this->get(route('clinical-record.show', $this->patient))->assertRedirect(route('visits.show', $visit));

        foreach ([$this->reception, $this->manager] as $member) {
            $this->actingAs($member->user)->get(route('clinical-record.show', $this->patient))->assertForbidden();
            $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->unit->id, 'justification' => 'Quero ver o prontuário dela.'])->assertForbidden();
        }
        $this->assertSame(0, EmergencyAccess::withoutTenantScope()->count());
    }

    public function test_the_access_trail_shows_grants_to_management_and_every_read_to_the_auditor(): void
    {
        $this->recordWithANote();
        $this->actingAs($this->nurseAccount->user)->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.']);
        $this->get(route('clinical-record.show', $this->patient));

        app(CurrentTenant::class)->set(null);
        $auditor = Membership::factory()->for($this->unit->tenant)->withRole(Role::Auditor)->create();
        $otherManager = Membership::factory()->for($this->unit->tenant)->create();
        $otherManager->grant(Role::UnitManager, $this->unit);

        // The grant was made in the nurse's unit: its manager sees it, the manager of the other unit does not.
        $this->actingAs($this->manager->user)->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page->component('clinical/access-log')
            ->has('emergencies', 1)->where('emergencies.0.justification', 'Paciente desacordada no pronto-socorro.')->where('reads', null));
        $this->actingAs($otherManager->user)->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page->has('emergencies', 0));
        $this->actingAs($auditor->user)->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page
            ->has('emergencies', 1)->has('reads', 1)->where('reads.0.reason', 'emergency'));
        $this->actingAs($this->reception->user)->get(route('clinical-access.index'))->assertForbidden();
        $this->actingAs($this->nurseAccount->user)->get(route('clinical-access.index'))->assertForbidden();
    }

    public function test_no_duplicate_grants_the_boundary_closes_and_revoked_accounts_lose_them(): void
    {
        $justified = ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.'];
        $this->actingAs($this->nurseAccount->user)->post(route('clinical-record.emergency.store', $this->patient), $justified);
        // A valid grant already opens the record: asking again creates nothing and notifies nobody.
        $this->post(route('clinical-record.emergency.store', $this->patient), $justified)->assertRedirect(route('clinical-record.show', $this->patient));
        $this->assertSame(1, EmergencyAccess::withoutTenantScope()->count());

        // Exactly at the end of the 4 hours it no longer opens.
        $this->travelTo(EmergencyAccess::withoutTenantScope()->sole()->expires_at);
        $this->get(route('clinical-record.show', $this->patient))->assertRedirect(route('clinical-record.emergency', $this->patient));

        $this->post(route('clinical-record.emergency.store', $this->patient), $justified)->assertSessionHasNoErrors();
        $this->nurseAccount->revoke();
        $this->get(route('clinical-record.show', $this->patient))->assertForbidden();
    }

    public function test_a_merged_registration_leads_to_the_primary_record(): void
    {
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana P. Prado', 'birth_date' => '1990-01-02', 'sex' => 'female', 'confirm_new' => true])->assertSessionHasNoErrors();
        $duplicate = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        app(CurrentTenant::class)->set($this->unit->tenant);
        Patient::merge($this->patient, $duplicate, 'Mesma pessoa');
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->nurseAccount->user)->get(route('clinical-record.show', $duplicate))->assertRedirect(route('clinical-record.show', $this->patient));
        $this->post(route('clinical-record.emergency.store', $duplicate), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.'])->assertNotFound();
    }

    public function test_grants_are_insert_only_and_last_four_hours_in_the_database(): void
    {
        $this->actingAs($this->nurseAccount->user)->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.']);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('emergency_accesses')->first();
        unset($row['id']);

        foreach ([
            fn () => DB::table('emergency_accesses')->update(['expires_at' => now()->addDays(30)]),
            fn () => DB::table('emergency_accesses')->delete(),
            fn () => DB::table('emergency_accesses')->insert([...$row, 'expires_at' => now()->addDays(30)]),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['P0001', '23514']);
            }
        }
    }
}
