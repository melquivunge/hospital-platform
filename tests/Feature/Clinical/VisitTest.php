<?php

namespace Tests\Feature\Clinical;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\ClinicalNote;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class VisitTest extends ClinicalTestCase
{
    public function test_a_visit_from_arrival_to_signed_notes_and_addendum(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);

        // Opening twice lands on the same visit.
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $this->post(route('visits.open', $appointment))->assertRedirect();
        $visit = Visit::withoutTenantScope()->sole();
        $this->assertSame('in_progress', Appointment::withoutTenantScope()->findOrFail($appointment->id)->status);

        $this->post(route('visits.notes.store', $visit), ['body' => 'Queixa: cefaleia há 3 dias.'])->assertSessionHasNoErrors();
        $draft = ClinicalNote::withoutTenantScope()->sole();
        $this->put(route('clinical-notes.update', $draft), ['body' => 'Queixa: cefaleia há 3 dias. Sem febre.'])->assertSessionHasNoErrors();
        // Closing with a draft is refused.
        $this->post(route('visits.close', $visit))->assertSessionHasErrors('visit');

        $this->post(route('clinical-notes.sign', $draft), ['body' => 'Queixa: cefaleia há 3 dias. Sem febre.', 'password' => 'errada'])->assertSessionHasErrors('password');
        $this->post(route('clinical-notes.sign', $draft), ['body' => 'Queixa: cefaleia há 3 dias. Sem febre.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->put(route('clinical-notes.update', $draft), ['body' => 'Mudança'])->assertForbidden();

        // A correction is an addendum, signed in turn.
        $this->post(route('visits.notes.store', $visit), ['body' => 'Corrijo: febre de 38 °C ontem.', 'addendum_of_id' => $draft->id])->assertSessionHasNoErrors();
        $addendum = ClinicalNote::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('clinical-notes.sign', $addendum), ['body' => 'Corrijo: febre de 38 °C ontem.', 'password' => 'password'])->assertSessionHasNoErrors();

        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->component('visits/show')
            ->has('record', 1)->where('record.0.body', 'Queixa: cefaleia há 3 dias. Sem febre.')->where('record.0.author', 'Dra. Ana')
            ->has('record.0.addenda', 1)->has('drafts', 0));
        $this->assertTrue(DB::table('clinical_reads')->where('user_id', $this->doctorAccount->user_id)->where('visit_id', $visit->id)->exists());

        $this->post(route('visits.close', $visit))->assertSessionHasNoErrors();
        $this->assertSame('completed', Appointment::withoutTenantScope()->findOrFail($appointment->id)->status);
        // Without an open visit the record is no longer readable.
        $this->get(route('visits.show', $visit))->assertRedirect(route('visits.index'));
        $this->post(route('visits.notes.store', $visit), ['body' => 'Depois'])->assertForbidden();

