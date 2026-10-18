<?php

namespace Tests\Feature\Imports;

use App\Jobs\ApplyImportBatch;
use App\Models\ImportBatch;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Scheduling\ShiftStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShiftImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'source_id;unit;sector;function;start;end;professional_source_id';

    private Membership $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($this->admin->tenant)->create(['name' => 'Hospital Norte', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $doctor = JobFunction::factory()->for($this->admin->tenant)->create(['name' => 'Médico']);

        // Professionals come from the same source, then get a qualification at the unit.
        $this->import('professionals', 'source_id;name;base_unit;council;council_number;council_uf;email;phone', ['P1;Ana Sintética;Hospital Norte;;;;;', 'P2;Bruno Sintético;Hospital Norte;;;;;']);
        app(CurrentTenant::class)->set($this->admin->tenant);
        $ana = Professional::query()->where('external_id', 'P1')->firstOrFail();
        Qualification::request($ana, $this->unit, ['job_function_id' => $doctor->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$icu->id])
            ->transitionTo(QualificationStatus::Approved, null);
    }

    /**
     * Uploads and confirms, running the dispatched job here regardless of the queue driver.
     *
     * @param  list<string>  $lines
     */
    private function import(string $type, string $header, array $lines, bool $confirm = true): ImportBatch
    {
        Bus::fake();
        $this->actingAs($this->admin->user)->post(route('imports.store'), [
            'type' => $type,
            'source_system' => 'Sistema Legado',
            'file' => UploadedFile::fake()->createWithContent('arquivo.csv', implode("\r\n", [$header, ...$lines])),
        ])->assertSessionHasNoErrors();
        $batch = ImportBatch::withoutTenantScope()->latest('id')->firstOrFail();

        if ($confirm) {
            $this->actingAs($this->admin->user)->post(route('imports.confirm', $batch));
            Bus::assertDispatched(ApplyImportBatch::class, function (ApplyImportBatch $job) {
                $job->handle(app(CurrentTenant::class));

                return true;
            });
        }

        return $batch;
    }

    /**
     * @return array<string, int>
     */
    private function counts(ImportBatch $batch): array
    {
        app(CurrentTenant::class)->set($this->admin->tenant);

        return $batch->counts();
    }

    public function test_lines_become_drafts_and_every_line_has_a_result(): void
    {
        $batch = $this->import('shifts', self::HEADER, [
            'S1;Hospital Norte;UTI;Médico;2026-11-10T07:00-03:00;2026-11-10T19:00-03:00;P1',
            'S2;Hospital Norte;UTI;Médico;2026-11-11T07:00-03:00;2026-11-11T19:00-03:00;',
            'S3;Hospital Norte;UTI;Médico;2026-11-12T07:00-03:00;2026-11-12T19:00-03:00;P9',
            'S4;Hospital Norte;Pediatria;Médico;2026-11-12T07:00-03:00;2026-11-12T19:00-03:00;',
            'S5;Hospital Norte;UTI;Médico;2026-11-12 07:00;2026-11-12 19:00;',
            'S6;Hospital Norte;UTI;Médico;2026-11-10T08:00-03:00;2026-11-10T09:00-03:00;P1',
            'S7;Hospital Norte;UTI;Médico;2026-11-13T07:00-03:00;2026-11-13T19:00-03:00;P2',
            'S8;Hospital Norte;UTI;Médico;2026-10-01T07:00-03:00;2026-10-01T19:00-03:00;',
        ]);

        // S1, S2 created; S3, S4, S5 rejected; S6 overlap, S7 not qualified, S8 already started → conflicts.
        $this->assertSame(['ready' => 0, 'created' => 2, 'identical' => 0, 'conflict' => 3, 'rejected' => 3], $this->counts($batch));
        $this->assertSame(8, $batch->fresh()?->received);

        $shifts = Shift::withoutTenantScope()->orderBy('id')->get();
        $this->assertCount(2, $shifts);
        $this->assertTrue($shifts->every(fn (Shift $shift) => $shift->status === ShiftStatus::Draft));
        $this->assertSame('2026-11-10 10:00', $shifts[0]->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertNull($shifts[1]->professional_id);
    }

    public function test_reimport_is_idempotent_and_changes_become_conflicts(): void
    {
        $this->import('shifts', self::HEADER, ['S1;Hospital Norte;UTI;Médico;2026-11-10T07:00-03:00;2026-11-10T19:00-03:00;P1']);

        $same = $this->import('shifts', self::HEADER, ['S1;Hospital Norte;UTI;Médico;2026-11-10T07:00-03:00;2026-11-10T19:00-03:00;P1'], confirm: false);
        $this->assertSame(1, $this->counts($same)['identical']);

        $changed = $this->import('shifts', self::HEADER, ['S1;Hospital Norte;UTI;Médico;2026-11-10T08:00-03:00;2026-11-10T19:00-03:00;P1'], confirm: false);
        $this->assertSame(1, $this->counts($changed)['conflict']);

        $this->assertSame(1, Shift::withoutTenantScope()->count());
    }
}
