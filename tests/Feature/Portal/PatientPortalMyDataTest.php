<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\PatientAccount;
use App\Models\PatientDataRequest;
use App\Notifications\PortalAccessLink;
use App\Tenancy\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.3b (LGPD art. 18): the patient reads the registration data and asks for a correction; the request waits
 * in the reception's list until it is marked resolved. Nothing is changed from the portal.
 */
class PatientPortalMyDataTest extends ClinicalTestCase
{
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'joana@example.com', 'phone' => '11988887777', 'status' => 'complete'])->save();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();
        $token = '';
        Notification::assertSentOnDemand(PortalAccessLink::class, function (PortalAccessLink $notification) use (&$token) {
            $token = basename($notification->url);

            return true;
        });
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $token]), ['password' => 'Senha-forte-123', 'password_confirmation' => 'Senha-forte-123']);
        $this->assertAuthenticated('patient');
    }

    private function url(string $name): string
    {
        return route($name, ['portal' => $this->slug]);
    }

    private function account(): PatientAccount
    {
        return PatientAccount::withoutTenantScope()->sole();
    }

    public function test_the_patient_reads_the_data_and_the_read_goes_to_the_trail(): void
    {
        $this->get($this->url('portal.my-data'))->assertInertia(fn (Assert $page) => $page->component('portal/my-data')
            ->where('data.name', $this->patient->name)
            ->where('data.cpf', '52998224725')
            ->where('data.phone', '11988887777')
            ->where('requests', []));

        $this->assertSame(1, DB::table('clinical_reads')->where('reason', 'portal')->where('patient_account_id', $this->account()->id)->count());
    }

    public function test_a_correction_request_waits_for_the_reception_and_changes_nothing(): void
    {
        $this->post($this->url('portal.my-data.store'), ['message' => ''])->assertSessionHasErrors(['message' => 'Conte o que precisa ser corrigido.']);
        $this->post($this->url('portal.my-data.store'), ['message' => 'Meu telefone agora é 11 97777-6666.'])->assertRedirect($this->url('portal.my-data'));
        $this->assertSame('11988887777', $this->patient->fresh()?->phone);

        $request = PatientDataRequest::withoutTenantScope()->sole();
        $this->assertSame([$this->patient->id, $this->account()->id, null], [$request->patient_id, $request->account_id, $request->resolved_at]);
        $this->assertNull(AuditEvent::where('action', 'patient_portal.correction_requested')->sole()->actor_user_id);
        $this->assertNull(AuditEvent::where('action', 'patient_portal.correction_requested')->sole()->context);

        // The reception sees it, fixes the record and marks it resolved.
        auth()->guard('patient')->logout();
        $this->actingAs($this->reception->user, 'web')->get(route('patient-data-requests.index'))->assertInertia(fn (Assert $page) => $page->component('patients/data-requests')
            ->where('requests.0.patientId', $this->patient->id)
            ->where('requests.0.message', 'Meu telefone agora é 11 97777-6666.'));
        $this->post(route('patient-data-requests.resolve', $request))->assertSessionHasNoErrors();
        $this->post(route('patient-data-requests.resolve', $request))->assertNotFound();
        $this->get(route('patient-data-requests.index'))->assertInertia(fn (Assert $page) => $page->where('requests', []));
        $this->assertSame($this->reception->user_id, AuditEvent::where('action', 'patient_portal.correction_resolved')->sole()->actor_user_id);

        // The patient sees the answer.
        auth()->guard('web')->logout();
        $this->actingAs($this->account(), 'patient')->withSession(['portal.last_seen' => now()->getTimestamp()]);
        $this->get($this->url('portal.my-data'))->assertInertia(fn (Assert $page) => $page->where('requests.0.resolved', true));
    }

    public function test_open_requests_are_limited(): void
    {
        for ($i = 1; $i <= PatientDataRequest::OPEN_LIMIT; $i++) {
            $this->post($this->url('portal.my-data.store'), ['message' => "Pedido {$i}."])->assertSessionHasNoErrors();
        }

        $this->post($this->url('portal.my-data.store'), ['message' => 'Mais um.'])->assertSessionHasErrors(['message' => 'Você já tem pedidos em aberto. Aguarde a recepção responder.']);
        $this->assertSame(PatientDataRequest::OPEN_LIMIT, PatientDataRequest::withoutTenantScope()->count());
    }

    public function test_only_who_registers_patients_sees_and_resolves_the_requests(): void
    {
        $this->post($this->url('portal.my-data.store'), ['message' => 'Nome da mãe errado.'])->assertSessionHasNoErrors();
        $request = PatientDataRequest::withoutTenantScope()->sole();
        auth()->guard('patient')->logout();

        $lab = Membership::factory()->for($this->unit->tenant)->create();
        $lab->grant(Role::Laboratory, $this->unit);
        $this->actingAs($lab->user, 'web')->get(route('patient-data-requests.index'))->assertForbidden();
        $this->post(route('patient-data-requests.resolve', $request))->assertForbidden();

        // A staff session is not a portal session.
        $this->get($this->url('portal.my-data'))->assertRedirect($this->url('portal.login'));
    }
}