        // The audit trail never carries the clinical text.
        $this->assertStringNotContainsString('cefaleia', (string) json_encode(AuditEvent::pluck('context')));
    }

    public function test_the_record_follows_the_patient_across_the_network_but_drafts_stay_with_the_author(): void
    {
        $first = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $first));
        $doctorVisit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.notes.store', $doctorVisit), ['body' => 'Hipertensão em acompanhamento.']);
        $note = ClinicalNote::withoutTenantScope()->sole();
        $this->post(route('clinical-notes.sign', $note), ['body' => 'Hipertensão em acompanhamento.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.notes.store', $doctorVisit), ['body' => 'Rascunho só meu.'])->assertSessionHasNoErrors();

        // The nurse, in another unit, has no visit with the patient yet: she cannot open the doctor's visit.
        $this->actingAs($this->nurseAccount->user)->get(route('visits.show', $doctorVisit))->assertForbidden();

        $this->travelTo('2026-11-13 12:00:00');
        $second = $this->arrived($this->nurse, $this->other, '09:00');
        $this->actingAs($this->nurseAccount->user)->post(route('visits.open', $second));
        $nurseVisit = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->get(route('visits.show', $nurseVisit))->assertInertia(fn (Assert $page) => $page
            ->has('record', 1)->where('record.0.body', 'Hipertensão em acompanhamento.')->has('drafts', 0));
        $this->assertSame(1, DB::table('clinical_reads')->where('user_id', $this->nurseAccount->user_id)->where('reason', 'visit')->count());

        // A second visit of the same patient starts clean: the draft of the first one stays there.
        $this->travelTo('2026-11-13 12:00:00');
        $third = $this->arrived($this->doctor, $this->unit, '10:00');
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $third));
        $later = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->get(route('visits.show', $later))->assertInertia(fn (Assert $page) => $page->has('drafts', 0));
        $this->post(route('visits.close', $later))->assertSessionHasNoErrors();
    }

    public function test_reception_managers_and_other_professionals_never_read_the_record(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $visit = Visit::withoutTenantScope()->sole();

        foreach ([$this->reception, $this->manager, $this->nurseAccount] as $member) {
            $this->actingAs($member->user)->get(route('visits.show', $visit))->assertForbidden();
            $this->post(route('visits.open', $appointment))->assertForbidden();
            $this->post(route('visits.notes.store', $visit), ['body' => 'Intruso'])->assertForbidden();
        }
        $this->actingAs($this->reception->user)->get(route('visits.index'))->assertForbidden();

        // Another client does not even find it.
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->create();
        $this->actingAs($stranger->user)->get(route('visits.show', $visit))->assertNotFound();
    }

    public function test_a_visit_starts_only_after_arrival(): void
    {
        $this->actingAs($this->reception->user)->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-16T08:20'])->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertSessionHasErrors('visit');
        $this->assertSame(0, Visit::withoutTenantScope()->count());
    }

    public function test_the_database_keeps_signed_notes_and_the_read_trail_untouched(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Texto original.']);
        $note = ClinicalNote::withoutTenantScope()->sole();
        $this->post(route('clinical-notes.sign', $note), ['body' => 'Texto original.', 'password' => 'password']);
        $this->get(route('visits.show', $visit));
        app(CurrentTenant::class)->set($this->unit->tenant);

        $this->post(route('visits.notes.store', $visit), ['body' => 'Rascunho novo.']);
        $draft = ClinicalNote::withoutTenantScope()->where('status', 'draft')->sole();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $signedRow = (array) DB::table('clinical_notes')->where('id', $note->id)->first();
        unset($signedRow['id']);

        foreach ([
            fn () => DB::table('clinical_notes')->where('id', $draft->id)->update(['author_professional_id' => $this->nurse->id]),
            fn () => DB::table('clinical_notes')->insert($signedRow),
            fn () => DB::table('visits')->where('id', $visit->id)->update(['patient_id' => $this->patient->id, 'professional_id' => $this->nurse->id]),
            fn () => DB::table('clinical_notes')->where('id', $note->id)->update(['body' => 'Adulterado']),
            fn () => DB::table('clinical_notes')->where('id', $note->id)->delete(),
            fn () => DB::table('clinical_reads')->update(['reason' => 'emergency']),
            fn () => DB::table('clinical_reads')->delete(),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
        $this->assertSame('Texto original.', DB::table('clinical_notes')->where('id', $note->id)->value('body'));

        // A closed visit is never reopened.
        $this->actingAs($this->doctorAccount->user)->put(route('clinical-notes.update', $draft), ['body' => 'Rascunho novo.'])->assertSessionHasNoErrors();
        $this->post(route('clinical-notes.sign', $draft), ['body' => 'Rascunho novo.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.close', $visit))->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('visits')->where('id', $visit->id)->update(['closed_at' => null]));
            $this->fail('A closed visit was reopened.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
    }

    public function test_one_draft_per_visit_only_the_author_signs_and_wrong_passwords_are_limited(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Primeiro.'])->assertSessionHasNoErrors();
        $this->post(route('visits.notes.store', $visit), ['body' => 'Segundo.'])->assertSessionHasErrors('body');
        $draft = ClinicalNote::withoutTenantScope()->sole();
        // An addendum targets a signed note, never a draft.
        $this->post(route('visits.notes.store', $visit), ['body' => 'Adendo.', 'addendum_of_id' => $draft->id])->assertSessionHasErrors('body');
        // Nobody else signs the author's draft.
        $this->actingAs($this->nurseAccount->user)->post(route('clinical-notes.sign', $draft), ['body' => 'x', 'password' => 'password'])->assertForbidden();

        // Five wrong passwords lock signing for a while, even with the right one; each failure is audited.
        $this->actingAs($this->doctorAccount->user);
        foreach (range(1, 5) as $attempt) {
            $this->post(route('clinical-notes.sign', $draft), ['body' => 'Primeiro.', 'password' => "errada{$attempt}"])->assertSessionHasErrors('password');
        }
        $this->post(route('clinical-notes.sign', $draft), ['body' => 'Primeiro.', 'password' => 'password'])->assertSessionHasErrors('password');
        $this->assertSame(5, AuditEvent::where('action', 'clinical_note.sign_failed')->count());
        $this->assertSame('draft', ClinicalNote::withoutTenantScope()->findOrFail($draft->id)->status);
        $this->travel(16)->minutes();
        $this->post(route('clinical-notes.sign', $draft), ['body' => 'Primeiro.', 'password' => 'password'])->assertSessionHasNoErrors();
    }
}
