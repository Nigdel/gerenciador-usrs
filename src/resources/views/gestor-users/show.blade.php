@extends('layouts.app')

@section('title', $gestorUser->nombre_completo)

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <a class="eyebrow" href="{{ route('home') }}">...Gestão de acessos</a>

                <h1 id="page-title">{{ $gestorUser->nombre_completo }}</h1>
                <p>Identidad central y cuentas externas vinculadas.</p>
            </div>
            <div class="flex gap-2">
                @can('update', $gestorUser)
                    <a class="button button-secondary" href="{{ route('gestor-users.edit', $gestorUser) }}" aria-label="Editar datos de {{ $gestorUser->nombre_completo }}" title="Editar datos"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg><span class="sr-only">Editar datos</span></a>
                @endcan
                @can('resetPassword', $gestorUser)
                    <form action="{{ route('gestor-users.reset-password', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Deseas restablecer las contraseñas de todas las cuentas?');">
                        @csrf
                        <button class="button button-secondary" type="submit" aria-label="Restablecer contraseñas de {{ $gestorUser->nombre_completo }}" title="Restablecer contraseñas"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-key"></use></svg><span class="sr-only">Restablecer contraseñas</span></button>
                    </form>
                @endcan
                @can('update', $gestorUser)
                    <form action="{{ route('gestor-users.sync-subsystems', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Propagar los datos de contacto a todas las cuentas en subsistemas?');">
                        @csrf
                        <button class="button button-secondary" type="submit" aria-label="Sincronizar datos con subsistemas de {{ $gestorUser->nombre_completo }}" title="Sincronizar datos con subsistemas"><svg class="h-4 w-4" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.6M20 20v-5h-.6M4.6 15a7.5 7.5 0 0 0 12.3 2.2L20 14M4 10l3.1-3.2A7.5 7.5 0 0 1 19.4 9" /></svg><span class="sr-only">Sincronizar datos con subsistemas</span></button>
                    </form>
                @endcan
            </div>
        </div>

        @if (session('contrasena_temporal'))
            <div class="mb-6 border border-[#d9e2dc] p-4" role="alert">
                <h2 class="mb-2 font-bold text-[#17211b]">Contraseña temporal de {{ $gestorUser->nombre_completo }}</h2>
                <p class="mb-3 text-sm text-[#68756d]">
                    Se muestra una sola vez y no queda guardada en ninguna parte.
                    Anótala y entrégasela al usuario: para volver a verla habrá que restablecerla otra vez.
                </p>
                <p class="border border-[#e8eee9] bg-[#f7faf8] px-4 py-3 font-mono text-lg text-[#17211b]">{{ session('contrasena_temporal') }}</p>
            </div>
        @endif

        @if (session('provisioning_results'))
            <div class="mb-6 border border-[#d9e2dc] p-4">
                <h2 class="mb-3 font-bold text-[#17211b]">Resultado del aprovisionamiento</h2>
                @foreach (session('provisioning_results') as $result)
                    <p class="text-sm {{ $result['exito'] ? 'text-green-700' : 'text-red-700' }}">{{ $result['subsistema'] }}: {{ $result['mensaje'] }}</p>
                @endforeach
            </div>
        @endif

        <dl class="detail-grid">
            <div><dt>CPF</dt><dd>{{ $gestorUser->cpf }}</dd></div>
            <div><dt>Usuario</dt><dd>{{ $gestorUser->usuario }}</dd></div>
            <div><dt>Empresa</dt><dd>{{ $gestorUser->empresa }}</dd></div>
            <div><dt>E-mail personal</dt><dd>{{ $gestorUser->email_personal ?: 'No informado' }}</dd></div>
            <div><dt>Teléfono personal</dt><dd>{{ $gestorUser->telefono_personal ?: 'No informado' }}</dd></div>
            <div><dt>Teléfono de trabajo</dt><dd>{{ $gestorUser->telefono_trabajo ?: 'No informado' }}</dd></div>
        </dl>

        @if ($gestorUser->estaDadoDeBaja())
            <div class="mt-6 border border-[#d9e2dc] bg-[#f7faf8] p-4" role="status">
                <h2 class="font-bold text-[#17211b]">Usuario dado de baja</h2>
                <p class="mt-1 text-sm text-[#68756d]">
                    Sus cuentas están deshabilitadas en todos los subsistemas.
                    @if ($gestorUser->baja_at)
                        Baja registrada el {{ $gestorUser->baja_at->format('d/m/Y') }}.
                    @endif
                </p>
                @if ($gestorUser->motivo_baja)
                    <p class="mt-2 text-sm text-[#68756d]">Motivo: {{ $gestorUser->motivo_baja }}</p>
                @endif

                @can('offboard', $gestorUser)
                    <form action="{{ route('gestor-users.reactivate', $gestorUser) }}" method="POST" class="mt-4" onsubmit="return confirm('¿Reactivar este usuario y sus cuentas en todos los subsistemas?');">
                        @csrf
                        <button class="button button-secondary" type="submit">Reactivar usuario</button>
                    </form>
                @endcan
            </div>
        @endif

        @can('suspend', $gestorUser)
            @if ($gestorUser->subsystemAccounts->isNotEmpty())
                <div class="mt-8 border-t border-[#d9e2dc] pt-6">
                    <h2 class="mb-4 text-xl font-bold text-[#17211b]">Suspender cuentas</h2>

                    <form action="{{ route('gestor-users.suspend', $gestorUser) }}" method="POST">
                        @csrf

                        <fieldset class="mb-4">
                            <legend class="mb-2 font-bold text-[#17211b]">Subsistemas</legend>
                            <p class="field-hint mb-2">Sin marcar ninguno, se suspenderán todas las cuentas del usuario.</p>

                            <div class="checkbox-group">
                                @foreach ($gestorUser->subsystemAccounts as $cuenta)
                                    <label class="checkbox-field" for="subsistema-{{ $cuenta->id }}">
                                        <input
                                            id="subsistema-{{ $cuenta->id }}"
                                            name="subsistemas[]"
                                            type="checkbox"
                                            value="{{ $cuenta->subsystem?->slug }}"
                                            @checked(in_array($cuenta->subsystem?->slug, old('subsistemas', []) ?? []))
                                        >
                                        <span>{{ $cuenta->subsystem?->nombre ?: 'Subsistema no disponible' }} ({{ $cuenta->credencial_usuario }})</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('subsistemas')<small class="error-message">{{ $message }}</small>@enderror
                            @error('subsistemas.*')<small class="error-message">{{ $message }}</small>@enderror
                        </fieldset>

                        <div class="field-grid">
                            <div class="field field-wide">
                                <label for="motivo_suspension">Motivo</label>
                                <input id="motivo_suspension" name="motivo_suspension" type="text" maxlength="500" required value="{{ old('motivo_suspension') }}">
                                @error('motivo_suspension')<small class="error-message">{{ $message }}</small>@enderror
                            </div>
                            <div class="field">
                                <label for="inicio_suspension">Inicio</label>
                                <input id="inicio_suspension" name="inicio_suspension" type="date" value="{{ old('inicio_suspension') }}">
                                <small class="field-hint">Si lo dejas vacío, empieza ahora.</small>
                                @error('inicio_suspension')<small class="error-message">{{ $message }}</small>@enderror
                            </div>
                            <div class="field">
                                <label for="fin_suspension">Fin</label>
                                <input id="fin_suspension" name="fin_suspension" type="date" value="{{ old('fin_suspension') }}">
                                <small class="field-hint">Opcional: sin fin, la suspensión es indefinida.</small>
                                @error('fin_suspension')<small class="error-message">{{ $message }}</small>@enderror
                            </div>
                        </div>

                        <button class="button button-secondary" type="submit" onclick="return confirm('¿Deseas suspender las cuentas marcadas?');">Suspender</button>
                    </form>
                </div>
            @endif
        @endcan

        @can('offboard', $gestorUser)
            @unless ($gestorUser->estaDadoDeBaja())
                <div class="mt-8 border border-[#d9e2dc] pt-6">
                    <h2 class="mb-1 text-xl font-bold text-[#17211b]">Dar de baja</h2>
                    <p class="mb-4 text-sm text-[#68756d]">
                        Deshabilita la cuenta del usuario en todos sus subsistemas.
                        No borra nada: se puede revertir desde aquí.
                    </p>

                    <form action="{{ route('gestor-users.offboard', $gestorUser) }}" method="POST">
                        @csrf

                        <div class="field mb-4">
                            <label for="motivo_baja">Motivo de la baja</label>
                            <input id="motivo_baja" name="motivo_baja" type="text" maxlength="500" required value="{{ old('motivo_baja') }}">
                            <small class="field-hint">Queda registrado en la ficha del usuario.</small>
                            @error('motivo_baja')<small class="error-message">{{ $message }}</small>@enderror
                        </div>

                        <button class="button button-danger" type="submit" onclick="return confirm('¿Deseas dar de baja a {{ $gestorUser->nombre_completo }}? Se deshabilitará su acceso en todos los subsistemas.');">Dar de baja</button>
                    </form>
                </div>
            @endunless
        @endcan

        <div class="mt-8">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-[#17211b]">Cuentas en subsistemas</h2>
                @can('create', [App\Models\UserSubsystemAccount::class])
                    <a class="button button-primary" href="{{ route('gestor-users.accounts.create', $gestorUser) }}">Nueva cuenta</a>
                @endcan
            </div>
            @if ($gestorUser->subsystemAccounts->isEmpty())
                <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">Este usuario no tiene cuentas vinculadas.</div>
            @else
                <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                    <table class="min-w-full border-collapse text-left">
                        <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]"><tr><th class="px-4 py-3.5 font-bold">Subsistema</th><th class="px-4 py-3.5 font-bold">Credencial</th><th class="px-4 py-3.5 font-bold">ID externo</th><th class="px-4 py-3.5 font-bold">Estado</th><th class="px-4 py-3.5 font-bold">Suspensión</th><th class="px-4 py-3.5 font-bold">Acciones</th></tr></thead>
                        <tbody>
                            @foreach ($gestorUser->subsystemAccounts as $account)
                                @php($status = $account->estado?->value ?? $account->estado)
                                <tr class="border-t border-[#e8eee9]">
                                    <td class="px-4 py-3.5 font-bold"><a href="{{ route('subsystems.show', [$account->subsystem->id]) }}">{{ $account->subsystem?->nombre ?: 'No disponible' }}</a></td>
                                    <td class="px-4 py-3.5">{{ $account->credencial_usuario }}</td><td class="px-4 py-3.5">{{ $account->external_account_id ?: 'No informado' }}</td><td class="px-4 py-3.5">@include('components.account-status', ['status' => $account->estado])</td><td class="px-4 py-3.5">
                                        @if ($account->motivo_suspension || $account->inicio_suspension || $account->fin_suspension)
                                            <div class="text-sm">
                                                @if ($account->inicio_suspension)
                                                    <div>Inicio: {{ $account->inicio_suspension->format('d/m/Y') }}</div>
                                                @endif
                                                @if ($account->fin_suspension)
                                                    <div>Fin: {{ $account->fin_suspension->format('d/m/Y') }}</div>
                                                @elseif ($account->motivo_suspension)
                                                    <div>Fin: indefinido</div>
                                                @endif
                                                @if ($account->motivo_suspension)
                                                    <div class="text-[#68756d]">{{ $account->motivo_suspension }}</div>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-[#68756d]">—</span>
                                        @endif
                                    </td><td class="px-4 py-3.5"><div class="flex flex-wrap gap-3">
                                        @if ($status === 'activo')
                                            @can('disable', $account)
                                                <form action="{{ route('subsystems.accounts.action', [$account->subsystem, $account]) }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="gestor-user">
                                                    <input type="hidden" name="operation" value="disable">
                                                    <button class="inline-flex items-center justify-center text-amber-700" type="submit" aria-label="Deshabilitar cuenta {{ $account->credencial_usuario }}" title="Deshabilitar"><svg class="h-5 w-5" aria-hidden="true"><use href="#icon-ban"></use></svg><span class="sr-only">Deshabilitar</span></button>
                                                </form>
                                            @endcan
                                        @else
                                            @can('reactivate', $account)
                                                <form action="{{ route('subsystems.accounts.action', [$account->subsystem, $account]) }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="return_to" value="gestor-user">
                                                    <input type="hidden" name="operation" value="enable">
                                                    <button class="inline-flex items-center justify-center text-[#1f5d49]" type="submit" aria-label="Habilitar cuenta {{ $account->credencial_usuario }}" title="Habilitar"><svg class="h-5 w-5" aria-hidden="true"><use href="#icon-check"></use></svg><span class="sr-only">Habilitar</span></button>
                                                </form>
                                            @endcan
                                        @endif
                                        @can('delete', $account)
                                            <form action="{{ route('subsystems.accounts.action', [$account->subsystem, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">
                                                @csrf
                                                <input type="hidden" name="return_to" value="gestor-user">
                                                <input type="hidden" name="operation" value="delete">
                                                <button class="inline-flex items-center justify-center text-red-700" type="submit" aria-label="Eliminar cuenta {{ $account->credencial_usuario }}" title="Eliminar"><svg class="h-5 w-5" aria-hidden="true"><use href="#icon-trash"></use></svg><span class="sr-only">Eliminar</span></button>
                                            </form>
                                        @endcan
                                    </div></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{--
            Operaciones (Fase 3.2).

            El panel se refresca solo con fetch nativo contra la ruta de polling:
            Alpine ya está cargado en el layout, así que no hace falta axios ni
            reconstruir assets. Cuando una operación termina se recarga la página
            una sola vez, para que la tabla de cuentas y el histórico de arriba
            muestren ya el estado final en lugar de parcheados a mano.
        --}}
        <div class="mt-8"
             @php($abiertas = $operaciones
                 ->filter(fn ($o) => ! $o->estaTerminada())
                 ->map(fn ($o) => ['url' => route('gestor-users.operaciones.show', [$gestorUser, $o->uuid])])
                 ->values())
             x-data="{
                 abiertas: @js($abiertas),
                 async consultar() {
                     if (this.abiertas.length === 0) {
                         return;
                     }

                     let quedan = 0;

                     for (const abierta of this.abiertas) {
                         const respuesta = await fetch(abierta.url);

                         // Un 404 o un error de red no se distingue aquí del
                         // final: se reintenta en el siguiente turno. Parar del
                         // todo dejaría la ficha congelada sin avisar.
                         if (! respuesta.ok) {
                             quedan++;

                             continue;
                         }

                         if (!(await respuesta.json()).terminado) {
                             quedan++;
                         }
                     }

                     // Cuando no queda ninguna, se recarga una sola vez para que
                     // las cuentas y el histórico muestren el estado final.
                     if (quedan === 0) {
                         location.reload();

                         return;
                     }

                     setTimeout(() => this.consultar(), 3000);
                 },
             }"
             x-init="consultar()">
            <h2 class="mb-4 text-xl font-bold text-[#17211b]">Operaciones</h2>
            <p class="mb-4 text-sm text-[#68756d]">
                Últimas acciones enviadas a los subsistemas. Mientras haya alguna en curso, esta tabla se actualiza sola.
            </p>

            @if ($operaciones->isEmpty())
                <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">Aún no se ha enviado ninguna operación a los subsistemas.</div>
            @else
                <ol class="border border-[#d9e2dc]">
                    @foreach ($operaciones as $operacion)
                        <li class="border-t border-[#e8eee9] px-4 py-3.5 first:border-t-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-bold text-[#17211b]">{{ $operacion->tipo->etiqueta() }}</span>
                                <span class="text-sm {{ $operacion->estaTerminada() ? 'text-[#68756d]' : 'font-bold text-amber-700' }}">
                                    {{ $operacion->estado->etiqueta() }} · {{ $operacion->resumen() }}
                                </span>
                            </div>
                            <div class="mt-1 text-sm text-[#68756d]">
                                {{ $operacion->iniciada_at?->format('d/m/Y H:i') ?: $operacion->created_at->format('d/m/Y H:i') }}
                                · por {{ $operacion->autor() }}
                                · <span class="uppercase">{{ $operacion->origen }}</span>
                            </div>

                            <ul class="mt-2 space-y-1">
                                @foreach ($operacion->cuentas as $cuenta)
                                    <li class="flex flex-wrap items-baseline gap-2 text-sm">
                                        <span class="inline-flex items-center gap-1 font-bold {{ $cuenta->estado->clase() }}">
                                            <svg class="h-4 w-4" aria-hidden="true"><use href="#{{ $cuenta->estado->icono() }}"></use></svg>
                                            {{ $cuenta->subsistema ?: 'Subsistema no disponible' }}
                                        </span>
                                        <span class="text-[#68756d]">{{ $cuenta->estado->etiqueta() }}</span>
                                        @if ($cuenta->mensaje)
                                            <span class="text-[#68756d]">— {{ $cuenta->mensaje }}</span>
                                        @endif
                                        @if ($cuenta->estado->value === 'error' && $operacion->estaTerminada())
                                            {{-- Se autoriza con la ability del tipo de operación, igual
                                                 que el POST: el botón y el controlador no pueden
                                                 discrepar. --}}
                                            @can($operacion->tipo->ability(), $gestorUser)
                                                <form method="POST"
                                                      action="{{ route('gestor-users.operaciones.retry', [$gestorUser, $operacion->uuid, $cuenta->id]) }}"
                                                      class="ml-auto">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-sm font-bold text-[#17211b] underline hover:text-emerald-800">
                                                        Reintentar
                                                    </button>
                                                </form>
                                            @endcan
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <div class="mt-8">
            <h2 class="mb-4 text-xl font-bold text-[#17211b]">Histórico</h2>
            <p class="mb-4 text-sm text-[#68756d]">Altas, cambios de estado y eliminaciones de sus cuentas en subsistemas.</p>

            @if ($historial->isEmpty())
                <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">Aún no hay cambios registrados.</div>
            @else
                <ol class="border border-[#d9e2dc]">
                    @foreach ($historial as $entrada)
                        <li class="border-t border-[#e8eee9] px-4 py-3.5 first:border-t-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-bold text-[#17211b]">{{ $entrada->descripcion }}</span>
                                <span class="text-sm text-[#68756d]">{{ $entrada->created_at->format('d/m/Y H:i') }}</span>
                            </div>
                            <div class="mt-1 text-sm text-[#68756d]">
                                {{ $entrada->subsystem?->nombre ?: 'Subsistema no disponible' }}
                                · por {{ $entrada->autor() }}
                                · <span class="uppercase">{{ $entrada->origen }}</span>
                            </div>

                            @if ($entrada->cambios)
                                <dl class="mt-2 text-sm">
                                    @foreach ($entrada->cambios as $atributo => $cambio)
                                        <div class="flex flex-wrap gap-2">
                                            <dt class="font-bold text-[#68756d]">{{ str_replace('_', ' ', $atributo) }}:</dt>
                                            <dd>
                                                @if (array_key_exists('desde', $cambio) && $cambio['desde'] !== null)
                                                    <span class="text-[#68756d] line-through">{{ $cambio['desde'] }}</span>
                                                    <span aria-hidden="true">→</span>
                                                @endif
                                                <span>{{ $cambio['hasta'] ?? '(vacío)' }}</span>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <div class="form-actions"><a class="button button-secondary" href="{{ route('gestor-users.index') }}">Volver a usuarios</a></div>
    </section>
@endsection