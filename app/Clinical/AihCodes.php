<?php

namespace App\Clinical;

/**
 * Step 6b.6b2: the official SISAIH01 tables of nationality and street type (resources/sus/aih/ORIGEM.md): code (3
 * digits; json_decode makes codes from 100 up int keys) => name. The table's "RESERVADO" codes are not choices.
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
