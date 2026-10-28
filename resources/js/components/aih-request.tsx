import { Form } from '@inertiajs/react';
import { useId } from 'react';
import AihRequestController from '@/actions/App/Http/Controllers/AihRequestController';
import { CidField } from '@/components/cid-field';
import { textareaClassName } from '@/components/clinical-notes';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SigtapProcedureField } from '@/components/sigtap-procedure-field';

export type AihRequestPanel = {
    current: {
        id: number;
        version: number;
        procedure: string;
        cid: string;
        signedAt: string;
        by: string | null;
    } | null;
    canSign: boolean;
    sigtap: boolean;
};

/**
 * Step 6b.5a (ADR-0011): the AIH request report of a SUS admission. Whoever attends prints the latest one; the
 * responsible doctor signs it with the password, and a new one replaces it (the previous is kept).
 */
export function AihRequest({
    admissionId,
    panel,
}: {
    admissionId: number;
    panel: AihRequestPanel;
}) {
    const id = useId();
    const current = panel.current;

    return (
        <section className="mb-6 grid gap-3 rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <h2 className="font-medium">Laudo da AIH</h2>
                    {current ? (
                        <p className="text-sm text-muted-foreground">
                            {current.procedure} · CID {current.cid}
                            <br />
                            Assinado por {current.by} em {current.signedAt}
                            {current.version > 1 &&
                                ` (versão ${current.version})`}
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Internação SUS: o laudo vai ao gestor para a
                            autorização da AIH.
                        </p>
                    )}
                </div>
                {current && (
                    <Button asChild variant="outline" className="min-h-11">
                        <a href={AihRequestController.pdf.url(current.id)}>
                            Baixar PDF
                        </a>
                    </Button>
                )}
            </div>
            {panel.canSign &&
                (panel.sigtap ? (
                    <details className="rounded-lg border px-4 py-2">
                        <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                            {current ? 'Emitir novo laudo' : 'Emitir laudo'}
                        </summary>
                        <Form
                            {...AihRequestController.store.form(admissionId)}
                            resetOnError={['password']}
                            resetOnSuccess
                            className="grid gap-4 py-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <SigtapProcedureField
                                        label="Procedimento solicitado (SIGTAP)"
                                        error={errors.procedure_code}
                                    />
                                    <CidField required single errors={errors} />
                                    <div className="grid gap-2">
                                        <Label htmlFor={`${id}-justification`}>
                                            Justificativa clínica
                                        </Label>
                                        <textarea
                                            id={`${id}-justification`}
                                            name="justification"
                                            required
                                            maxLength={4000}
                                            placeholder="Sinais, sintomas, exames e condições que justificam a internação"
                                            className={textareaClassName}
                                        />
                                        <InputError
                                            message={errors.justification}
                                        />
                                    </div>
                                    <div className="grid max-w-sm gap-2">
                                        <Label htmlFor={`${id}-password`}>
                                            Sua senha, para assinar o laudo
                                        </Label>
                                        <Input
                                            id={`${id}-password`}
                                            name="password"
                                            type="password"
                                            autoComplete="current-password"
                                            required
                                            className="h-11"
                                        />
                                        <InputError message={errors.password} />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        O faturamento não lê a justificativa:
                                        ele registra o número da AIH quando o
                                        gestor autoriza.
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11 justify-self-start"
                                    >
                                        Assinar laudo
                                    </Button>
                                </>
                            )}
                        </Form>
                    </details>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        A tabela SIGTAP ainda não foi importada: peça ao
                        administrador da rede (tela Tabela SIGTAP).
                    </p>
                ))}
        </section>
    );
}
