<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\Membership;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Step 4.6: transfer of an inpatient between units of the network, from the request to the reception.
 */
class UnitTransferTest extends ClinicalTestCase
{
    private Membership $originBeds;

    private Membership $destinationBeds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originBeds = Membership::factory()->for($this->unit->tenant)->create();
        $this->originBeds->grant(Role::BedManagement, $this->unit);
        $this->destinationBeds = Membership::factory()->for($this->unit->tenant)->create();
        $this->destinationBeds->grant(Role::BedManagement, $this->other);
    }

    private function bed(Unit $unit, string $code): Bed
    {
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => $code, 'type' => 'ward'])->assertSessionHasNoErrors();

        return Bed::withoutGlobalScopes()->where('code', $code)->sole();
    }

    /**
     * Admitted at the origin (the doctor's unit) in the given bed, with the doctor responsible.
     */
    private function admitted(Bed $bed): Admission
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id]);
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['outcome' => 'admission_requested', 'requested_sector_id' => $bed->sector_id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->originBeds->user)->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();

        return $admission->fresh();
    }

    /**
     * @return array<string, int>
     */
    private function request(Bed $destinationBed): array
    {
        return ['to_unit_id' => $this->other->id, 'requested_sector_id' => $destinationBed->sector_id, 'responsible_professional_id' => $this->nurse->id];
    }

    public function test_the_patient_goes_to_the_other_unit_with_the_admission_and_its_history(): void
    {
        $origin = $this->bed($this->unit, 'O-1');
        $destination = $this->bed($this->other, 'D-1');
        $admission = $this->admitted($origin);

        $this->actingAs($this->doctorAccount->user)->post(route('transfers.store', $admission), $this->request($destination))->assertSessionHasNoErrors();
        $transfer = AdmissionTransfer::withoutTenantScope()->sole();
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.accept', $transfer), ['bed_id' => $destination->id])->assertSessionHasNoErrors();
        $this->assertSame('reserved', $destination->fresh()->status);

        $this->actingAs($this->originBeds->user)->post(route('transfers.depart', $transfer))->assertSessionHasNoErrors();
        $this->assertSame(['in_transit', null], [$admission->fresh()->status, $admission->fresh()->bed_id]);
        $this->assertSame('cleaning', $origin->fresh()->status);

        // The destination's responsible is checked again: revoked in between, the reception is refused.
        $this->nurseAccount->revoke();
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.receive', $transfer))->assertSessionHasErrors('transfer');
        $this->nurseAccount->forceFill(['revoked_at' => null])->save();
        $this->post(route('transfers.receive', $transfer))->assertSessionHasNoErrors();
        $admission = $admission->fresh();
        $this->assertSame([$this->other->id, $destination->id, $this->nurse->id, 'admitted'], [$admission->unit_id, $admission->bed_id, $admission->responsible_professional_id, $admission->status]);
        $this->assertSame(['occupied', 'completed'], [$destination->fresh()->status, $transfer->fresh()->status]);
        $this->assertSame(['bed', 'bed', 'responsible'], DB::table('admission_movements')->where('admission_id', $admission->id)->orderBy('id')->pluck('kind')->all());

        // The record follows: the destination's responsible reads, the origin's doctor no longer does.
        $this->actingAs($this->nurseAccount->user)->get(route('ward.show', $admission))->assertOk();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertForbidden();

        // Bed management of both units heard of the 4 steps, without patient data.
        (new DeliverNotifications)->handle();
        foreach ([$this->originBeds, $this->destinationBeds] as $member) {
            $this->assertSame(4, DB::table('user_notifications')->where('user_id', $member->user_id)->where('url', '/admissions')->count());
        }
        $this->assertStringNotContainsString('Joana', (string) DB::table('user_notifications')->pluck('title')->implode(' '));
    }

    public function test_each_step_belongs_to_its_side_and_cancellation_frees_the_reserved_bed(): void
    {
        $destination = $this->bed($this->other, 'D-1');
        $admission = $this->admitted($this->bed($this->unit, 'O-1'));

        foreach ([$this->nurseAccount, $this->originBeds, $this->destinationBeds] as $member) {
            $this->actingAs($member->user)->post(route('transfers.store', $admission), $this->request($destination))->assertForbidden();
        }
        $this->actingAs($this->doctorAccount->user)->post(route('transfers.store', $admission), ['to_unit_id' => $this->unit->id] + $this->request($destination))->assertSessionHasErrors('to_unit_id');
        $this->post(route('transfers.store', $admission), $this->request($destination))->assertSessionHasNoErrors();
        $this->post(route('transfers.store', $admission), $this->request($destination))->assertSessionHasErrors('to_unit_id');
        $transfer = AdmissionTransfer::withoutTenantScope()->sole();

        $this->actingAs($this->originBeds->user)->post(route('transfers.accept', $transfer), ['bed_id' => $destination->id])->assertForbidden();
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.depart', $transfer))->assertForbidden();
        $this->post(route('transfers.accept', $transfer), ['bed_id' => $destination->id])->assertSessionHasNoErrors();

        // While the transfer is open: no bed change and no hand-over.
        $spare = $this->bed($this->unit, 'O-2');
        $this->actingAs($this->originBeds->user)->post(route('admissions.move', $admission), ['bed_id' => $spare->id])->assertSessionHasErrors('bed_id');
        $this->actingAs($this->doctorAccount->user)->post(route('ward.hand-over', $admission), ['professional_id' => $this->nurse->id])->assertSessionHasErrors('professional_id');

        // Cancelling needs a reason; the reserved bed goes back to free.
        $this->actingAs($this->doctorAccount->user)->post(route('transfers.cancel', $transfer), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('transfers.cancel', $transfer), ['reason' => 'Paciente melhorou.'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'free', 'admitted'], [$transfer->fresh()->status, $destination->fresh()->status, $admission->fresh()->status]);

        // A new request can be refused by the destination, with a reason.
        $this->post(route('transfers.store', $admission), $this->request($destination))->assertSessionHasNoErrors();
        $second = AdmissionTransfer::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.refuse', $second), ['reason' => 'Sem vaga na UTI.'])->assertSessionHasNoErrors();
        $this->assertSame('Sem vaga na UTI.', $second->fresh()->reason);
    }

    public function test_the_database_keeps_one_open_transfer_and_one_reservation_per_bed(): void
    {
        $destination = $this->bed($this->other, 'D-1');
        $admission = $this->admitted($this->bed($this->unit, 'O-1'));
        $this->actingAs($this->doctorAccount->user)->post(route('transfers.store', $admission), $this->request($destination));
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.accept', AdmissionTransfer::withoutTenantScope()->sole()), ['bed_id' => $destination->id]);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('admission_transfers')->first();
        unset($row['id']);

        foreach ([
            // A second open transfer of the same admission.
            fn () => DB::table('admission_transfers')->insert([...$row, 'status' => 'requested', 'reserved_bed_id' => null, 'decided_at' => null, 'decided_by_user_id' => null]),
            // A refused transfer cannot hold a bed.
            fn () => DB::table('admission_transfers')->insert([...$row, 'status' => 'refused', 'reason' => 'x']),
            // Origin and destination must differ.
            fn () => DB::table('admission_transfers')->update(['to_unit_id' => $this->unit->id]),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23505', '23514', '23503']);
            }
        }
    }
}
