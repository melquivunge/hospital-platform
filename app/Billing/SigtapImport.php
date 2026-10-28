<?php

namespace App\Billing;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Step 6b.3 (ADR-0011): the official SIGTAP zip of a competence (DATASUS, "TabelaUnificada_AAAAMM_v....zip"). Its
 * fixed-width files are read through the layouts shipped in the same zip (tb_procedimento_layout.txt...), so a
 * moved column is found by its name. Kept per network and competence: the procedures with their values, sex, ages,
 * instruments of registration and compatible CID-10 and CBO codes.
 */
final class SigtapImport
{
    /** The files the import needs, each with its layout and the columns read from it. */
    private const FILES = [
        'tb_procedimento' => ['CO_PROCEDIMENTO', 'NO_PROCEDIMENTO', 'TP_SEXO', 'QT_MAXIMA_EXECUCAO', 'VL_IDADE_MINIMA', 'VL_IDADE_MAXIMA', 'VL_SH', 'VL_SA', 'VL_SP', 'DT_COMPETENCIA'],
        'rl_procedimento_registro' => ['CO_PROCEDIMENTO', 'CO_REGISTRO'],
        'rl_procedimento_cid' => ['CO_PROCEDIMENTO', 'CO_CID'],
        'rl_procedimento_ocupacao' => ['CO_PROCEDIMENTO', 'CO_OCUPACAO'],
    ];

    /** The real files are under 50 MB unpacked and their lines under 400 bytes: beyond is not the official table. */
    private const MAX_ENTRY_BYTES = 100 * 1024 * 1024;

    private const MAX_LINE_BYTES = 4096;

    /**
     * @param  array<string, array<string, mixed>>  $procedures  keyed by code
     */
    private function __construct(public readonly string $competence, public readonly array $procedures) {}

