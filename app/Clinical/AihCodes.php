<?php

namespace App\Clinical;

/**
 * Step 6b.6b2: the official SISAIH01 tables (resources/sus/aih/ORIGEM.md): code => name. Nationality and street
 * type have 3 digits (json_decode makes codes from 100 up int keys); admission character, exit reason and bed
 * specialty have 2. The table's "RESERVADO" codes are not choices.
 */
final class AihCodes
{
    /** @var array<string, array<int|string, string>> */
    private static array $tables = [];

    /**
     * @return array<int|string, string>
     */
    public static function nationalities(): array
    {
        return self::table('nacionalidades');
    }

    /**
     * @return array<int|string, string>
     */
    public static function streetTypes(): array
    {
        return self::table('tipos-logradouro');
    }

    /**
     * Step 6b.6b2b1: CAR_INTERN.
     *
     * @return array<int|string, string>
     */
    public static function characters(): array
    {
        return self::table('carater-internacao');
    }

    /**
     * Step 6b.6b2b1: MOT_SAÍDA.
     *
     * @return array<int|string, string>
     */
    public static function exitReasons(): array
    {
        return self::table('motivos-saida');
    }

    /**
     * Step 6b.6b2b1: ESPEC_AIH.
     *
     * @return array<int|string, string>
     */
    public static function bedSpecialties(): array
    {
        return self::table('especialidades-leito');
    }

    /**
     * @return array<int|string, string>
     */
    private static function table(string $name): array
    {
        if (! isset(self::$tables[$name])) {
            /** @var array<int|string, string> $table */
            $table = json_decode((string) file_get_contents(resource_path("sus/aih/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
            self::$tables[$name] = array_filter($table, fn (string $label) => $label !== 'RESERVADO');
        }

        return self::$tables[$name];
    }
}
