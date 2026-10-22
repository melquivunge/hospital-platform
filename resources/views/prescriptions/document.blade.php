{{-- Canonical prescription document (step 4b.5, ADR-0006): what the certificate signs. Escaped by Blade. Colors from docs/design/direction.md. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Prescrição {{ $prescription }} · {{ $unit }}</title>
    <style>
        @page { margin: 40px 40px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #172d36; }
        .eyebrow { font-size: 7.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #126b61; font-weight: bold; }
        h1 { font-size: 17px; margin: 4px 0 10px; }
        .meta td { padding: 2px 12px 2px 0; border: 0; }
        .meta .label { color: #54666d; }
        ol { padding-left: 16px; margin-top: 14px; }
        li { margin-bottom: 9px; page-break-inside: avoid; }
        .item { font-weight: bold; }
        .detail { color: #54666d; }
        .test { border: 1px solid #b42318; color: #b42318; padding: 6px 8px; font-weight: bold; margin-bottom: 10px; }
    </style>
</head>
<body>
    @if ($test)
        <p class="test">Assinatura de teste: sem validade jurídica. Não use como prescrição.</p>
    @endif
    <div class="eyebrow">{{ $network }} · {{ $unit }}</div>
    <h1>Prescrição nº {{ $prescription }}</h1>
    <table class="meta">
        <tr><td class="label">Paciente</td><td>{{ $patient }}@if ($socialName) (nome social: {{ $socialName }})@endif</td></tr>
        @if ($birthDate)
            <tr><td class="label">Nascimento</td><td>{{ $birthDate }}</td></tr>
        @endif
        <tr><td class="label">Prescritor</td><td>{{ $professional }}@if ($council) · {{ $council }}@endif</td></tr>
        <tr><td class="label">Assinada em</td><td>{{ $signedAt }} (horário da unidade), válida até {{ $validUntil }}</td></tr>
    </table>

    <ol>
        @foreach ($items as $item)
            <li>
                <div class="item">{{ $item['medication'] }} · {{ $item['dose'] }} · {{ $item['route'] }}</div>
                <div>{{ $item['schedule'] }}, a partir de {{ $item['startsAt'] }}</div>
                @if ($item['notes'])
                    <div class="detail">Observação: {{ $item['notes'] }}</div>
                @endif
                @if ($item['justification'])
                    <div class="detail">Justificativa dos alertas: {{ $item['justification'] }}</div>
                @endif
            </li>
        @endforeach
    </ol>
</body>
</html>
