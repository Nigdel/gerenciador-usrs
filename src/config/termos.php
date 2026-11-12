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
    | Generación del PDF
    |--------------------------------------------------------------------------
    |
    | Igual que el plugin de GLPI: se llena la plantilla DOCX y se convierte a
    | PDF con LibreOffice. El DOCX conserva el encabezado, el pie y el texto
    | legal originales; aquí solo se cambian los marcadores.
    |
    */

    'plantilla' => env('TERMOS_PLANTILLA') ?: resource_path('termos/termo_template.docx'),
    'libreoffice_bin' => env('TERMOS_LIBREOFFICE_BIN', '/usr/bin/libreoffice'),

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

    /*
    |--------------------------------------------------------------------------
    | Imágenes de los documentos del medio
    |--------------------------------------------------------------------------
    |
    | El plugin original adjuntaba al termo las imágenes que el medio tiene
    | como documentos en GLPI. Se copian al snapshot y se embeben en el PDF
    | para que el documento no dependa de que el archivo siga en GLPI ni de
    | que la sesión de la API siga viva.
    |
    | Los límites existen porque un documento de GLPI puede ser cualquier
    | archivo: sin ellos, un equipo con muchas capturas haría crecer el JSON
    | del termo y el PDF sin control. Las imágenes que superan el límite se
    | omiten; el termo se genera igual.
    |
    */

    'imagenes_max' => (int) env('TERMOS_IMAGENES_MAX', 8),
    'imagen_max_kb' => (int) env('TERMOS_IMAGEN_MAX_KB', 4096),
    'imagenes_total_kb' => (int) env('TERMOS_IMAGENES_TOTAL_KB', 12288),

    /*
    |--------------------------------------------------------------------------
    | Cuadrícula de fotos del termo
    |--------------------------------------------------------------------------
    |
    | El DOCX del plugin reservaba, en el lugar de "Fotos_Aqui", una cuadrícula
    | de tamaño fijo. Se respetan esas dimensiones para que todas las fotos
    | ocupen el mismo espacio, sin importar la resolución con que se subieron a
    | GLPI. Con "cover" la foto llena la celda (recortando el sobrante); con
    | "contain" se ve completa, centrada dentro de la misma celda.
    |
    */

    'imagen_columnas' => (int) env('TERMOS_IMAGEN_COLUMNAS', 3),
    'imagen_ancho' => env('TERMOS_IMAGEN_ANCHO', '5.1cm'),
    'imagen_alto' => env('TERMOS_IMAGEN_ALTO', '3.5cm'),
    'imagen_ajuste' => env('TERMOS_IMAGEN_AJUSTE', 'cover'),
    'imagen_dpi' => (int) env('TERMOS_IMAGEN_DPI', 150),

];
