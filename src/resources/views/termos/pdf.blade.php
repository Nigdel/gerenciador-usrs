<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $termo->tipo->tituloDocumento() }}</title>
    <style>
        /*
         * Esta plantilla reproduce el termo del plugin termosglpi
         * (docs_templates/termo_template.docx): A4, Times New Roman 12 pt,
         * márgenes de 2,5 cm a la derecha y abajo y 2 cm a la izquierda, con
         * el encabezado y el pie institucionales repetidos en cada página.
         */
        @page {
            size: A4 portrait;
            margin: 5.25cm 2.5cm 2.5cm 2cm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #000000;
            font-family: "Times", "Times New Roman", serif;
            font-size: 12pt;
            line-height: 1.5;
        }

        p {
            margin: 0 0 10pt;
            text-align: justify;
        }

        strong {
            font-weight: bold;
        }

        /* ── Encabezado institucional (header1.xml) ─────────────────────── */

        .cabecalho {
            position: fixed;
            top: -5.2cm;
            left: 0;
            right: 0;
            color: #000066;
            text-align: left;
        }

        .cabecalho img {
            display: block;
        }

        .cabecalho .logo-federal {
            height: 1.06cm;
        }

        .cabecalho .titulo-cabecalho {
            margin: 0.31cm 0 0;
            color: #000066;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: bold;
            line-height: 1.15;
            text-align: center;
        }

        .cabecalho .logo-klios {
            height: 1.08cm;
            margin-top: 0.35cm;
        }

        .cabecalho .meta {
            margin: 0.19cm 0 0;
            color: #000066;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1.15;
        }

        .cabecalho .meta-1 {
            margin-left: 0.21cm;
        }

        .cabecalho .meta-2 {
            margin-left: 2.19cm;
        }

        .cabecalho .meta-3 {
            margin-left: 1.39cm;
        }

        /* ── Pie institucional (footer1.xml) ────────────────────────────── */

        .rodape {
            position: fixed;
            bottom: -2.25cm;
            left: 0;
            right: 0;
            width: 100%;
        }

        .rodape table {
            width: 100%;
            border-collapse: collapse;
        }

        .rodape td {
            padding: 0;
            vertical-align: bottom;
            font-family: "Times", "Times New Roman", serif;
            font-size: 11pt;
            line-height: 1.15;
        }

        .rodape .rodape-texto {
            color: #767171;
            text-align: left;
        }

        .rodape .rodape-pagina {
            color: #000000;
            font-size: 10.5pt;
            text-align: right;
        }

        .rodape .numero::after {
            content: counter(page);
        }

        /* ── Título y datos del equipo ──────────────────────────────────── */

        .titulo-documento {
            margin: 0 0 13.5pt 4.34cm;
            color: #000000;
            font-size: 12pt;
            font-weight: bold;
            line-height: 1.08;
            text-align: left;
        }

        .intro {
            margin: 0 0 0 1.09cm;
            line-height: 1.08;
            text-align: left;
        }

        .dados {
            width: calc(100% - 1.11cm);
            margin: 0 0 0.75cm 1.11cm;
            border: 1px solid #000000;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .dados td {
            padding: 1px 5px 1px 4px;
            border: 1px solid #000000;
            vertical-align: middle;
        }

        .dados tr {
            height: 0.58cm;
        }

        .dados .rotulo {
            width: 28.3%;
            font-size: 10pt;
            font-weight: bold;
            text-align: left;
        }

        .dados .valor {
            width: 71.7%;
            font-size: 12pt;
            text-align: left;
        }

        /* ── Registro fotográfico ───────────────────────────────────────── */

        .titulo-fotos {
            margin: 0 0 13.5pt 3.79cm;
            font-size: 12pt;
            font-weight: bold;
            line-height: 1.08;
            text-align: left;
        }

        .fotos {
            width: 100%;
            margin: 0 0 1.6cm;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .fotos td {
            padding: 3px;
            text-align: center;
            vertical-align: middle;
        }

        .fotos .foto {
            display: block;
            margin: 0 auto;
            background-repeat: no-repeat;
            background-position: center center;
            background-size: cover;
        }

        /* ── Cláusulas ──────────────────────────────────────────────────── */

        .clausulas {
            margin: 0 0 10pt 0.75cm;
            padding-left: 0.5cm;
        }

        .clausulas li {
            margin-bottom: 10pt;
            text-align: justify;
        }

        .clausulas li:last-child {
            margin-bottom: 0;
        }

        /* ── Firma ──────────────────────────────────────────────────────── */

        .firma {
            margin-top: 4.4cm;
            page-break-inside: avoid;
        }

        .firma .ciudad {
            margin: 0;
            text-align: right;
        }

        .firma .linea {
            height: 0;
            margin-top: 1.05cm;
            border-top: 1px solid #000000;
        }

        .firma .linea-colaborador {
            margin-top: 1.1cm;
        }

        .firma .empresa {
            margin: 0;
            font-size: 12pt;
            line-height: 1.15;
            text-align: center;
        }

        .firma .colaborador {
            margin: 0;
            font-size: 12pt;
            line-height: 1.15;
            text-align: center;
        }
    </style>
</head>
<body>
    @php
        $fecha = $termo->generado_at?->format('d/m/Y') ?? now()->format('d/m/Y');
        $ciudad = $empresa['ciudad'] ?? 'Goiânia/GO';
        $empresaNombre = $empresa['nombre'] ?? '';
        $cnpj = $empresa['cnpj'] ?? '';
        $nombreUsuario = $usuario['nombre'] ?? '';
        $cpf = $usuario['cpf'] ?? '';
        $mac = (string) ($dispositivo['mac'] ?? '');
        $imei = (string) ($dispositivo['imei'] ?? '');
        $identificadores = $mac.$imei;
        $columnas = max(1, (int) ($fotos['columnas'] ?? 3));
        $anchoFoto = $fotos['ancho'] ?? '5.1cm';
        $altoFoto = $fotos['alto'] ?? '3.5cm';
        $ajusteFoto = $fotos['ajuste'] ?? 'cover';

        $intro = match ($termo->tipo) {
            \App\Enums\TermoTipo::Devolucion => "Nesta data {$fecha}, o(a) {$nombreUsuario}, sob nº de CPF/MF: {$cpf}, devolve à empresa {$empresaNombre}, inscrita sob nº de CNPJ/MF: {$cnpj}, os seguintes equipamentos, descrição e registros fotográficos:",
            \App\Enums\TermoTipo::Transferencia => "Nesta data {$fecha}, a empresa {$empresaNombre}, inscrita sob nº de CNPJ/MF: {$cnpj}, transfere para o(a) {$nombreUsuario}, sob nº de CPF/MF: {$cpf}, os seguintes equipamentos, descrição e registros fotográficos:",
            default => "Nesta data {$fecha}, a empresa {$empresaNombre}, inscrito sob nº de CNPJ/MF: {$cnpj}, faz a entrega para o(a) {$nombreUsuario} sob nº de CPF/MF: {$cpf} dos seguintes equipamentos, descrição e registros fotográficos:",
        };
    @endphp

    <header class="cabecalho">
        @if (! empty($logos['federal']))
        <img class="logo-federal" src="{{ $logos['federal'] }}" alt="Federal Soluções Técnicas">
        @endif

        <div class="titulo-cabecalho">Termo de Entrega de Equipamento</div>

        @if (! empty($logos['klios']))
        <img class="logo-klios" src="{{ $logos['klios'] }}" alt="Klios">
        @endif

        <div class="meta meta-1">Data Criação: 31.07.2024</div>
        <div class="meta meta-2">Última atualização: 11.09.2025</div>
        <div class="meta meta-3">Versão:2.1</div>
    </header>

    <footer class="rodape">
        <table>
            <tr>
                <td class="rodape-texto">
                    Criador: Wanderson Silva<br>
                    Revisor: Iago Gomes<br>
                    Aprovação: Evaneide Ramos
                </td>
                <td class="rodape-pagina">pág. <span class="numero"></span></td>
            </tr>
        </table>
    </footer>

    <h1 class="titulo-documento">{{ $termo->tipo->tituloDocumento() }}</h1>

    <p class="intro">{{ $intro }}</p>

    <table class="dados">
        <tr>
            <td class="rotulo">Modelo</td>
            <td class="valor">{{ $dispositivo['modelo'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="rotulo">Processador</td>
            <td class="valor">{{ $dispositivo['procesador'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="rotulo">Cor</td>
            <td class="valor">{{ $dispositivo['color'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="rotulo">Memoria</td>
            <td class="valor">{{ $dispositivo['ram'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="rotulo">Armazenamento</td>
            <td class="valor">{{ $dispositivo['almacenamiento'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="rotulo">Mac/IMEI</td>
            <td class="valor">{{ $identificadores }}</td>
        </tr>
        <tr>
            <td class="rotulo">Estado de conservação</td>
            <td class="valor">{{ $dispositivo['estado'] ?? '' }}</td>
        </tr>
    </table>

    <div class="titulo-fotos">REGISTRO FOTOGRAFICO DO EQUIPAMENTO</div>

    @if (! empty($imagenes))
    <table class="fotos">
        @foreach (array_chunk($imagenes, $columnas) as $fila)
        <tr style="page-break-inside: avoid;">
            @foreach ($fila as $imagen)
            <td style="width: {{ 100 / $columnas }}%; height: {{ $altoFoto }};">
                <div
                    class="foto"
                    style="width: {{ $anchoFoto }}; height: {{ $altoFoto }}; background-size: {{ $ajusteFoto }}; background-image: url('{{ $imagen['data_uri'] }}');"
                    title="{{ $imagen['nombre'] ?? 'Registro fotográfico do equipamento' }}"
                ></div>
            </td>
            @endforeach
            @for ($i = count($fila); $i < $columnas; $i++)
            <td style="width: {{ 100 / $columnas }}%; height: {{ $altoFoto }};"></td>
            @endfor
        </tr>
        @endforeach
    </table>
    @else
    <div style="height: {{ $altoFoto }}; margin-bottom: 1.6cm;"></div>
    @endif

    <p>
        O <strong>COLABORADOR</strong> compromete-se a utilizar os equipamentos fornecidos de forma responsável,
        respeitando as políticas de segurança de dados da {{ $empresaNombre }} e os seguintes termos:
    </p>

    <p>
        O <strong>COLABORADOR</strong> compromete-se a utilizar os equipamentos exclusivamente para fins
        profissionais, evitando o uso indevido, incluindo;
    </p>

    <ul class="clausulas">
        <li>Acesso a conteúdo não relacionado ao trabalho,</li>
        <li>Instalação de software não autorizado pela {{ $empresaNombre }},</li>
        <li>Compartilhamento de senhas e informações confidenciais com terceiros,</li>
        <li>Uso de equipamentos para atividades ilegais ou antiéticas.</li>
    </ul>

    <p>
        O <strong>COLABORADOR</strong> concorda em manter os equipamentos em bom estado de conservação,
        tomando medidas para proteger contra danos, furto, perda ou avarias. Quaisquer problemas ou danos
        devem ser imediatamente reportados ao departamento de <strong>TI</strong> da {{ $empresaNombre }};
    </p>

    <p>
        A {{ $empresaNombre }} recolherá os equipamentos fornecidos quando afastado de suas atividades
        devido as férias, licenças obrigatórias OU rescisão contratual. Sendo que, em casos de fornecimento
        de número celular e o e-mail funcional ficarão com a mensagem de ausência padronizada. Durante o
        período de afastamento, o aparelho celular e o notebook ficam armazenado na sede da empresa
        (equipe de TI) e guardado, sendo devolvido ao usuário, ao fim do período de afastamento;
    </p>

    <ul class="clausulas">
        <li>
            Caso o <strong>COLABORADOR</strong> exerça suas atividades em unidade de cliente (externo e em
            outra cidade), o mesmo <strong>COLABORADOR</strong>, deverá, em caso de afastamento por gozo de
            férias, licença ou outro motivo previsto em legislação trabalhista, deixar o referido
            equipamento DESLIGADO e SEM USO durante todo o período, sob risco de penalidade trabalhista,
            em caso de infração.
        </li>
    </ul>

    <p>
        O <strong>COLABORADOR</strong> compromete-se devolver os equipamentos à Empresa nas condições em
        que foram fornecidas, em casos de danos, que ficando comprovada culpa do
        <strong>COLABORADOR</strong> à {{ $empresaNombre }} poderão descontar em seu salário, pelos termos
        da lei vigente.
    </p>

    <p>
        Ambas as partes declaram ter lido e compreendido as cláusulas deste termo, concordando com seu teor.
    </p>

    <div class="firma">
        <p class="ciudad">{{ $ciudad }}, {{ $fecha }}</p>

        <div class="linea"></div>
        <p class="empresa"><strong>{{ $empresaNombre }}</strong></p>
        <p class="empresa">CNPJ/MF: {{ $cnpj }}</p>

        <div class="linea linea-colaborador"></div>
        <p class="colaborador">
            <strong>ASSINATURA DO COLABORADOR</strong><br>
            CPF/MF: {{ $cpf }}
        </p>
    </div>
</body>
</html>
