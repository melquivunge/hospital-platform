<?php

namespace App\Clinical;

/**
 * Brazilian patient identifiers (delivery 3): CPF by its check digits and the Cartão Nacional de Saúde
 * by the Ministry of Health rule (definitive numbers start with 1 or 2, provisional ones with 7, 8 or 9).
 */
final class PatientIdentity
{
    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public static function validCpf(string $cpf): bool
    {
        if (preg_match('/^\d{11}$/', $cpf) !== 1 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        foreach ([9, 10] as $length) {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }
            if ((int) $cpf[$length] !== ($sum * 10) % 11 % 10) {
                return false;
            }
        }

        return true;
    }

    public static function validCns(string $cns): bool
    {
        if (preg_match('/^\d{15}$/', $cns) !== 1) {
            return false;
        }

        if (in_array($cns[0], ['1', '2'], true)) {
            $sum = 0;
            for ($i = 0; $i < 11; $i++) {
                $sum += (int) $cns[$i] * (15 - $i);
            }
            $check = 11 - $sum % 11;
            $middle = '000';
            if ($check === 11) {
                $check = 0;
            } elseif ($check === 10) {
                $check = 11 - ($sum + 2) % 11;
                $middle = '001';
            }

            return $cns === substr($cns, 0, 11).$middle.$check;
        }

        if (in_array($cns[0], ['7', '8', '9'], true)) {
            $sum = 0;
            for ($i = 0; $i < 15; $i++) {
                $sum += (int) $cns[$i] * (15 - $i);
            }

            return $sum % 11 === 0;
        }

        return false;
    }

    /** Lower case, no accents, single spaces: what duplicate suspicion compares. */
    public static function nameKey(string $name): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) $plain));
    }

    /** Only the last digits, enough to tell two people apart at the front desk. */
    public static function maskCpf(?string $cpf): ?string
    {
        return $cpf === null ? null : '•••.•••.•'.substr($cpf, 7, 2).'-'.substr($cpf, 9, 2);
    }

    public static function maskCns(?string $cns): ?string
    {
        return $cns === null ? null : '••• •••• •••• '.substr($cns, 11, 4);
    }
}
