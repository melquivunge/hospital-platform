<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Insurer;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Triage;
use App\Models\TriageLevel;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class EmergencyTest extends ClinicalTestCase
{
    private const TRIAGE = ['level' => 3, 'complaint' => 'Dor abdominal há 2 dias.', 'systolic' => 120, 'diastolic' => 80, 'heart_rate' => 92, 'temperature' => 37.8, 'spo2' => 97];

    private function arrive(?Patient $patient = null): EmergencyEpisode
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => ($patient ?? $this->patient)->id, 'modality' => 'sus'])->assertSessionHasNoErrors();

        return EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function unidentified(): EmergencyEpisode
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['unidentified' => true, 'modality' => 'sus', 'sex' => 'male', 'apparent_age' => 40, 'description' => 'Camiseta azul, trazido pelo SAMU.'])->assertSessionHasNoErrors();

        return EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_the_arrival_records_the_modality_and_the_card(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $insurer = Insurer::query()->create(['name' => 'Saúde Boa', 'ans_code' => '123456']);
        app(CurrentTenant::class)->set(null);
        $arrive = fn (array $data) => $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, ...$data]);
        $card = ['modality' => 'insurance', 'insurer_id' => $insurer->id, 'insurance_card_number' => '0012 3456 7890', 'insurance_card_valid_until' => now()->addYear()->toDateString()];

        $arrive([])->assertSessionHasErrors('modality');
        $arrive(['modality' => 'insurance'])->assertSessionHasErrors(['insurer_id', 'insurance_card_number', 'insurance_card_valid_until']);
        $arrive([...$card, 'insurance_card_valid_until' => now()->subDay()->toDateString()])->assertSessionHasErrors('insurance_card_valid_until');
        $this->assertSame(0, EmergencyEpisode::withoutTenantScope()->count());

        $arrive($card)->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->assertSame(['insurance', $insurer->id, '0012 3456 7890'], [$episode->modality, $episode->insurer_id, $episode->insurance_card_number]);
        $this->assertStringNotContainsString('7890', (string) json_encode(AuditEvent::where('action', 'emergency.arrived')->pluck('context')));

        // The database keeps the card with the insurer, and nothing without it.
        $this->expectException(QueryException::class);
        DB::table('emergency_episodes')->where('id', $episode->id)->update(['modality' => 'private']);
    }

    public function test_the_reception_registers_arrivals_and_never_sees_the_complaint(): void
    {
        $episode = $this->arrive();
        $this->unidentified();
        $unidentified = Patient::withoutTenantScope()->where('unidentified', true)->sole();
        $this->assertSame(['Não identificado', 'provisional', 40], [$unidentified->name, $unidentified->status, $unidentified->apparent_age]);

        // The same patient twice in the network is refused.
        $this->post(route('emergency.arrive', $this->other), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasErrors('patient_id');
        $this->assertSame(2, EmergencyEpisode::withoutTenantScope()->count());

        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), self::TRIAGE)->assertSessionHasNoErrors();

        $this->actingAs($this->reception->user)->get(route('emergency.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->component('emergency/index')
            ->where('canArrive', true)->where('canTriage', false)->has('episodes', 2)
            ->where('episodes.1.patient', 'Joana Prado')->where('episodes.1.level', 3)->where('episodes.1.latest', null)
            ->where('episodes.0.unidentified', true)->where('episodes.0.identification', 'cerca de 40 anos · Camiseta azul, trazido pelo SAMU.'));
        $this->assertStringNotContainsString('abdominal', (string) json_encode(AuditEvent::where('action', 'like', 'emergency.%')->pluck('context')));
    }

    public function test_professionals_of_the_unit_classify_and_reclassify_keeping_the_history(): void
    {
        $episode = $this->arrive();

        // Only a professional qualified at the unit classifies; reception, manager and other units do not.
        foreach ([$this->reception, $this->manager, $this->nurseAccount] as $member) {
            $this->actingAs($member->user)->post(route('emergency.triage', $episode), self::TRIAGE)->assertForbidden();
        }
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 6, 'complaint' => ' ', 'spo2' => 140])->assertSessionHasErrors(['level', 'complaint', 'spo2']);
        $this->post(route('emergency.triage', $episode), self::TRIAGE)->assertSessionHasNoErrors();
        $this->travel(20)->minutes();
        $first = Triage::withoutTenantScope()->sole();
        // Classifying without having seen the current classification is refused: no silent override.
        $this->post(route('emergency.triage', $episode), [...self::TRIAGE, 'level' => 4])->assertSessionHasErrors('level');
        $this->post(route('emergency.triage', $episode), [...self::TRIAGE, 'level' => 2, 'spo2' => 89, 'previous_id' => $first->id])->assertSessionHasNoErrors();
        $this->post(route('emergency.triage', $episode), [...self::TRIAGE, 'level' => 5, 'previous_id' => $first->id])->assertSessionHasErrors('level');

        $triages = Triage::withoutTenantScope()->orderBy('id')->get();
        $this->assertCount(2, $triages);
        $this->assertNull($triages[0]->previous_id);
        $this->assertSame($triages[0]->id, $triages[1]->previous_id);
        $this->assertSame(2, $episode->fresh()->current_level);
        $this->assertSame(1, AuditEvent::where('action', 'emergency.triaged')->where('context->reclassification', true)->count());

        $this->get(route('emergency.index'))->assertInertia(fn (Assert $page) => $page->where('canTriage', true)->where('canArrive', false)
            ->where('episodes.0.level', 2)->where('episodes.0.classifications', 2)
            ->where('episodes.0.latest.complaint', 'Dor abdominal há 2 dias.')->where('episodes.0.latest.spo2', 89));
        $this->actingAs($this->manager->user)->get(route('emergency.index'))->assertForbidden();
    }

    public function test_the_queue_puts_the_unclassified_first_then_level_and_waiting_and_flags_the_target(): void
    {
        $third = $this->arrive();
        $this->travel(1)->minutes();
        $others = collect(['Lia Souza', 'Rui Nunes'])->map(function (string $name) {
            $this->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => $name])->assertSessionHasNoErrors();

            return Patient::withoutTenantScope()->latest('id')->firstOrFail();
        });
        $first = $this->arrive($others[0]);
        $this->travel(1)->minutes();
        $unclassified = $this->arrive($others[1]);

        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $third), self::TRIAGE);
        $this->post(route('emergency.triage', $first), [...self::TRIAGE, 'level' => 2]);
        // Level 2 targets 10 minutes; level 3, 60.
        $this->travel(15)->minutes();

        $this->get(route('emergency.index'))->assertInertia(fn (Assert $page) => $page
            ->where('episodes.0.id', $unclassified->id)->where('episodes.0.level', null)
            ->where('episodes.1.id', $first->id)->where('episodes.1.overdue', true)
            ->where('episodes.2.id', $third->id)->where('episodes.2.overdue', false)->where('episodes.2.waitingMinutes', 17));
    }

    public function test_classifications_are_insert_only_in_the_database(): void
    {
        $episode = $this->arrive();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), self::TRIAGE);
        app(CurrentTenant::class)->set($this->unit->tenant);

        foreach ([
            fn () => DB::table('triages')->update(['level' => 5]),
            fn () => DB::table('triages')->delete(),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
    }

    public function test_an_unidentified_patient_is_identified_later_and_merged(): void
    {
        $episode = $this->unidentified();
        $unidentified = Patient::withoutTenantScope()->findOrFail($episode->patient_id);

        $this->actingAs($this->reception->user)->put(route('patients.update', $unidentified), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female'])->assertSessionHasNoErrors();
        $identified = Patient::withoutTenantScope()->findOrFail($unidentified->id);
        $this->assertFalse($identified->unidentified);
        $this->assertNull($identified->description);

        $this->actingAs($this->manager->user)->get(route('patient-merges.index'))->assertInertia(fn (Assert $page) => $page->has('pairs', 1));
        $this->post(route('patient-merges.store'), ['a_id' => $this->patient->id, 'b_id' => $identified->id, 'primary_id' => $this->patient->id, 'reason' => 'Identificada pela família.'])->assertSessionHasNoErrors();

        // The person is still in the queue through the merged registration: no second arrival by either one.
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->other), ['patient_id' => $identified->id, 'modality' => 'sus'])->assertSessionHasErrors('patient_id');
        $this->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasErrors('patient_id');

        // Once that episode ends, an arrival through the merged registration lands on the primary one.
        $this->post(route('emergency.evade', $episode))->assertSessionHasNoErrors();
        $this->post(route('emergency.arrive', $this->other), ['patient_id' => $identified->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $this->assertSame($this->patient->id, EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail()->patient_id);
    }

    public function test_merged_registrations_never_hold_two_open_episodes(): void
    {
        $primaryEpisode = $this->arrive();
        $episode = $this->unidentified();
        $unidentified = Patient::withoutTenantScope()->findOrFail($episode->patient_id);
        $this->actingAs($this->reception->user)->put(route('patients.update', $unidentified), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female'])->assertSessionHasNoErrors();

        // Both registrations are in the emergency department: merging is refused until one episode ends.
        $merge = ['a_id' => $this->patient->id, 'b_id' => $unidentified->id, 'primary_id' => $this->patient->id, 'reason' => 'Mesma pessoa.'];
        $this->actingAs($this->manager->user)->post(route('patient-merges.store'), $merge)->assertSessionHasErrors('merge');

        // Only the duplicate in the queue: the merge goes through and the primary cannot arrive again.
        $this->actingAs($this->reception->user)->post(route('emergency.evade', $primaryEpisode))->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->post(route('patient-merges.store'), $merge)->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->other), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasErrors('patient_id');
        $this->assertSame(1, EmergencyEpisode::withoutTenantScope()->where('status', 'waiting')->count());
    }

    public function test_an_expired_qualification_no_longer_classifies(): void
    {
        $episode = $this->arrive();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->qualifications()->update(['valid_until' => '2026-11-12']);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), self::TRIAGE)->assertForbidden();
        $this->get(route('emergency.index'))->assertForbidden();
    }

    public function test_the_network_admin_names_the_levels_and_sets_target_times(): void
    {
        $admin = Membership::query()->where('tenant_id', $this->unit->tenant_id)->get()->first(fn (Membership $member) => $member->hasRole(Role::NetworkAdmin));
        $this->actingAs($admin->user)->get(route('triage-levels.index'))->assertInertia(fn (Assert $page) => $page->component('triage-levels/index')
            ->has('levels', 5)->where('levels.0.name', 'Emergência')->where('levels.0.color', 'red')->where('levels.4.target_minutes', 240));
        $level = TriageLevel::withoutTenantScope()->where('level', 3)->sole();

        $this->put(route('triage-levels.update', $level), ['name' => '', 'target_minutes' => 2000])->assertSessionHasErrorsIn('level-3', ['name', 'target_minutes']);
        $this->put(route('triage-levels.update', $level), ['name' => 'Amarelo', 'target_minutes' => 45])->assertSessionHasNoErrors();
        $fresh = TriageLevel::withoutTenantScope()->findOrFail($level->id);
        $this->assertSame(['Amarelo', 45, 'yellow'], [$fresh->name, $fresh->target_minutes, $fresh->color]);
        $this->assertSame(['name', 'target_minutes'], AuditEvent::where('action', 'triage_level.updated')->sole()->context['fields']);

        foreach ([$this->manager, $this->reception, $this->doctorAccount] as $member) {
            $this->actingAs($member->user)->get(route('triage-levels.index'))->assertForbidden();
            $this->put(route('triage-levels.update', $level), ['name' => 'X', 'target_minutes' => 1])->assertForbidden();
        }
    }

    public function test_a_revoked_professional_no_longer_classifies(): void
    {
        $episode = $this->arrive();
        $this->doctorAccount->revoke();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), self::TRIAGE)->assertForbidden();
        $this->get(route('emergency.index'))->assertForbidden();
        $this->assertSame(0, Triage::withoutTenantScope()->count());
    }
}
