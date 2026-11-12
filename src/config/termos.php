<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Empresas de los termos
    |--------------------------------------------------------------------------
    |
    | Cada empresa tiene sus datos legales y su logo. La clave se compara en
    | minúsculas contra GestorUser::empresa, que es el literal que ya usa el
    | alta de usuarios ("Klios", "Federal"). El CNPJ no se inventa: queda
    | vacío y el operador lo completa al generar el termo, igual que en el
    | formulario del plugin de GLPI. Lo que se guarde en el termo queda
    | congelado en su snapshot.
    |
    */

    'empresas' => [
        'klios' => [
            'nombre' => env('TERMOS_EMPRESA_KLIOS_NOMBRE', 'Klios'),
            'cnpj' => env('TERMOS_EMPRESA_KLIOS_CNPJ', ''),
            'ciudad' => env('TERMOS_EMPRESA_KLIOS_CIUDAD', 'Goiânia/GO'),
            'logo' => 'images/termos/klios.png',
        ],
        'federal' => [
            'nombre' => env('TERMOS_EMPRESA_FEDERAL_NOMBRE', 'Federal Soluções Técnicas'),
            'cnpj' => env('TERMOS_EMPRESA_FEDERAL_CNPJ', ''),
            'ciudad' => env('TERMOS_EMPRESA_FEDERAL_CIUDAD', 'Goiânia/GO'),
            'logo' => 'images/termos/federal.png',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Empresa por defecto
    |--------------------------------------------------------------------------
    |
    | Se usa cuando la empresa del gestor no coincide con ninguna clave de
    | arriba (por ejemplo, un valor nuevo o vacío). El termo se puede generar
    | igual, pero con los datos de esta empresa.
    |
    */

    'empresa_por_defecto' => env('TERMOS_EMPRESA_DEFAULT', 'klios'),

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de los PDF
    |--------------------------------------------------------------------------
    |
    | Los PDF viven en el disco privado de Laravel. La carpeta va dentro de
    | storage/app/private, que no se publica: se sirven siempre por el
    | controlador, con la autorización de la ficha.
    |
    */

    'directorio' => 'termos',

    /*
    |--------------------------------------------------------------------------
    | PDF firmado
    |--------------------------------------------------------------------------
    |
    | El plugin de GLPI aceptaba hasta 10 MB. Se mantiene el mismo límite y se
    | valida también el MIME real del archivo, no solo la extensión.
    |
    */

    'max_pdf_kb' => (int) env('TERMOS_MAX_PDF_KB', 10240),

];
