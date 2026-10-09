@extends('layouts.app')

@section('title', 'Operação · '.$operacion->uuid)

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <a class="eyebrow" href="{{ route('operaciones.index') }}">Operações</a>
                <h1 id="page-title">{{ $operacion->tipo->etiqueta() }}</h1>
                <p class="font-mono text-xs">{{ $operacion->uuid }}</p>
            </div>
        </div>

        <dl class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="field">
                <dt class="text-xs uppercase tracking-[0.04em] text-[#68756d]">Estado</dt>
                <dd class="mt-1">{{ $operacion->estado->etiqueta() }}</dd>
            </div>
            <div class="field">
                <dt class="text-xs uppercase tracking-[0.04em] text-[#68756d]">Usuário</dt>
                <dd class="mt-1">
                    <a class="link" href="{{ route('gestor-users.show', $operacion->usuario) }}">{{ $operacion->usuario?->nombre_completo ?? '—' }}</a>
                </dd>
            </div>
            <div class="field">
                <dt class="text-xs uppercase tracking-[0.04em] text-[#68756d]">Ator</dt>
                <dd class="mt-1">{{ $operacion->autor() }}</dd>
            </div>
            <div class="field">
                <dt class="text-xs uppercase tracking-[0.04em] text-[#68756d]">Iniciada</dt>
                <dd class="mt-1">{{ $operacion->iniciada_at?->format('d/m/Y H:i') ?? '—' }}</dd>
            </div>
            <div class="field">
                <dt class="text-xs uppercase tracking-[0.04em] text-[#68756d]">Terminada</dt>
                <dd class="mt-1">{{ $operacion->terminada_at?->format('d/m/Y H:i') ?? '—' }}</dd>
            </div>
            <div class="field">
                <dt class="text-xs uppercase tracking-[0.04em] text-[#68756d]">Resumen</dt>
                <dd class="mt-1">{{ $operacion->resumen() }}</dd>
            </div>
        </dl>

        <h2 class="mb-3 text-sm font-bold uppercase tracking-[0.04em] text-[#68756d]">Cuentas afectadas</h2>

        @if ($operacion->cuentas->isEmpty())
            <div class="border border-dashed border-[#d9e2dc] px-6 py-11 text-center text-[#68756d]">
                <strong class="block text-[#17211b]">Esta operación no tiene cuentas.</strong>
                <span>No hay detalle por subsistema que mostrar.</span>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Subsistema</th>
                            <th class="px-4 py-3.5 font-bold">Cuenta</th>
                            <th class="px-4 py-3.5 font-bold">Estado</th>
                            <th class="px-4 py-3.5 font-bold">Intentos</th>
                            <th class="px-4 py-3.5 font-bold">Mensaje</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operacion->cuentas as $fila)
                            <tr class="border-t border-[#e6ece8]">
                                <td class="px-4 py-3.5">{{ $fila->subsistema }}</td>
                                <td class="px-4 py-3.5">{{ $fila->cuenta?->user?->nombre_completo ?? '—' }}</td>
                                <td class="px-4 py-3.5">{{ $fila->estado?->etiqueta() ?? '—' }}</td>
                                <td class="px-4 py-3.5">{{ $fila->intentos }}</td>
                                <td class="px-4 py-3.5 text-[#68756d]">{{ $fila->mensaje ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection