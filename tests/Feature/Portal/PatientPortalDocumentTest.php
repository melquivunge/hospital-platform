<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\ClinicalDocumentRelease;
use App\Models\EmergencyEpisode;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Visit;
use App\Notifications\PortalAccessLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.4: the professional who attached a document releases it to the patient's portal and may take it back
 * (insert-only history, audited); the portal lists and downloads only what is released, not revoked, not withdrawn.
 */
class PatientPortalDocumentTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $slug;

    private Visit $visit;

    private ClinicalDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'joana@example.com'])->save();

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
        $this->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent('atestado.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->document = ClinicalDocument::withoutTenantScope()->sole();
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

    private function asPatient(PatientAccount $account): void
    {
        auth()->guard('web')->logout();
        $this->actingAs($account, 'patient')->withSession(['portal.last_seen' => now()->getTimestamp()]);
    }

    private function joana(): PatientAccount
    {
        return PatientAccount::withoutTenantScope()->where('patient_id', $this->patient->id)->sole();
    }

    private function download(): string
    {
        return route('portal.document', ['portal' => $this->slug, 'document' => $this->document->id]);
    }

    private function home(): string
    {
        return route('portal.home', ['portal' => $this->slug]);
    }

    public function test_the_patient_sees_a_document_only_while_it_is_released(): void
    {
        $this->asPatient($this->joana());
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('documents', []));
        $this->get($this->download())->assertNotFound();

        $this->actingAs($this->doctorAccount->user, 'web')->post(route('clinical-documents.release', $this->document))->assertSessionHasNoErrors();
        $this->post(route('clinical-documents.release', $this->document))->assertSessionHasErrors(['release' => 'Este documento não pode ser liberado.']);

        $this->asPatient($this->joana());
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('documents.0.id', $this->document->id)->where('documents.0.name', 'atestado.pdf'));
        $this->get($this->download())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, AuditEvent::where('action', 'patient_portal.document_downloaded')->whereNull('actor_user_id')->count());

        $this->actingAs($this->doctorAccount->user, 'web')->post(route('clinical-documents.revoke', $this->document))->assertSessionHasNoErrors();
        $this->asPatient($this->joana());
        $this->get($this->download())->assertNotFound();
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('documents', []));

        $this->assertSame(['released', 'revoked'], ClinicalDocumentRelease::withoutTenantScope()->orderBy('id')->pluck('event')->all());
        $this->assertSame(
            ['clinical_document.released_to_patient', 'clinical_document.release_revoked'],
            AuditEvent::where('action', 'like', 'clinical_document.re%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_only_the_author_releases_and_a_withdrawn_document_leaves_the_portal(): void
    {
        $this->actingAs($this->nurseAccount->user, 'web')->post(route('clinical-documents.release', $this->document))->assertForbidden();

        $this->actingAs($this->doctorAccount->user, 'web')->post(route('clinical-documents.release', $this->document))->assertSessionHasNoErrors();
        $this->post(route('clinical-documents.withdraw', $this->document), ['reason' => 'Paciente errado.'])->assertSessionHasNoErrors();
        $this->post(route('clinical-documents.revoke', $this->document))->assertSessionHasErrors('release');

        $this->asPatient($this->joana());
        $this->get($this->download())->assertNotFound();
        $this->get($this->home())->assertInertia(fn (Assert $page) => $page->where('documents', []));
    }

    public function test_a_stranger_gets_404_and_the_staff_never_gets_in(): void
    {
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('clinical-documents.release', $this->document))->assertSessionHasNoErrors();
        $this->get($this->download())->assertRedirect(route('portal.login', ['portal' => $this->slug]));

        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Rui Costa', 'birth_date' => '1970-01-01', 'sex' => 'male'])->assertSessionHasNoErrors();
        $rui = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $rui->forceFill(['cpf' => '11144477735', 'email' => 'rui@example.com'])->save();
        $this->asPatient($this->activate($rui));
        $this->get($this->download())->assertNotFound();
    }
}
