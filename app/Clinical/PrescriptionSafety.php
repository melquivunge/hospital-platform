<?php

namespace App\Clinical;

use App\Models\Medication;
use App\Models\MedicationInteraction;
use App\Models\PatientAllergy;
use App\Models\PrescriptionItem;

/**
 * The safety rules of a prescription (step 4b.2a, decisions of 19/10/2026): a matching allergy, a dose above the
 * catalog maximum (per administration or per day) or an interaction with an earlier item raise an alert that the
 * prescriber must justify. An interaction is reported once, on the later of the two items. Coverage is only what
 * the network registered; the pharmacy evaluation remains the barrier.
 */
final class PrescriptionSafety
{
    /**
     * @param  array<int, PrescriptionItem>  $items  in prescription order; the last may be unsaved
     * @param  list<int>  $patientIds  the whole record (ClinicalAccess::recordIds)
     * @return list<list<string>> the alerts of each item, same order
     */
    public static function alerts(array $items, array $patientIds): array
    {
        $items = array_values($items);
        $medications = Medication::query()->whereKey(array_map(fn (PrescriptionItem $item) => $item->medication_id, $items))->get()->keyBy('id');
        $allergies = PatientAllergy::query()->whereIn('patient_id', $patientIds)->whereNull('inactivated_at')->get()->keyBy('substance_key');
        $keys = $medications->pluck('ingredient_key')->unique()->values()->all();
        $interactions = MedicationInteraction::query()->where('active', true)->whereIn('ingredient_a', $keys)->whereIn('ingredient_b', $keys)->get()
            ->keyBy(fn (MedicationInteraction $interaction) => $interaction->ingredient_a.'|'.$interaction->ingredient_b);

        $result = [];
        foreach ($items as $index => $item) {
            $medication = $medications->get($item->medication_id);
            if ($medication === null) {
                $result[] = [];

                continue;
            }
            $alerts = [];
            if (($allergy = $allergies->get(Medication::matchKey($medication->active_ingredient))) !== null) {
                $alerts[] = "Alergia registrada a {$allergy->substance} (".mb_strtolower(PatientAllergy::SEVERITIES[$allergy->severity]).').';
            }
            $unit = $item->dose_unit;
            if ($medication->max_single_dose !== null && (float) $item->dose > (float) $medication->max_single_dose) {
                $alerts[] = 'Dose acima do máximo por administração ('.self::number($medication->max_single_dose)." {$unit}).";
            }
            $daily = (float) $item->dose * $item->dosesPerDay();
            if ($medication->max_daily_dose !== null && $daily > (float) $medication->max_daily_dose) {
                $alerts[] = 'Dose do dia ('.self::number((string) $daily)." {$unit}) acima do máximo por dia (".self::number($medication->max_daily_dose)." {$unit}).";
            }
            foreach (array_slice($items, 0, $index) as $earlier) {
                $other = $medications->get($earlier->medication_id);
                if ($other === null || $other->ingredient_key === $medication->ingredient_key) {
                    continue;
                }
                $pair = [$other->ingredient_key, $medication->ingredient_key];
                sort($pair, SORT_STRING);
                if (($interaction = $interactions->get(implode('|', $pair))) !== null) {
                    $alerts[] = 'Interação '.mb_strtolower(Medication::SEVERITIES[$interaction->severity])." com {$other->active_ingredient}: {$interaction->note}";
                }
            }
            $result[] = array_values(array_unique($alerts));
        }

        return $result;
    }

    /**
     * "1.5" → "1,5"; "4000.000" → "4000".
     */
    public static function number(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',');
    }
}
