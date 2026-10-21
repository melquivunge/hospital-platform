<?php

namespace App\Imports;

/**
 * Bounded CSV reading shared by the importers (step 5): UTF-8, ";" or ",", quoted line breaks.
 */
final class CsvReader
{
    // ponytail: fixed limits; move to config when a client needs bigger files.
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_LINES = 2000;

    public const MAX_FIELDS = 20;

    public const MAX_FIELD_BYTES = 1000;

    /**
     * Parses the file into line number => row. File-level problems come back as a list of messages.
     *
     * @param  list<string>  $columns  expected header, any order
     * @return array{rows: array<int, array<string, string|null>>, errors: list<string>}
     */
    public static function parse(string $contents, array $columns): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return ['rows' => [], 'errors' => ['O arquivo precisa estar em UTF-8.']];
        }

        $headerLine = strtok($contents, "\r\n") ?: '';
        // Excel in pt-BR saves with ";"; other tools with ",".
        $delimiter = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';

        // Stream parsing keeps quoted fields with line breaks intact.
        $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Sem memória temporária para ler o arquivo.');
        fwrite($stream, $contents);
        rewind($stream);

        $header = array_map(fn ($column) => mb_strtolower(trim((string) $column)), fgetcsv($stream, 0, $delimiter, '"', '') ?: []);
        $missing = array_diff($columns, $header);
        $unknown = array_diff($header, $columns);

        if ($missing !== [] || $unknown !== []) {
            fclose($stream);

            return ['rows' => [], 'errors' => array_values(array_filter([
                $missing !== [] ? 'Colunas ausentes: '.implode(', ', $missing).'.' : null,
                $unknown !== [] ? 'Colunas desconhecidas: '.implode(', ', $unknown).'.' : null,
            ]))];
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if ($values === [null]) {
                continue;
            }

            if (count($rows) >= self::MAX_LINES) {
                fclose($stream);

                return ['rows' => [], 'errors' => ['O arquivo tem mais de '.self::MAX_LINES.' linhas de dados.']];
            }

            // Bounded work per line: a single huge line or thousands of fields cannot exhaust the request.
            if (count($values) > self::MAX_FIELDS || max(array_map(fn ($value) => strlen((string) $value), $values)) > self::MAX_FIELD_BYTES) {
                fclose($stream);

                return ['rows' => [], 'errors' => ["A linha {$line} tem campos demais ou muito longos."]];
            }

            // A text with an unquoted separator splits into extra fields: refused, never cut silently.
            if (array_filter(array_slice($values, count($header)), fn ($value) => trim((string) $value) !== '') !== []) {
                fclose($stream);

                return ['rows' => [], 'errors' => ["A linha {$line} tem mais campos que o cabeçalho. Coloque entre aspas o texto que contém o separador."]];
            }

            $row = [];

            foreach ($header as $position => $column) {
                $value = trim((string) ($values[$position] ?? ''));
                $row[$column] = $value === '' ? null : $value;
            }

            // Line numbers as the user sees them in a spreadsheet (header is line 1).
            $rows[$line] = $row;
        }

        fclose($stream);

        return ['rows' => $rows, 'errors' => []];
    }
}
