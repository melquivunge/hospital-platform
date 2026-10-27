{{-- Receipt of a private account (step 6.4b, ADR-0010): informative, no tax document. Escaped by Blade. Colors from docs/design/direction.md. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $number }} · {{ $unit }}</title>
    <style>
        @page { margin: 40px 40px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #172d36; }
        .eyebrow { font-size: 7.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #126b61; font-weight: bold; }
        h1 { font-size: 17px; margin: 4px 0 10px; }
        .meta td { padding: 2px 12px 2px 0; border: 0; }
        .meta .label { color: #54666d; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .items th { text-align: left; color: #54666d; font-weight: normal; border-bottom: 1px solid #c9d3d6; padding: 4px 6px; }
        .items td { border-bottom: 1px solid #e3e8ea; padding: 4px 6px; }
        .items .number { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-bottom: 0; padding-top: 8px; }
        .note { color: #54666d; margin-top: 18px; }
    </style>
</head>
<body>
    <div class="eyebrow">{{ $network }} · {{ $unit }}</div>
    <h1>Recibo nº {{ $number }}</h1>
    <table class="meta">
        <tr><td class="label">Paciente</td><td>{{ $patient }}@if ($cpf) · CPF {{ $cpf }}@endif</td></tr>
        <tr><td class="label">Conta</td><td>{{ $account }} (particular)</td></tr>
        <tr><td class="label">Recebido em</td><td>{{ $receivedAt }} (horário da unidade) · {{ $method }}</td></tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Data</th><th>Código</th><th>Descrição</th><th class="number">Qtd.</th><th class="number">Unitário</th><th class="number">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item['day'] }}</td>
                    <td>{{ $item['code'] }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td class="number">{{ $item['quantity'] }}</td>
                    <td class="number">{{ $item['price'] }}</td>
                    <td class="number">{{ $item['total'] }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="5">Total recebido</td><td class="number">{{ $total }}</td></tr>
        </tbody>
    </table>

    <p class="note">Recibo de pagamento de serviços hospitalares. Não substitui a nota fiscal.</p>
</body>
</html>
