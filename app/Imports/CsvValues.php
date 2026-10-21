<?php

namespace App\Imports;

/**
 * Small conversions of CSV cells shared by the catalog importers (step 4b.1b).
 */
final class CsvValues
{
    /**
     * "sim"/"não" (also yes/no, true/false, 1/0); empty is "não". Anything else is null, which validation rejects.
     */
    public static function flag(?string $value): ?bool
    {
        return match (mb_strtolower(trim((string) $value))) {
            '', 'não', 'nao', 'n', 'no', 'false', '0' => false,
            'sim', 's', 'yes', 'true', '1' => true,
            default => null,
        };
    }

    /**
     * A number with comma or dot as decimal separator. Kept as text, so validation sees what was typed.
     * Thousands separators are not accepted; ambiguous() tells "1.000" (a thousand?) apart.
     */
    public static function decimal(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : str_replace(',', '.', $value);
    }

    /**
     * "1.000" or "2,500" may be a thousand in pt-BR or a decimal elsewhere: a dose limit read 1000 times
     * too small is a silent clinical error, so such values are refused with a clear message.
     */
    public static function ambiguous(?string $value): bool
    {
        return preg_match('/^\d{1,3}[.,]\d{3}$|[.,]\d{3}[.,]/', trim((string) $value)) === 1;
    }
}
