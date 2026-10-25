<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A confirmed step of the WHO safe surgery checklist (step 5b.4, ADR-0009): who confirmed, when and the answer to
 * each fixed item. Never changed (trigger). Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $surgery_id
 * @property string $step sign_in | time_out | sign_out
 * @property array<string, string> $answers item => yes | no | na
 * @property int $confirmed_by_user_id
 * @property Carbon $confirmed_at
 */
#[Fillable(['tenant_id', 'surgery_id', 'step', 'answers', 'confirmed_by_user_id', 'confirmed_at'])]
class SurgeryChecklistStep extends Model
{
    use BelongsToTenant;

    public const ANSWERS = ['yes' => 'Sim', 'no' => 'Não', 'na' => 'Não se aplica'];

    /**
     * The WHO Surgical Safety Checklist, in order: each item with the answers it accepts. "Sim" on a confirmation
     * item means confirmed; on a question it means the risk is present (and the team is prepared).
     */
    public const STEPS = [
        'sign_in' => ['label' => 'Antes da indução anestésica', 'items' => [
            'identity' => ['O paciente confirmou identidade, sítio, procedimento e consentimento', ['yes']],
            'site_marked' => ['Sítio cirúrgico demarcado', ['yes', 'na']],
            'anesthesia_check' => ['Verificação de segurança anestésica concluída (equipamentos e medicamentos)', ['yes']],
            'oximeter' => ['Oxímetro de pulso no paciente e funcionando', ['yes']],
            'allergy' => ['O paciente tem alergia conhecida?', ['yes', 'no']],
            'airway' => ['Via aérea difícil ou risco de aspiração? (equipamento e assistência disponíveis)', ['yes', 'no']],
            'blood_loss' => ['Risco de perda sanguínea acima de 500 ml (7 ml/kg em crianças)? (acesso e fluidos planejados)', ['yes', 'no']],
        ]],
        'time_out' => ['label' => 'Antes da incisão cirúrgica', 'items' => [
            'team_introduced' => ['Todos os membros da equipe se apresentaram pelo nome e função', ['yes']],
            'confirmed' => ['Cirurgião, anestesista e enfermagem confirmaram paciente, sítio e procedimento', ['yes']],
            'antibiotic' => ['Antibiótico profilático administrado nos últimos 60 minutos', ['yes', 'na']],
            'critical_events' => ['Eventos críticos previstos revisados (cirurgião, anestesia e enfermagem)', ['yes']],
            'imaging' => ['Imagens essenciais disponíveis na sala', ['yes', 'na']],
        ]],
        'sign_out' => ['label' => 'Antes de o paciente sair da sala', 'items' => [
            'procedure_recorded' => ['Nome do procedimento registrado', ['yes']],
            'counts' => ['Contagem de instrumentos, compressas e agulhas correta', ['yes', 'na']],
            'specimen' => ['Peças identificadas com o nome do paciente', ['yes', 'na']],
            'equipment' => ['Houve problema com equipamento a resolver?', ['yes', 'no']],
            'recovery' => ['Cuidados para a recuperação revisados (cirurgião, anestesia e enfermagem)', ['yes']],
        ]],
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['answers' => 'array', 'confirmed_at' => 'datetime'];
    }
}
