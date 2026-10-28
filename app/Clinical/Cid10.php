<?php

namespace App\Clinical;

use Illuminate\Support\Str;

/**
 * Step 6b.1 (ADR-0011): the official CID-10 table of DATASUS (version 2008, resources/cid10/cid10.json, origin in
 * ORIGEM.md): each code without the dot (A000, or the 3-character category without subcategories) with its
 * description and the sex it is restricted to, if any.
 */
final class Cid10
{
    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $codes = null;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        return self::$codes ??= json_decode((string) file_get_contents(resource_path('cid10/cid10.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The code as typed (A00.0, a000) in the table's form (A000); null when it is not in the table. */
    public static function normalize(string $code): ?string
    {
        $code = strtoupper(str_replace('.', '', trim($code)));

        return isset(self::all()[$code]) ? $code : null;
    }

    public static function name(string $code): ?string
    {
        return self::all()[$code][0] ?? null;
    }

    /** The sex the code is restricted to (F or M), or null. */
    public static function sex(string $code): ?string
    {
        $sex = self::all()[$code][1] ?? '';

        return $sex === '' ? null : $sex;
    }

    /**
     * Codes starting with the term (A00, a00.1), else descriptions containing every word of it, accents ignored;
     * by code, up to the limit.
     * ponytail: a scan of the 12k codes (~50 ms); an indexed table if the search becomes hot.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $prefix = strtoupper(str_replace('.', '', $term));
        $words = preg_split('/\s+/', self::fold($term)) ?: [];
        $found = [];
        foreach (self::all() as $code => [$name]) {
            $folded = str_starts_with($code, $prefix) ? '' : self::fold($name);
            $match = $folded === '' || array_filter($words, fn (string $word) => ! str_contains($folded, $word)) === [];
            if ($match) {
                $found[] = ['code' => $code, 'name' => $name];
                if (count($found) === $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    private static function fold(string $text): string
    {
        return strtolower(Str::ascii($text));
    }
}
