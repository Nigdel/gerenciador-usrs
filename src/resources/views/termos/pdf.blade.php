<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $termo->tipo->tituloDocumento() }}</title>
    <style>
        @page {
            margin: 34px 42px 42px 42px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #17211b;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            line-height: 1.55;
        }

        h1 {
            margin: 0 0 18px;
            font-size: 17px;
            letter-spacing: 0.02em;
            text-align: center;
            text-transform: uppercase;
        }

        p {
            margin: 0 0 9px;
            text-align: justify;
        }

        .cabecalho {
            margin-bottom: 18px;
            border-bottom: 1px solid #d9e2dc;
            padding-bottom: 12px;
        }

        .cabecalho table {
            width: 100%;
            border-collapse: collapse;
        }

        .cabecalho td {
            vertical-align: middle;
        }

        .logo {
            max-width: 210px;
            max-height: 62px;
        }

        .empresa {
            text-align: right;
            font-size: 9.5px;
            color: #4b5a51;
        }

        .empresa strong {
            display: block;
            color: #17211b;
            font-size: 12px;
        }

        .dados {
            width: 100%;
            margin: 12px 0 16px;
            border-collapse: collapse;
        }

        .dados th,
        .dados td {
            border: 1px solid #d9e2dc;
            padding: 7px 9px;
            vertical-align: top;
            text-align: left;
        }

        .dados th {
            width: 27%;
            background: #f7faf8;
            color: #4b5a51;
            font-size: 9px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .fotos {
            height: 105px;
            margin: 10px 0 16px;
            border: 1px dashed #a3b0a8;
            background: #fbfdfc;
            color: #a3b0a8;
            font-size: 9px;
            letter-spacing: 0.08em;
            line-height: 105px;
            text-align: center;
            text-transform: uppercase;
        }

        .clausulas {
            margin: 0 0 14px;
            padding-left: 18px;
        }

        .clausulas li {
            margin-bottom: 7px;
            text-align: justify;
        }

        .fecho {
            margin-top: 14px;
        }

        .assinatura {
            margin-top: 46px;
            text-align: center;
        }

        .assinatura .linha {
            width: 62%;
            margin: 0 auto 5px;
            border-top: 1px solid #17211b;
        }

        .assinatura .nome {
            font-weight: bold;
        }

        .rodape {
            margin-top: 34px;
            border-top: 1px solid #d9e2dc;
            padding-top: 7px;
            color: #68756d;
            font-size: 8px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="cabecalho">
        <table>
            <tr>
                <td>
                    @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="{{ $empresa['nombre'] ?? '' }}">
                    @else
                    <strong>{{ $empresa['nombre'] ?? '' }}</strong>
                    @endif
                </td>
                <td class="empresa">
                    <strong>{{ $empresa['nombre'] ?? '' }}</strong>
                    @if (! empty($empresa['cnpj']))
                    CNPJ/MF: {{ $empresa['cnpj'] }}
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <h1>{{ $termo->tipo->tituloDocumento() }}</h1>

    @php
        $fecha = $termo->generado_at?->format('d/m/Y') ?? now()->format('d/m/Y');
        $ciudad = $empresa['ciudad'] ?? 'Goiânia/GO';
        $empresaNombre = $empresa['nombre'] ?? '';
        $cnpj = $empresa['cnpj'] ?? '';
        $nombreUsuario = $usuario['nombre'] ?? '';
        $cpf = $usuario['cpf'] ?? '';
        $mac = $dispositivo['mac'] ?? null;
        $imei = $dispositivo['imei'] ?? null;
        $identificadores = array_values(array_unique(array_filter([$mac, $imei])));
        $intro = match ($termo->tipo) {
            \App\Enums\TermoTipo::Devolucion => "Nesta data {$fecha}, o(a) {$nombreUsuario}, sob nº de CPF/MF: {$cpf}, devolve à empresa {$empresaNombre}, inscrita sob nº de CNPJ/MF: {$cnpj}, os seguintes equipamentos, descrição e registros fotográficos:",
            \App\Enums\TermoTipo::Transferencia => "Nesta data {$fecha}, a empresa {$empresaNombre}, inscrita sob nº de CNPJ/MF: {$cnpj}, transfere para o(a) {$nombreUsuario}, sob nº de CPF/MF: {$cpf}, os seguintes equipamentos, descrição e registros fotográficos:",
            default => "Nesta data {$fecha}, a empresa {$empresaNombre}, inscrita sob nº de CNPJ/MF: {$cnpj}, faz a entrega para o(a) {$nombreUsuario}, sob nº de CPF/MF: {$cpf}, dos seguintes equipamentos, descrição e registros fotográficos:",
        };
    @endphp

    <p>{{ $intro }}</p>

    <table class="dados">
        <tr>
            <th>Modelo</th>
            <td>{{ $dispositivo['modelo'] ?? $dispositivo['nombre'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Processador</th>
            <td>{{ $dispositivo['procesador'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Cor</th>
            <td>{{ $dispositivo['color'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Memória</th>
            <td>{{ $dispositivo['ram'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Armazenamento</th>
            <td>{{ $dispositivo['almacenamiento'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>MAC / IMEI</th>
            <td>{{ $identificadores === [] ? '—' : implode(' / ', $identificadores) }}</td>
        </tr>
        <tr>
            <th>Estado de conservação</th>
            <td>{{ $dispositivo['estado'] ?? '—' }}</td>
        </tr>
        @if (! empty($dispositivo['serial']))
        <tr>
            <th>Nº de série</th>
            <td>{{ $dispositivo['serial'] }}</td>
        </tr>
        @endif
        @if (! empty($dispositivo['inventario']))
        <tr>
            <th>Nº de inventário</th>
            <td>{{ $dispositivo['inventario'] }}</td>
        </tr>
        @endif
    </table>

    <div class="fotos">Registro fotográfico do equipamento</div>

    <p>
        O COLABORADOR compromete-se a utilizar os equipamentos fornecidos de forma responsável,
        respeitando as políticas de segurança de dados da {{ $empresaNombre }} e os seguintes termos:
    </p>

    <ol class="clausulas">
        <li>
            O COLABORADOR compromete-se a utilizar os equipamentos exclusivamente para fins profissionais,
            evitando o uso indevido, incluindo:
            <br>Acesso a conteúdo não relacionado ao trabalho;
            <br>Instalação de software não autorizado pela {{ $empresaNombre }};
            <br>Compartilhamento de senhas e informações confidenciais com terceiros;
            <br>Uso de equipamentos para atividades ilegais ou antiéticas.
        </li>
        <li>
            O COLABORADOR concorda em manter os equipamentos em bom estado de conservação, tomando medidas
            para proteger contra danos, furto, perda ou avarias. Quaisquer problemas ou danos devem ser
            imediatamente reportados ao departamento de TI da {{ $empresaNombre }}.
        </li>
        <li>
            A {{ $empresaNombre }} recolherá os equipamentos fornecidos quando afastado de suas atividades
            devido as férias, licenças obrigatórias OU rescisão contratual. Sendo que, em casos de fornecimento
            de número celular e o e-mail funcional ficarão com a mensagem de ausência padronizada. Durante o
            período de afastamento, o aparelho celular e o notebook ficam armazenados na sede da empresa
            (equipe de TI) e guardados, sendo devolvidos ao usuário ao fim do período de afastamento.
        </li>
        <li>
            Caso o COLABORADOR exerça suas atividades em unidade de cliente (externo e em outra cidade), o
            mesmo COLABORADOR deverá, em caso de afastamento por gozo de férias, licença ou outro motivo
            previsto em legislação trabalhista, deixar o referido equipamento DESLIGADO e SEM USO durante
            todo o período, sob risco de penalidade trabalhista, em caso de infração.
        </li>
        <li>
            O COLABORADOR compromete-se a devolver os equipamentos à Empresa nas condições em que foram
            fornecidos. Em casos de danos, ficando comprovada culpa do COLABORADOR, a {{ $empresaNombre }}
            poderá descontar em seu salário, pelos termos da lei vigente.
        </li>
    </ol>

    @if (! empty($termo->seccion('termo')['observaciones']))
    <p><strong>Observações:</strong> {{ $termo->seccion('termo')['observaciones'] }}</p>
    @endif

    <p class="fecho">Ambas as partes declaram ter lido e compreendido as cláusulas deste termo, concordando com seu teor.</p>

    <p>{{ $ciudad }}, {{ $fecha }}.</p>

    <p>
        <strong>{{ $empresaNombre }}</strong><br>
        CNPJ/MF: {{ $cnpj }}
    </p>

    <div class="assinatura">
        <div class="linha"></div>
        <div class="nome">ASSINATURA DO COLABORADOR</div>
        <div>CPF/MF: {{ $cpf }}</div>
    </div>

    <div class="rodape">
        Termo nº {{ str_pad((string) $termo->id, 6, '0', STR_PAD_LEFT) }}
        · Emitido em {{ $fecha }}
        · {{ $termo->item_type }} #{{ $termo->item_id }}
    </div>
</body>
</html>
