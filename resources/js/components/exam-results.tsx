import { FileText } from 'lucide-react';
import ExamResultController from '@/actions/App/Http/Controllers/ExamResultController';
import { Button } from '@/components/ui/button';

export type ExamResultVersion = {
    id: number;
    version: number;
    releasedAt: string | null;
    releasedBy: string | null;
    reason: string | null;
    report: string | null;
    attachment: { name: string; sizeKb: number } | null;
    values: {
        analyteId: number;
        name: string;
        value: string | null;
        unit: string | null;
        reference: string | null;
        flag: 'low' | 'high' | null;
    }[];
};

export type ExamResultItem = {
    id: number;
    name: string;
    type: string;
    orderedAt: string;
    orderedBy: string | null;
    versions: ExamResultVersion[];
};

const flags = { low: 'Abaixo', high: 'Acima' } as const;

/**
 * One released version (step 5.4): the values with the reference as released, the report and the PDF. A
 * rectification shows its reason; older versions stay visible below the current one.
 */
export function ResultVersion({
    result,
    current,
}: {
    result: ExamResultVersion;
    current: boolean;
}) {
    return (
        <div
            className={`grid gap-3 text-sm ${current ? '' : 'rounded-lg border border-dashed p-3 text-muted-foreground'}`}
        >
            <p className="text-xs text-muted-foreground">
                {current ? 'Liberado' : `Versão ${result.version}, substituída`}{' '}
                em {result.releasedAt} por {result.releasedBy}
                {result.version > 1 && ` · retificação: ${result.reason}`}
            </p>
            {result.values.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="w-full text-left tabular-nums">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1 pr-3 font-medium">
                                    Analito
                                </th>
                                <th className="py-1 pr-3 font-medium">Valor</th>
                                <th className="py-1 font-medium">Referência</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {result.values.map((value, index) => (
                                <tr key={index}>
                                    <td className="py-1.5 pr-3">
                                        {value.name}
                                    </td>
                                    <td
                                        className={`py-1.5 pr-3 ${value.flag ? 'font-semibold text-destructive' : ''}`}
                                    >
                                        {value.value} {value.unit}
                                        {value.flag &&
                                            ` (${flags[value.flag]})`}
                                    </td>
                                    <td className="py-1.5 text-muted-foreground">
                                        {value.reference ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {result.report && (
                <p className="whitespace-pre-line">{result.report}</p>
            )}
            {result.attachment && (
                <div>
                    <Button asChild variant="outline" className="min-h-11">
                        <a
                            href={ExamResultController.download.url(result.id)}
                            aria-label={`Baixar ${result.attachment.name}`}
                        >
                            <FileText aria-hidden="true" />
                            {result.attachment.name} ·{' '}
                            {result.attachment.sizeKb} KB
                        </a>
                    </Button>
                </div>
            )}
        </div>
    );
}

export function ResultVersions({
    versions,
}: {
    versions: ExamResultVersion[];
}) {
    return (
        <div className="grid gap-3">
            {versions.map((result, index) => (
                <ResultVersion
                    key={result.id}
                    result={result}
                    current={index === 0}
                />
            ))}
        </div>
    );
}

/**
 * The released exam results of the record (step 5.4), newest first; read-only.
 */
export function ExamResults({ results }: { results: ExamResultItem[] }) {
    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Resultados de exames</h2>
            {results.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum resultado liberado.
                </p>
            ) : (
                <ul className="grid gap-4">
                    {results.map((item) => (
                        <li
                            key={item.id}
                            className="grid gap-3 rounded-lg border p-4"
                        >
                            <div className="grid gap-0.5">
                                <span className="font-medium">{item.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    Pedido em {item.orderedAt} por{' '}
                                    {item.orderedBy}
                                </span>
                            </div>
                            <ResultVersions versions={item.versions} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
