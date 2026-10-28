{{-- AIH request report (step 6b.5a, ADR-0011): printed for the manager's authorization flow. Escaped by Blade. Colors from docs/design/direction.md. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Laudo da AIH · {{ $unit }}</title>
    <style>
        @page { margin: 40px 40px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #172d36; }
        .eyebrow { font-size: 7.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #126b61; font-weight: bold; }
        h1 { font-size: 17px; margin: 4px 0 10px; }
        h2 { font-size: 10px; margin: 16px 0 4px; text-transform: uppercase; letter-spacing: 1px; color: #54666d; }
        .meta td { padding: 2px 12px 2px 0; border: 0; vertical-align: top; }
        .meta .label { color: #54666d; width: 120px; }
        .justification { white-space: pre-wrap; border: 1px solid #d5dcde; padding: 8px; }
        .sign { margin-top: 28px; color: #54666d; }
    </style>
</head>
<body>
    <div class="eyebrow">{{ collect([$network, $unit, $cnes ? "CNES {$cnes}" : null])->filter()->implode(' · ') }}</div>
    <h1>Laudo para solicitação de AIH{{ $version > 1 ? " (versão {$version})" : '' }}</h1>

    <h2>Paciente</h2>
    <table class="meta">
        <tr><td class="label">Nome</td><td>{{ $patient->name }}@if ($patient->social_name) (nome social: {{ $patient->social_name }})@endif</td></tr>
        <tr><td class="label">Nascimento</td><td>{{ collect([$patient->birth_date?->format('d/m/Y') ?? 'não informado', $sex])->filter()->implode(' · ') }}</td></tr>
        <tr><td class="label">CNS</td><td>{{ collect([$patient->cns ?? 'não informado', $patient->cpf ? "CPF {$patient->cpf}" : null])->filter()->implode(' · ') }}</td></tr>
        @if ($patient->mother_name)
            <tr><td class="label">Mãe</td><td>{{ $patient->mother_name }}</td></tr>
        @endif
    </table>

    <h2>Solicitação</h2>
    <table class="meta">
        <tr><td class="label">Procedimento</td><td>{{ $procedure }}</td></tr>
        <tr><td class="label">CID-10 principal</td><td>{{ $cid }}</td></tr>
        @if ($cidSecondary)
            <tr><td class="label">CID-10 secundário</td><td>{{ $cidSecondary }}</td></tr>
        @endif
    </table>

    <h2>Justificativa clínica</h2>
    <div class="justification">{{ $justification }}</div>

    <p class="sign">
        Solicitante: {{ collect([$professional, $council, $cns ? "CNS {$cns}" : null])->filter()->implode(' · ') }}<br>
        Assinado com a senha no sistema em {{ $signedAt }} (horário da unidade).
    </p>
</body>
</html>
