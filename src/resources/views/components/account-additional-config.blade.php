@php
    $entries = is_array($config ?? null) ? $config : [];

    // Los valores pueden ser escalares, listas de escalares o listas de
    // objetos (p. ej. los equipos de Chatwoot). Se formatean de forma legible
    // sin asumir una forma concreta.
    $formatearValor = function ($valor) use (&$formatearValor): string {
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        if ($valor === null || $valor === '') {
            return '—';
        }

        if (is_array($valor)) {
            if (! array_is_list($valor)) {
                return implode(', ', array_map(
                    fn ($item, $clave): string => $clave.': '.$formatearValor($item),
                    $valor,
                    array_keys($valor),
                ));
            }

            return implode(', ', array_map(
                function ($item) use (&$formatearValor): string {
                    if (is_array($item)) {
                        foreach (['nombre', 'name', 'label', 'descripcion'] as $campo) {
                            if (isset($item[$campo]) && is_scalar($item[$campo])) {
                                return (string) $item[$campo];
                            }
                        }
                    }

                    return $formatearValor($item);
                },
                $valor,
            ));
        }

        if (is_scalar($valor)) {
            return (string) $valor;
        }

        return (string) json_encode($valor);
    };
@endphp

@if (empty($entries))
    <span class="text-[#a3b0a8]">—</span>
@else
    <ul class="space-y-1">
        @foreach ($entries as $clave => $valor)
            <li class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-mono text-[10px] uppercase tracking-[0.06em] text-[#68756d]">{{ $clave }}</span>
                <span class="text-xs text-[#17211b]">{{ $formatearValor($valor) }}</span>
            </li>
        @endforeach
    </ul>
@endif