    /**
     * Reads the zip; a file that is not the official table is refused with the reason, in words for the admin.
     *
     * @throws SigtapImportException
     */
    public static function read(string $path): self
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SigtapImportException('O arquivo não é um ZIP válido.');
        }
        try {
            $layouts = [];
            foreach (self::FILES as $file => $columns) {
                $data = $zip->statName("{$file}.txt");
                $layout = $zip->getFromName("{$file}_layout.txt", 64 * 1024);
                if ($layout === false || $data === false) {
                    throw new SigtapImportException("O ZIP não tem {$file}.txt e o seu layout: use o arquivo oficial da SIGTAP (TabelaUnificada).");
                }
                if ($data['size'] > self::MAX_ENTRY_BYTES) {
                    throw new SigtapImportException("{$file}.txt é grande demais para a tabela oficial.");
                }
                $layouts[$file] = self::layout($layout, $file, $columns);
            }

            $procedures = [];
            $competences = [];
            self::lines($zip, 'tb_procedimento', function (string $line) use ($layouts, &$procedures, &$competences) {
                $row = self::row($line, $layouts['tb_procedimento']);
                if (preg_match('/^\d{10}$/', $row['CO_PROCEDIMENTO']) !== 1) {
                    throw new SigtapImportException('Linha de procedimento sem código de 10 dígitos.');
                }
                $competences[$row['DT_COMPETENCIA']] = true;
                $procedures[$row['CO_PROCEDIMENTO']] = [
                    'code' => $row['CO_PROCEDIMENTO'],
                    'name' => mb_substr($row['NO_PROCEDIMENTO'], 0, 250),
                    'sex' => in_array($row['TP_SEXO'], ['I', 'F', 'M', 'N'], true) ? $row['TP_SEXO'] : 'I',
                    // The layout's own widths bound them (4 and 12 digits); a sign or a letter reads as 0.
                    'min_age_months' => self::number($row['VL_IDADE_MINIMA'], 9999),
                    'max_age_months' => self::number($row['VL_IDADE_MAXIMA'], 9999),
                    'max_quantity' => self::number($row['QT_MAXIMA_EXECUCAO'], 9999),
                    'sh_cents' => self::number($row['VL_SH'], 999999999999),
                    'sa_cents' => self::number($row['VL_SA'], 999999999999),
                    'sp_cents' => self::number($row['VL_SP'], 999999999999),
                    'registers' => [], 'cids' => [], 'cbos' => [],
                ];
            });
            if ($procedures === [] || count($competences) !== 1 || preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', (string) array_key_first($competences), $month) !== 1) {
                throw new SigtapImportException('A tabela precisa ter procedimentos de uma só competência (AAAAMM).');
            }
            foreach (['rl_procedimento_registro' => ['registers', 'CO_REGISTRO'], 'rl_procedimento_cid' => ['cids', 'CO_CID'], 'rl_procedimento_ocupacao' => ['cbos', 'CO_OCUPACAO']] as $file => [$key, $column]) {
                self::lines($zip, $file, function (string $line) use ($layouts, $file, $key, $column, &$procedures) {
                    $row = self::row($line, $layouts[$file]);
                    if (isset($procedures[$row['CO_PROCEDIMENTO']]) && preg_match('/^[0-9A-Z]{2,6}$/', $row[$column]) === 1) {
                        $procedures[$row['CO_PROCEDIMENTO']][$key][] = $row[$column];
                    }
                });
            }

            return new self("{$month[1]}-{$month[2]}-01", $procedures);
        } finally {
            $zip->close();
        }
    }

    /**
     * Keeps the competence for the network, replacing a previous import of the same competence (DATASUS republishes
     * versions); accounts are never touched, their items keep the frozen code and value. True when it replaced one.
     */
    public function apply(int $tenantId, string $fileName, string $sha256, User $user): bool
    {
        return DB::transaction(function () use ($tenantId, $fileName, $sha256, $user) {
            // Two imports of a competence not yet kept would both insert: one waits for the other.
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["sigtap:{$tenantId}:{$this->competence}"]);
            $previous = DB::table('sigtap_competences')->where('tenant_id', $tenantId)->where('competence', $this->competence)->lockForUpdate()->first();
            if ($previous !== null) {
                DB::table('sigtap_competences')->where('id', $previous->id)->delete();
            }
            $id = DB::table('sigtap_competences')->insertGetId([
                'tenant_id' => $tenantId, 'competence' => $this->competence, 'file_name' => mb_substr($fileName, 0, 100), 'file_sha256' => $sha256,
                'procedure_count' => count($this->procedures), 'imported_by_user_id' => $user->id, 'imported_at' => now(),
            ]);
            $array = fn (array $values) => '{'.implode(',', array_values(array_unique($values))).'}';
            foreach (array_chunk($this->procedures, 500) as $chunk) {
                DB::table('sigtap_procedures')->insert(array_map(fn (array $procedure) => [
                    ...$procedure, 'tenant_id' => $tenantId, 'sigtap_competence_id' => $id,
                    'registers' => $array($procedure['registers']), 'cids' => $array($procedure['cids']), 'cbos' => $array($procedure['cbos']),
                ], $chunk));
            }
            AuditEvent::record('sigtap.imported', $tenantId, null, ['competence' => substr($this->competence, 0, 7), 'procedures' => count($this->procedures), 'sha256' => $sha256, 'replaced' => $previous !== null]);

            return $previous !== null;
        });
    }

    /**
     * The columns of a layout file ("Coluna,Tamanho,Inicio,Fim,Tipo"): name => [start (0-based), length].
     *
     * @param  list<string>  $required
     * @return array<string, array{0: int, 1: int}>
     */
    private static function layout(string $text, string $file, array $required): array
    {
        $columns = [];
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $index => $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if ($index === 0 || count($cells) < 4) {
                continue;
            }
            $columns[trim((string) $cells[0])] = [(int) $cells[2] - 1, (int) $cells[1]];
        }
        foreach ($required as $name) {
            if (! isset($columns[$name]) || $columns[$name][0] < 0 || $columns[$name][1] < 1 || $columns[$name][1] > 300) {
                throw new SigtapImportException("O layout de {$file} não tem a coluna {$name} como na tabela oficial.");
            }
        }

        return array_intersect_key($columns, array_flip($required));
    }

    /**
     * The columns of a raw ISO-8859-1 line, cut by byte position, then in UTF-8.
     *
     * @param  array<string, array{0: int, 1: int}>  $layout
     * @return array<string, string>
     */
    private static function row(string $line, array $layout): array
    {
        return array_map(fn (array $column) => trim((string) mb_convert_encoding(substr($line, $column[0], $column[1]), 'UTF-8', 'ISO-8859-1')), $layout);
    }

    /** Each non-empty raw line of a file of the zip, streamed. */
    private static function lines(ZipArchive $zip, string $file, callable $each): void
    {
        $stream = $zip->getStream("{$file}.txt") ?: throw new SigtapImportException("Não foi possível ler {$file}.txt.");
        try {
            while (($line = fgets($stream, self::MAX_LINE_BYTES)) !== false) {
                if (strlen($line) >= self::MAX_LINE_BYTES - 1 && ! str_ends_with($line, "\n")) {
                    throw new SigtapImportException("{$file}.txt tem uma linha longa demais para a tabela oficial.");
                }
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    $each($line);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** A layout number: digits only, at most the bound; anything else reads as 0. */
    private static function number(string $value, int $max): int
    {
        return ctype_digit($value) ? min((int) $value, $max) : 0;
    }
}
