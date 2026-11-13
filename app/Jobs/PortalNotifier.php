<?php

namespace App\Jobs;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Visit;
use App\Notifications\PortalNews;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Step 7b.3 (contract, decision 6): the portal's side of DeliverNotifications. From the same audit events it tells
 * the patient, and the guardians of a linked minor, that an appointment was booked, care started, they are next, a
 * result came out or they were discharged; an e-mail without content goes for the result, the appointment and the
 * discharge. Clinical tables are read under the event's client, then the previous context comes back.
 */
final class PortalNotifier
{
    public const ACTIONS = ['appointment.created', 'visit.opened', 'emergency.ended', 'admission.discharged', 'exam_result.released'];

    public static function deliver(\stdClass $event): void
    {
        $tenant = Tenant::query()->find((int) $event->tenant_id);
        if ($tenant === null || ! in_array($event->action, self::ACTIONS, true)) {
            return;
        }

        $current = app(CurrentTenant::class);
        $previous = $current->get();
        $current->set($tenant);
        try {
            foreach (self::notices($event) as [$patientId, $kind, $ref, $title, $mail]) {
                self::send($event, $tenant, $patientId, $kind, $ref, $title, $mail);
            }
        } finally {
            $current->set($previous);
        }
    }

    /**
     * @return list<array{int, string, string|null, string, bool}>
     */
    private static function notices(\stdClass $event): array
    {
        $context = $event->context === null ? [] : (array) json_decode($event->context, true);

        switch ($event->action) {
            case 'appointment.created':
                // A walk-in is booked at the desk with the patient there.
                return ($context['walk_in'] ?? false) ? [] : [[(int) ($context['patient_id'] ?? 0), 'appointment', null, 'Uma consulta foi marcada', true]];
            case 'visit.opened':
                $visit = Visit::query()->find((int) $event->subject_id);
                if ($visit === null) {
                    return [];
                }
                $next = $visit->appointment_id !== null ? self::nextAppointment($visit) : self::nextEpisode($visit);

                $started = [$visit->patient_id, 'started', null, 'Seu atendimento começou', false];

                return $next === null ? [$started] : [$started, $next];
            case 'emergency.ended':
                $episode = EmergencyEpisode::query()->find((int) $event->subject_id);

                return ($context['outcome'] ?? null) === 'discharged' && $episode !== null ? [[$episode->patient_id, 'discharge', null, 'Sua alta do pronto-socorro foi registrada', true]] : [];
            case 'admission.discharged':
                $admission = Admission::query()->find((int) $event->subject_id);

                // A death is never told by a notice: the team speaks to the family.
                return $admission !== null && $admission->discharge_type !== 'death' ? [[$admission->patient_id, 'discharge', null, 'Sua alta da internação foi registrada', true]] : [];
            default: // exam_result.released
                // The first release only; a critical result waits for the team's call (pending product decision).
                return ($context['version'] ?? 1) === 1 && ! ($context['critical'] ?? false)
                    ? [[(int) ($context['patient_id'] ?? 0), 'result', null, 'Um resultado de exame foi liberado', true]] : [];
        }
    }

    /**
     * Contract: "you are next" is position 1 — the first still waiting for this professional today, after this call.
     *
     * @return array{int, string, string, string, bool}|null
     */
    private static function nextAppointment(Visit $visit): ?array
    {
        $timezone = Unit::query()->whereKey($visit->unit_id)->value('timezone');
        $day = CarbonImmutable::now(is_string($timezone) ? $timezone : 'UTC')->startOfDay();
        $next = Appointment::query()->where('unit_id', $visit->unit_id)->where('professional_id', $visit->professional_id)->where('status', 'arrived')
            ->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())->orderBy('starts_at')->orderBy('id')->first();

        return $next === null ? null : [$next->patient_id, 'next', "appointment:{$next->id}", 'Você é o próximo', false];
    }

    /**
     * @return array{int, string, string, string, bool}|null
     */
    private static function nextEpisode(Visit $visit): ?array
    {
        $next = EmergencyEpisode::query()->where('unit_id', $visit->unit_id)->where('status', 'waiting')->whereNotNull('current_level')
            ->orderBy('current_level')->orderBy('arrived_at')->orderBy('id')->first();

        return $next === null ? null : [$next->patient_id, 'next', "episode:{$next->id}", 'Você é o próximo', false];
    }

    private static function send(\stdClass $event, Tenant $tenant, int $patientId, string $kind, ?string $ref, string $title, bool $mail): void
    {
        $patient = Patient::query()->find($patientId);
        if ($patient === null) {
            return;
        }
        // The patient's own accounts (also one made for a record merged into this one) and, for a minor, the guardians.
        $own = PatientAccount::query()->whereNotNull('password')
            ->whereIn('patient_id', Patient::query()->whereKey($patient->id)->orWhere('merged_into_id', $patient->id)->select('id'))->get();
        $guardians = PatientAccountLink::isMinor($patient)
            ? PatientAccount::query()->whereNotNull('password')->whereKey(PatientAccountLink::guardiansOf($patient))->get() : collect();

        foreach ([[$own, $title], [$guardians, "Menor sob sua responsabilidade: {$title}"]] as [$accounts, $text]) {
            foreach ($accounts as $account) {
                $inserted = DB::table('patient_notifications')->insertOrIgnore([
                    'tenant_id' => $tenant->id, 'account_id' => $account->id, 'audit_event_id' => $event->id,
                    'kind' => $kind, 'ref' => $ref, 'title' => $text, 'created_at' => $event->occurred_at,
                ]);
                if ($inserted > 0 && $mail) {
                    $url = route('portal.notifications', ['portal' => $tenant->slug]);
                    DB::afterCommit(fn () => Notification::route('mail', $account->email)->notify(new PortalNews($url, $tenant->name)));
                }
            }
        }
    }
}
