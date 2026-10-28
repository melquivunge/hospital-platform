<?php

namespace App\Clinical;

use Illuminate\Support\Str;

/**
 * Step 6b.6b1: the official IBGE municipalities (resources/ibge/ORIGEM.md): code (7 digits, with the check digit) =>
 * [name, UF]. The SUS files carry the code without the check digit.
 */
final class Municipality
{
    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $all = null;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        if (self::$all === null) {
            /** @var array<string, array{0: string, 1: string}> $table */
            $table = json_decode((string) file_get_contents(resource_path('ibge/municipios.json')), true, flags: JSON_THROW_ON_ERROR);
            self::$all = $table;
        }

        return self::$all;
    }

    /** "São Paulo/SP", or null for an unknown code. */
    public static function label(?string $code): ?string
    {
        $municipality = $code === null ? null : (self::all()[$code] ?? null);

        return $municipality === null ? null : "{$municipality[0]}/{$municipality[1]}";
    }

    /** The code for the SUS files: without the check digit. */
    public static function sus(?string $code): ?string
    {
        return $code === null ? null : substr($code, 0, 6);
    }

    /**
     * By name words (accents ignored) or code prefix.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $words = preg_split('/\s+/', strtolower(Str::ascii($term))) ?: [];
        $start = strtolower(Str::ascii($term));
        $found = [];
        foreach (self::all() as $code => [$name, $uf]) {
            $folded = strtolower(Str::ascii("{$name} {$uf}"));
            if (str_starts_with((string) $code, $term) || array_filter($words, fn (string $word) => ! str_contains($folded, $word)) === []) {
                // Names that start with what was typed first, then the shortest ("Campinas/SP" before "Campinas do Piauí").
                $found[] = ['rank' => [str_starts_with($folded, $start) ? 0 : 1, strlen($name)], 'code' => (string) $code, 'name' => "{$name}/{$uf}"];
            }
        }
        usort($found, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return array_map(fn (array $row) => ['code' => $row['code'], 'name' => $row['name']], array_slice($found, 0, $limit));
    }
}
