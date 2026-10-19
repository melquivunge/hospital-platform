<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Medication of the network catalog (step 4b.1a): a presentation (active ingredient, form, strength) with
 * the routes it may be given by and the network's safety rules. Deactivated, never deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $active_ingredient
 * @property string $ingredient_key normalized active ingredient, the key interactions refer to
 * @property string $form
 * @property string $strength
 * @property string $dose_unit
 * @property list<string> $routes
 * @property bool $controlled
 * @property bool $high_alert
 * @property string|null $max_single_dose
 * @property string|null $max_daily_dose
 * @property bool $active
 */
#[Fillable(['active_ingredient', 'form', 'strength', 'dose_unit', 'routes', 'controlled', 'high_alert', 'max_single_dose', 'max_daily_dose', 'active'])]
class Medication extends Model
{
    use BelongsToTenant;

    public const DOSE_UNITS = ['mg', 'mcg', 'g', 'mL', 'UI', 'gota', 'unidade', 'aplicação', 'jato'];

    public const ROUTES = [
        'oral' => 'Oral', 'sublingual' => 'Sublingual', 'enteral' => 'Enteral (sonda)', 'iv' => 'Intravenosa', 'im' => 'Intramuscular',
        'sc' => 'Subcutânea', 'inhalation' => 'Inalatória', 'nasal' => 'Nasal', 'topical' => 'Tópica', 'ophthalmic' => 'Oftálmica',
        'otic' => 'Otológica', 'rectal' => 'Retal', 'vaginal' => 'Vaginal',
    ];

    public const SEVERITIES = ['mild' => 'Leve', 'moderate' => 'Moderada', 'severe' => 'Grave', 'contraindicated' => 'Contraindicada'];

    protected static function booted(): void
    {
        static::saving(fn (Medication $medication) => $medication->ingredient_key = self::key($medication->active_ingredient));
    }

    /**
     * The key interactions match on: lowercase, single spaces. "Dipirona  Sódica" and "dipirona sódica" are one.
     */
    public static function key(string $ingredient): string
    {
        return mb_strtolower(self::clean($ingredient));
    }

    /**
     * Single spaces, trimmed, Unicode NFC (an accent typed as one or two code points is the same text).
     */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['routes' => 'array', 'controlled' => 'boolean', 'high_alert' => 'boolean', 'active' => 'boolean', 'max_single_dose' => 'decimal:3', 'max_daily_dose' => 'decimal:3'];
    }
}
