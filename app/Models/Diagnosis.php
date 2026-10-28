<?php

namespace App\Models;

use App\Clinical\Cid10;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 6b.2 (ADR-0011): a CID-10 code informed by who attends, at the close of a visit or at the discharge of an
 * admission; one principal and up to five secondary per version, a correction being a new version. Required when the
 * care is SUS (user's decision, 27/10/2026), optional otherwise.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int|null $visit_id
 * @property int|null $admission_id
 * @property int $version
 * @property string $cid_code
 * @property bool $principal
 * @property int $recorded_by_user_id
 * @property Carbon $recorded_at
 */
class Diagnosis extends Model
{
    use BelongsToTenant;

    public const MAX_SECONDARY = 5;

    public $timestamps = false;

    /**
     * The codes typed in the request (cid_principal; cid_secondary, separated by commas or spaces), checked against
     * the CID-10 table and the patient's sex: [principal, secondary], or null when nothing was typed and it is not
     * required.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function fromRequest(Request $request, bool $required, ?string $patientSex): ?array
    {
        $request->validate([
            'cid_principal' => [$required ? 'required' : 'nullable', 'string', 'max:10'],
            'cid_secondary' => ['nullable', 'string', 'max:100'],
        ], ['cid_principal.required' => 'Informe o CID-10 principal (obrigatório no SUS).'], ['cid_principal' => 'CID principal', 'cid_secondary' => 'CIDs secundários']);
        $typed = trim((string) $request->input('cid_principal'));
        $others = array_values(array_filter(preg_split('/[\s,;]+/', trim((string) $request->input('cid_secondary'))) ?: []));
        if ($typed === '' && $others === []) {
            return null;
        }
        $check = function (string $code, string $field) use ($patientSex): string {
            $normalized = Cid10::normalize($code) ?? throw ValidationException::withMessages([$field => "O CID {$code} não existe na tabela CID-10 (use o código com 4 caracteres, como J18.9)."]);
            $sex = Cid10::sex($normalized);
            if ($sex !== null && in_array($patientSex, ['female', 'male'], true) && $sex !== ($patientSex === 'female' ? 'F' : 'M')) {
                throw ValidationException::withMessages([$field => "O CID {$normalized} é exclusivo do sexo ".($sex === 'F' ? 'feminino' : 'masculino').'.']);
            }

            return $normalized;
        };
        if ($typed === '') {
            throw ValidationException::withMessages(['cid_principal' => 'Informe o CID principal antes dos secundários.']);
        }
        $principal = $check($typed, 'cid_principal');
        $secondary = array_values(array_unique(array_diff(array_map(fn (string $code) => $check($code, 'cid_secondary'), $others), [$principal])));
        if (count($secondary) > self::MAX_SECONDARY) {
            throw ValidationException::withMessages(['cid_secondary' => 'Informe no máximo '.self::MAX_SECONDARY.' CIDs secundários.']);
        }

        return [$principal, $secondary];
    }

    /**
     * Records the codes as the next version of the visit's or the admission's diagnoses. Called under the lock of
     * the visit or the admission, so two versions never collide.
     *
     * @param  array{0: string, 1: list<string>}  $codes
     */
    public static function record(Visit|Admission $of, array $codes, User $user): void
    {
        $column = $of instanceof Visit ? 'visit_id' : 'admission_id';
        $version = (int) DB::table('diagnoses')->where($column, $of->id)->max('version') + 1;
        $now = now();
        $all = [$codes[0], ...$codes[1]];
        DB::table('diagnoses')->insert(array_map(fn (string $code, int $index) => [
            'tenant_id' => $of->tenant_id, 'patient_id' => $of->patient_id, $column => $of->id, 'version' => $version,
            'cid_code' => $code, 'principal' => $index === 0, 'recorded_by_user_id' => $user->id, 'recorded_at' => $now,
        ], $all, array_keys($all)));
    }

    /**
     * The latest version's codes of a visit or an admission, the principal first, with their names.
     *
     * @return list<array{code: string, name: string|null, principal: bool}>
     */
    public static function current(string $column, int $id): array
    {
        $latest = DB::table('diagnoses')->where($column, $id)->max('version');

        return $latest === null ? [] : array_values(DB::table('diagnoses')->where($column, $id)->where('version', $latest)->orderByDesc('principal')->orderBy('id')->get()
            ->map(fn ($row) => ['code' => (string) $row->cid_code, 'name' => Cid10::name($row->cid_code), 'principal' => (bool) $row->principal])->all());
    }

    /** SUS care (the appointment's or the emergency episode's modality) requires the principal code. */
    public static function requiredForVisit(Visit $visit): bool
    {
        $modality = $visit->appointment_id !== null
            ? DB::table('appointments')->where('id', $visit->appointment_id)->value('modality')
            : DB::table('emergency_episodes')->where('id', $visit->emergency_episode_id)->value('modality');

        return $modality === 'sus';
    }

    public static function requiredForAdmission(Admission $admission): bool
    {
        return $admission->emergency_episode_id !== null && DB::table('emergency_episodes')->where('id', $admission->emergency_episode_id)->value('modality') === 'sus';
    }
}
