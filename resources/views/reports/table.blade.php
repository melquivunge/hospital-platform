{{-- PDF export (step 9b-2): the same rows as the CSV, escaped by Blade. Colors from docs/design/direction.md. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} · {{ $unit }}</title>
    <style>
        @page { margin: 36px 36px 44px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #172d36; }
        .eyebrow { font-size: 7.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #126b61; font-weight: bold; }
        .rule { display: inline-block; width: 18px; border-top: 1px solid #8ed0c1; margin-right: 6px; vertical-align: middle; }
        h1 { font-size: 17px; margin: 4px 0 2px; }
        .meta { color: #54666d; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: bold; color: #54666d; border-bottom: 1px solid #172d36; padding: 5px 6px; }
        td { border-bottom: 1px solid #dce3e1; padding: 5px 6px; vertical-align: top; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .empty { color: #54666d; padding: 16px 0; }
        .total { margin-top: 10px; color: #54666d; }
    </style>
</head>
<body>
    <div class="eyebrow"><span class="rule"></span>{{ $network }} · {{ $unit }}</div>
    <h1>{{ $title }}</h1>
    <div class="meta">Plantões que começam de {{ $period }}, no horário da unidade · Gerado em {{ $generated }}</div>

    @if ($rows === [])
        <p class="empty">Nenhum plantão no período.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($header as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="total">{{ count($rows) }} {{ count($rows) === 1 ? 'plantão' : 'plantões' }}</p>
    @endif
</body>
</html>
