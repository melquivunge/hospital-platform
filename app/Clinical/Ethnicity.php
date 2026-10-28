<?php

namespace App\Clinical;

/**
 * Step 6b.6a1: the official table of Brazilian indigenous ethnicities (Portaria SAS 508/2010, Annex I), kept in the
 * repository with its origin (resources/sus/ORIGEM-etnias.md): code (4 digits) => name.
 */
final class Ethnicity
{
    /** @var array<string, string>|null */
    private static ?array $all = null;

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$all === null) {
            /** @var array<string, string> $table */
            $table = json_decode((string) file_get_contents(resource_path('sus/etnias.json')), true, flags: JSON_THROW_ON_ERROR);
            self::$all = $table;
        }

        return self::$all;
    }
}
