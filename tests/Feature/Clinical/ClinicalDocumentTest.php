<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\Membership;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

class ClinicalDocumentTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private static function png(): string
    {
        // A real 1×1 PNG: the container has no GD to draw one.
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    private function attach(string $name, string $content): ClinicalDocument
    {
        $this->actingAs($this->doctorAccount->user)->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertSessionHasNoErrors();

        return ClinicalDocument::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_attach_download_with_clinical_access_and_withdraw_keeping_the_file(): void
    {
        $document = $this->attach('Exame de sangue.pdf', self::PDF);
        $this->assertSame(['application/pdf', 'Exame de sangue.pdf', hash('sha256', self::PDF)], [$document->mime_type, $document->original_name, $document->sha256]);
        $this->assertStringStartsWith("clinical/{$this->unit->tenant_id}/", $document->path);
        Storage::disk('local')->assertExists($document->path);
        $this->attach('foto.png', self::png());

        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->has('documents', 2)->where('documents.1.name', 'Exame de sangue.pdf'));

        $reads = DB::table('clinical_reads')->count();
        $response = $this->get(route('clinical-documents.download', $document))->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame($reads + 1, DB::table('clinical_reads')->count());
        $this->assertSame(1, AuditEvent::where('action', 'clinical_document.downloaded')->count());

        // Without access no download; with an emergency grant, yes.
        $this->actingAs($this->nurseAccount->user)->get(route('clinical-documents.download', $document))->assertForbidden();
        $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.']);
        $this->get(route('clinical-documents.download', $document))->assertOk();
        $this->post(route('clinical-documents.withdraw', $document), ['reason' => 'Não é dela'])->assertForbidden();

        // Only the author withdraws, with a reason; the file stays, the download stops.
        $this->actingAs($this->doctorAccount->user)->post(route('clinical-documents.withdraw', $document), [])->assertSessionHasErrors('reason');
        $this->post(route('clinical-documents.withdraw', $document), ['reason' => 'Anexado no paciente errado'])->assertSessionHasNoErrors();
        $this->get(route('clinical-documents.download', $document))->assertStatus(410);
        Storage::disk('local')->assertExists($document->path);
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('documents.1.withdrawReason', 'Anexado no paciente errado'));
    }

    public function test_false_types_and_oversized_files_are_refused(): void
    {
        $upload = fn (UploadedFile $file) => $this->post(route('clinical-documents.store', $this->visit), ['file' => $file]);
        $this->actingAs($this->doctorAccount->user);

        $upload(UploadedFile::fake()->createWithContent('exame.pdf', '<html><script>alert(1)</script></html>'))->assertSessionHasErrors('file');
        $upload(UploadedFile::fake()->createWithContent('exame.pdf', self::png()))->assertSessionHasErrors('file');
        $upload(UploadedFile::fake()->createWithContent('programa.exe', self::PDF))->assertSessionHasErrors('file');
        $upload(UploadedFile::fake()->createWithContent('grande.pdf', self::PDF.str_repeat('0', 10 * 1024 * 1024)))->assertSessionHasErrors('file');
        $this->assertSame(0, ClinicalDocument::withoutTenantScope()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_another_client_or_role_never_reaches_the_file(): void
    {
        $document = $this->attach('exame.pdf', self::PDF);

        foreach ([$this->reception, $this->manager] as $member) {
            $this->actingAs($member->user)->get(route('clinical-documents.download', $document))->assertForbidden();
            $this->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent('x.pdf', self::PDF)])->assertForbidden();
        }
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->create();
        $this->actingAs($stranger->user)->get(route('clinical-documents.download', $document))->assertNotFound();

        // After the visit closes, the doctor no longer downloads it.
        $this->actingAs($this->doctorAccount->user)->post(route('visits.close', $this->visit), ['cid_principal' => 'J18.9'])->assertSessionHasNoErrors();
        $this->get(route('clinical-documents.download', $document))->assertForbidden();
    }

    public function test_documents_change_only_in_their_own_open_visit_and_names_are_cleaned(): void
    {
        $document = $this->attach("../../etc/passwd\n\"exame\".pdf", self::PDF);
        $this->assertSame('passwd"exame".pdf', $document->original_name);
        $this->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent('foto.jpg', self::PDF)])->assertSessionHasErrors('file');

        // A grant is read-only, and nobody uploads to another professional's visit.
        $this->actingAs($this->nurseAccount->user)->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.']);
        $this->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent('x.pdf', self::PDF)])->assertForbidden();

        // Withdrawing twice is refused; after the visit closes, a new visit does not reopen the old documents.
        $other = $this->attach('outro.pdf', self::PDF);
        $this->post(route('clinical-documents.withdraw', $other), ['reason' => 'Duplicado'])->assertSessionHasNoErrors();
        $this->post(route('clinical-documents.withdraw', $other), ['reason' => 'De novo'])->assertSessionHasErrors('reason');
        $this->post(route('visits.close', $this->visit), ['cid_principal' => 'J18.9'])->assertSessionHasNoErrors();
        $this->travelTo('2026-11-13 12:00:00');
        $next = $this->arrived($this->doctor, $this->unit, '10:00');
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $next));
        $this->post(route('clinical-documents.withdraw', $document), ['reason' => 'Mudei de ideia'])->assertForbidden();

        // A file missing on disk is a 404 and logs no read.
        Storage::disk('local')->delete($document->path);
        $reads = DB::table('clinical_reads')->count();
        $this->get(route('clinical-documents.download', $document))->assertNotFound();
        $this->assertSame($reads, DB::table('clinical_reads')->count());
    }

    public function test_the_database_keeps_documents_and_their_client_prefix(): void
    {
        $document = $this->attach('exame.pdf', self::PDF);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('clinical_documents')->first();
        unset($row['id']);

        foreach ([
            fn () => DB::table('clinical_documents')->where('id', $document->id)->update(['path' => 'clinical/999/x.pdf']),
            fn () => DB::table('clinical_documents')->where('id', $document->id)->delete(),
            fn () => DB::table('clinical_documents')->insert([...$row, 'path' => 'clinical/999/outro.pdf']),
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
