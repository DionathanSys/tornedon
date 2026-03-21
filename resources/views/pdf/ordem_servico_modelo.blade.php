<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ordem de Servico</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
            color: #111827;
            margin: 28px 34px 34px 34px;
        }

        @page {
            margin: 24px 30px 34px 30px;
        }

        .page { width: 100%; }
        .clearfix::after { content: ""; display: table; clear: both; }
        .mt-8 { margin-top: 8px; }
        .mt-10 { margin-top: 10px; }
        .mt-12 { margin-top: 12px; }
        .mt-14 { margin-top: 14px; }
        .mb-8 { margin-bottom: 8px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
        .small { font-size: 10px; }
        .muted { color: #5b6777; }

        .header-table,
        .info-table,
        .items-table,
        .totals-table,
        .footer-table,
        .two-col-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td,
        .info-table td,
        .items-table th,
        .items-table td,
        .totals-table td,
        .footer-table td,
        .two-col-table td {
            border: 1px solid #cfd7df;
            padding: 7px 8px;
            vertical-align: top;
        }

        .header-table td {
            padding: 0;
        }

        .brand-box {
            width: 110px;
            height: 76px;
            background: #17385b;
            color: #ffffff;
            text-align: center;
            padding-top: 14px;
        }

        .brand-box .os {
            font-size: 26px;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 2px;
        }

        .brand-box .diesel {
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.4px;
        }

        .header-company,
        .header-order {
            padding: 10px 12px !important;
            height: 76px;
        }

        .header-company {
            width: 58%;
        }

        .header-order {
            width: 28%;
        }

        .company-name {
            font-size: 17px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .order-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .section-title {
            background: #17385b;
            color: #ffffff;
            font-size: 14px;
            font-weight: bold;
            padding: 6px 10px;
            margin-top: 10px;
        }

        .label {
            width: 92px;
            font-weight: bold;
            color: #17385b;
            background: #fbfcfd;
        }

        .items-table th {
            background: #e8eef4;
            color: #111827;
            font-weight: bold;
            text-align: center;
        }

        .items-table td.num,
        .items-table td.money,
        .items-table td.qty {
            text-align: center;
            white-space: nowrap;
        }

        .items-table td.desc {
            text-align: left;
        }

        .two-col-table td {
            width: 50%;
        }

        .box-title {
            font-weight: bold;
            margin-bottom: 3px;
        }

        .summary-wrap {
            width: 100%;
        }

        .summary-left {
            float: left;
            width: 63%;
        }

        .summary-right {
            float: right;
            width: 36%;
        }

        .totals-table td:first-child {
            width: 50%;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: bold;
            color: #17385b;
            font-size: 14px;
        }

        .total-final td {
            background: #e8eef4;
            font-weight: bold;
        }

        .signature-row {
            width: 100%;
            margin-top: 18px;
        }

        .signature-box {
            float: left;
            width: 48%;
            text-align: center;
        }

        .signature-box.right {
            float: right;
        }

        .signature-line {
            border-top: 1px solid #555;
            width: 78%;
            margin: 0 auto 3px auto;
            height: 1px;
        }

        .page-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -6px;
            font-size: 10px;
            color: #5b6777;
        }

        .page-footer .line {
            border-top: 1px solid #cfd7df;
            margin-bottom: 3px;
        }

        .footer-left { float: left; }
        .footer-right { float: right; }
    </style>
</head>
<body>
@php
    $empresa = $empresa ?? [
        'nome' => 'Oficina Atlas Diesel',
        'descricao' => 'Mecanica pesada e manutencao preventiva',
        'documento' => '12.345.678/0001-90',
        'endereco' => 'Rod. BR-282, km 515 - Chapeco/SC',
        'contato' => 'contato@atlasdiesel.com.br | (49) 3333-4455',
    ];

    $ordem = $ordem ?? [
        'numero' => 'OS-2026-00127',
        'emissao' => '21/03/2026 09:15',
        'status' => 'Em execucao',
        'cliente' => [
            'nome' => 'Transportes Vale Norte Ltda',
            'documento' => '48.221.900/0001-17',
            'contato' => 'Marcos Almeida - Compras/Frota',
            'telefone' => '(49) 99888-1122',
            'endereco' => 'Av. Senador Attilio Fontana, 850 - Chapeco/SC',
            'email' => 'frota@valenorte.com.br',
        ],
        'veiculo' => [
            'descricao' => 'Caminhao Volvo VM 330 6x2 - 2021/2022',
            'placa' => 'RXT-4J82',
            'chassi' => '9BVX4P0D9ME123456',
            'km' => '348.920 km',
        ],
        'relato_cliente' => 'Veiculo apresentou perda de potencia, fumaca escura e vibracao em marcha lenta. Solicitada verificacao do sistema de admissao, injecao e troca preventiva.',
        'diagnostico_inicial' => 'Filtro de ar saturado, correia micro-v gasta e vazamento leve na mangueira de intercooler. Scanner registrou baixa pressao de sobrealimentacao.',
        'servicos' => [
            ['item' => 1, 'descricao' => 'Diagnostico eletronico e leitura de falhas do motor', 'quantidade' => '1,0', 'valor' => 180.00],
            ['item' => 2, 'descricao' => 'Substituicao do filtro de ar do motor', 'quantidade' => '0,6', 'valor' => 180.00],
            ['item' => 3, 'descricao' => 'Troca da correia micro-v e regulagem de tensao', 'quantidade' => '1,2', 'valor' => 180.00],
            ['item' => 4, 'descricao' => 'Substituicao da mangueira do intercooler', 'quantidade' => '1,0', 'valor' => 180.00],
            ['item' => 5, 'descricao' => 'Teste final de rodagem e checklist', 'quantidade' => '0,5', 'valor' => 180.00],
        ],
        'pecas' => [
            ['item' => 1, 'descricao' => 'Filtro de ar primario Volvo VM', 'quantidade' => 1, 'valor' => 135.00],
            ['item' => 2, 'descricao' => 'Correia micro-v 8PK', 'quantidade' => 1, 'valor' => 189.00],
            ['item' => 3, 'descricao' => 'Mangueira intercooler reforcada', 'quantidade' => 1, 'valor' => 248.00],
            ['item' => 4, 'descricao' => 'Abracadeira inox 89-97 mm', 'quantidade' => 2, 'valor' => 14.50],
            ['item' => 5, 'descricao' => 'Consumiveis diversos', 'quantidade' => 1, 'valor' => 32.00],
        ],
        'observacoes' => [
            'Revisar conjunto de turbo na proxima parada preventiva.',
            'Bateria em 12,2V com motor desligado.',
            'Cliente autorizou somente os itens descritos.',
        ],
        'pagamento' => '28 dias - boleto faturado',
        'entrega' => '21/03/2026 - 17:30',
        'responsavel_tecnico' => 'Joao Pedro Martins',
        'desconto' => 57.00,
        'rodape' => 'Modelo demonstrativo com dados ficticios, pensado para oficina de linha diesel pesada.',
    ];

    $formatarMoeda = function ($valor) {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    };

    $calcularTotalLinha = function (array $item, bool $usarQuantidadeDecimal = false) {
        $quantidade = $usarQuantidadeDecimal
            ? (float) str_replace(',', '.', $item['quantidade'])
            : (float) $item['quantidade'];

        return $quantidade * (float) $item['valor'];
    };

    $subtotalServicos = collect($ordem['servicos'])->sum(function ($item) {
        return ((float) str_replace(',', '.', $item['quantidade'])) * (float) $item['valor'];
    });

    $subtotalPecas = collect($ordem['pecas'])->sum(function ($item) {
        return (float) $item['quantidade'] * (float) $item['valor'];
    });

    $desconto = (float) ($ordem['desconto'] ?? 0);
    $totalOrdem = $subtotalServicos + $subtotalPecas - $desconto;

    $dadosClienteVeiculo = [
        [
            ['label' => 'Cliente', 'value' => $ordem['cliente']['nome']],
            ['label' => 'CNPJ', 'value' => $ordem['cliente']['documento']],
        ],
        [
            ['label' => 'Contato', 'value' => $ordem['cliente']['contato']],
            ['label' => 'Telefone', 'value' => $ordem['cliente']['telefone']],
        ],
        [
            ['label' => 'Endereco', 'value' => $ordem['cliente']['endereco']],
            ['label' => 'E-mail', 'value' => $ordem['cliente']['email']],
        ],
        [
            ['label' => 'Veiculo', 'value' => $ordem['veiculo']['descricao']],
            ['label' => 'Placa', 'value' => $ordem['veiculo']['placa']],
        ],
        [
            ['label' => 'Chassi', 'value' => $ordem['veiculo']['chassi']],
            ['label' => 'KM', 'value' => $ordem['veiculo']['km']],
        ],
    ];

    $blocosTexto = [
        ['titulo' => 'Relato do cliente', 'conteudo' => $ordem['relato_cliente']],
        ['titulo' => 'Diagnostico inicial', 'conteudo' => $ordem['diagnostico_inicial']],
    ];

    $secoesItens = [
        [
            'titulo' => 'Itens de servico',
            'itens' => $ordem['servicos'],
            'usarQuantidadeDecimal' => true,
        ],
        [
            'titulo' => 'Pecas e materiais aplicados',
            'itens' => $ordem['pecas'],
            'usarQuantidadeDecimal' => false,
        ],
    ];

    $resumoTotais = [
        ['label' => 'Subtotal servicos', 'value' => $subtotalServicos],
        ['label' => 'Subtotal pecas', 'value' => $subtotalPecas],
        ['label' => 'Desconto', 'value' => $desconto],
    ];

    $dadosEncerramento = [
        ['titulo' => 'Pagamento', 'value' => $ordem['pagamento'], 'width' => '32%'],
        ['titulo' => 'Entrega', 'value' => $ordem['entrega'], 'width' => '30%'],
        ['titulo' => 'Responsavel tecnico', 'value' => $ordem['responsavel_tecnico']],
    ];
@endphp

<div class="page">
    <table class="header-table">
        <tr>
            <td style="width: 110px;">
                <div class="brand-box">
                    <div class="os">OS</div>
                    <div class="diesel">DIESEL</div>
                </div>
            </td>
            <td class="header-company">
                <div class="company-name">{{ $empresa['nome'] }}</div>
                <div>{{ $empresa['descricao'] }}</div>
                <div>CNPJ: {{ $empresa['documento'] }}</div>
                <div>{{ $empresa['endereco'] }}</div>
                <div>{{ $empresa['contato'] }}</div>
            </td>
            <td class="header-order">
                <div class="order-title">ORDEM DE SERVICO</div>
                <div><strong>No.:</strong> {{ $ordem['numero'] }}</div>
                <div><strong>Emissao:</strong> {{ $ordem['emissao'] }}</div>
                <div><strong>Status:</strong> {{ $ordem['status'] }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Dados do cliente e do veiculo</div>
    <table class="info-table">
        @foreach ($dadosClienteVeiculo as $linha)
            <tr>
                @foreach ($linha as $campo)
                    <td class="label">{{ $campo['label'] }}</td>
                    <td>{{ $campo['value'] }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <div class="section-title">Solicitacao do cliente e diagnostico inicial</div>
    <table class="two-col-table">
        <tr>
            @foreach ($blocosTexto as $bloco)
                <td>
                    <div class="box-title">{{ $bloco['titulo'] }}</div>
                    {{ $bloco['conteudo'] }}
                </td>
            @endforeach
        </tr>
    </table>

    @foreach ($secoesItens as $secao)
        <div class="section-title">{{ $secao['titulo'] }}</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 6%;">Item</th>
                    <th>Descricao</th>
                    <th style="width: 10%;">Qtd/Hr</th>
                    <th style="width: 13%;">Valor</th>
                    <th style="width: 13%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($secao['itens'] as $item)
                    @php
                        $totalItem = $calcularTotalLinha($item, $secao['usarQuantidadeDecimal']);
                    @endphp
                    <tr>
                        <td class="num">{{ $item['item'] }}</td>
                        <td class="desc">{{ $item['descricao'] }}</td>
                        <td class="qty">{{ $item['quantidade'] }}</td>
                        <td class="money">{{ $formatarMoeda($item['valor']) }}</td>
                        <td class="money">{{ $formatarMoeda($totalItem) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="summary-wrap clearfix mt-12">
        <div class="summary-left">
            <table class="info-table">
                <tr>
                    <td>
                        <div class="box-title">Observacoes tecnicas</div>
                        @foreach ($ordem['observacoes'] as $observacao)
                            - {{ $observacao }}<br>
                        @endforeach
                    </td>
                </tr>
            </table>
        </div>

        <div class="summary-right">
            <table class="totals-table">
                @foreach ($resumoTotais as $total)
                    <tr>
                        <td>{{ $total['label'] }}</td>
                        <td>{{ $formatarMoeda($total['value']) }}</td>
                    </tr>
                @endforeach
                <tr class="total-final">
                    <td>Total da ordem</td>
                    <td>{{ $formatarMoeda($totalOrdem) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="section-title">Aprovacao e encerramento</div>
    <table class="footer-table">
        <tr>
            @foreach ($dadosEncerramento as $campo)
                <td @if (! empty($campo['width'])) style="width: {{ $campo['width'] }};" @endif>
                    <div class="box-title">{{ $campo['titulo'] }}</div>
                    {{ $campo['value'] }}
                </td>
            @endforeach
        </tr>
    </table>

    <div class="signature-row clearfix">
        <div class="signature-box">
            <div class="signature-line"></div>
            <div>Assinatura do cliente / responsavel</div>
        </div>

        <div class="signature-box right">
            <div class="signature-line"></div>
            <div>Assinatura da oficina</div>
        </div>
    </div>

    <div class="mt-8 muted">{{ $ordem['rodape'] }}</div>
</div>

<div class="page-footer clearfix">
    <div class="line"></div>
    <div class="footer-left">{{ $empresa['nome'] }} - Ordem de Servico</div>
    <div class="footer-right">Pagina 1</div>
</div>
</body>
</html>
