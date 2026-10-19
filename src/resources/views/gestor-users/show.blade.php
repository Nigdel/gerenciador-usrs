@extends('layouts.app')

@section('title', $gestorUser->nombre_completo)

@section('content')
@php
// Clases reutilizables (un solo lugar para ajustar el estilo)
$btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
$btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
$btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
$btnWarning = $btnBase.' bg-amber-500 text-white hover:bg-amber-600';
$btnDanger = $btnBase.' border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus-visible:ring-red-500';
$btnIcon = 'px-3';
$input = 'w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] placeholder:text-[#a3b0a8] transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
$label = 'mb-1 block text-sm font-bold text-[#17211b]';
$hint = 'mt-1 block text-xs text-[#68756d]';
$err = 'mt-1 block text-xs font-bold text-red-700';
$iconBtn = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600';
$th = 'px-4 py-3.5 font-bold';
$card = 'rounded-xl border border-[#d9e2dc] bg-white';

// Operaciones aún abiertas (las consulta el polling de Alpine)
$abiertas = $operaciones
->filter(fn ($o) => ! $o->estaTerminada())
->map(fn ($o) => ['url' => route('gestor-users.operaciones.show', [$gestorUser, $o->uuid])])
->values();

// El panel de equipos de Chatwoot solo tiene sentido si ese subsistema está
// disponible para este gestor.
$chatwootSubsistema = $subsistemasDisponibles->firstWhere('slug', 'chatwoot');

// Reabre el modal si el error viene de sus propios campos. Se comprueba por
// prefijo porque los errores de arrays llegan con índice (subsystem_ids.0).
$crearCuentaConErrores = isset($errors) && collect($errors->getBag('default')->keys())->contains(
    static fn (string $key): bool => str_starts_with($key, 'subsystem_ids')
        || str_starts_with($key, 'password_patron')
        || str_starts_with($key, 'subsystem_config'),
);
@endphp

<section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title" x-data="{ showSuspension: false, showManagerSuspension: false, showOffboard: false, showCreateAccount: {{ $crearCuentaConErrores ? 'true' : 'false' }} }">
    {{-- Cabecera --}}
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">{{ $gestorUser->nombre_completo }}</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Identidad central y cuentas externas vinculadas.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Volver vive aquí y no al pie: la ficha es larga y llegar al
                 listado desde abajo obligaba a bajar hasta el final para
                 comprobar si había otra acción. Mismo tratamiento de icono que
                 el resto de la fila, con el texto solo para lectores de pantalla. --}}
            <a class="{{ $btnSecondary }} {{ $btnIcon }}" href="{{ route('gestor-users.index') }}" aria-label="Volver a usuarios" title="Volver a usuarios"><svg class="h-4 w-4" aria-hidden="true">
                <use href="#icon-arrow-left"></use>
            </svg><span class="sr-only">Volver a usuarios</span></a>
            @can('update', $gestorUser)
            <a class="{{ $btnSecondary }} {{ $btnIcon }}" href="{{ route('gestor-users.edit', $gestorUser) }}" aria-label="Editar datos de {{ $gestorUser->nombre_completo }}" title="Editar datos"><svg class="h-4 w-4" aria-hidden="true">
                    <use href="#icon-edit"></use>
                </svg><span class="sr-only">Editar datos</span></a>
            @endcan
            @can('offboard', $gestorUser)
            <button type="button"
                @click="showOffboard = ! showOffboard"
                class="{{ $btnDanger }} {{ $btnIcon }}"
                aria-label="Mostrar/ocultar sección de baja"
                title="Mostrar/ocultar sección de baja">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use href="#icon-user-minus"></use>
                </svg>
                <span class="sr-only">Gestão de baixa</span>
            </button>
            @endcan


            @can('suspendManager', $gestorUser)
            @if ($gestorUser->estado === \App\Enums\GestorUserStatus::Activo)
            <button class="{{ $btnSecondary }} {{ $btnIcon }}" type="button" @click="showManagerSuspension = true" aria-label="Suspender gestor {{ $gestorUser->nombre_completo }}" title="Suspender gestor"><svg class="h-4 w-4" aria-hidden="true">
                    <use href="#icon-ban"></use>
                </svg><span class="sr-only">Suspender gestor</span></button>
            @endif
            @endcan
            @if ($gestorUser->estaSuspendido())
            @can('reactivateManager', $gestorUser)
            <form action="{{ route('gestor-users.reactivate-manager', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Reactivar el gestor y las cuentas que estaban activas antes de la suspensión?');">
                @csrf
                <button class="{{ $btnSecondary }} {{ $btnIcon }}" type="submit" aria-label="Reactivar gestor {{ $gestorUser->nombre_completo }}" title="Reactivar gestor"><svg class="h-4 w-4" aria-hidden="true">
                        <use href="#icon-check"></use>
                    </svg><span class="sr-only">Reactivar gestor</span></button>
            </form>
            @endcan
            @endif
            @if ($gestorUser->estaDadoDeBaja())
            @can('offboard', $gestorUser)
            <form action="{{ route('gestor-users.reactivate', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Reactivar este usuario y sus cuentas en todos los subsistemas?');">
                @csrf
                <button class="{{ $btnSecondary }} {{ $btnIcon }}" type="submit" aria-label="Reactivar usuario {{ $gestorUser->nombre_completo }}" title="Reactivar usuario"><svg class="h-4 w-4" aria-hidden="true">
                        <use href="#icon-check"></use>
                    </svg><span class="sr-only">Reactivar usuario</span></button>
            </form>
            @endcan
            @endif

        </div>
    </div>

    {{-- Contraseña temporal --}}
    @if (session('contrasena_temporal'))
    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50/60 p-5" role="alert">
        <h2 class="mb-2 font-bold text-[#17211b]">Contraseña temporal de {{ $gestorUser->nombre_completo }}</h2>
        <p class="mb-3 text-sm text-[#68756d]">
            Se muestra una sola vez y no queda guardada en ninguna parte.
            Anótala y entrégasela al usuario: para volver a verla habrá que restablecerla otra vez.
        </p>
        <p class="select-all rounded-lg border border-[#e8eee9] bg-white px-4 py-3 font-mono text-lg text-[#17211b]">{{ session('contrasena_temporal') }}</p>
    </div>
    @endif

    {{-- Resultado del aprovisionamiento --}}
    @if (session('provisioning_results'))
    <div class="mb-6 rounded-xl border border-[#d9e2dc] bg-[#f7faf8] p-5">
        <h2 class="mb-3 font-bold text-[#17211b]">Resultado del aprovisionamiento</h2>
        <ul class="space-y-1">
            @foreach (session('provisioning_results') as $result)
            <li class="flex items-start gap-2 text-sm {{ $result['exito'] ? 'text-emerald-700' : 'text-red-700' }}">
                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $result['exito'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                <span>{{ $result['subsistema'] }}: {{ $result['mensaje'] }}</span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Dar de baja --}}
    @can('offboard', $gestorUser)
    <div class="mt-8 mb-6" x-show="showOffboard" x-cloak x-transition>
        @unless ($gestorUser->estaDadoDeBaja() || $gestorUser->estaSuspendido())
        <div class="rounded-xl border border-red-200 bg-red-50/40 p-5 sm:p-6">
            <h2 class="mb-1 text-xl font-bold text-red-800">Dar de baja</h2>
            <p class="mb-4 text-sm text-[#68756d]">
                Deshabilita la cuenta del usuario en todos sus subsistemas.
                No borra nada: se puede revertir desde aquí.
            </p>

            <form action="{{ route('gestor-users.offboard', $gestorUser) }}" method="POST">
                @csrf

                <div class="mb-4">
                    <label class="{{ $label }}" for="motivo_baja">Motivo de la baja</label>
                    <input id="motivo_baja" class="{{ $input }}" name="motivo_baja" type="text" maxlength="500" required value="{{ old('motivo_baja') }}">
                    <small class="{{ $hint }}">Queda registrado en la ficha del usuario.</small>
                    @error('motivo_baja')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <button class="{{ $btnDanger }}" type="submit" onclick="return confirm('¿Deseas dar de baja a {{ $gestorUser->nombre_completo }}? Se deshabilitará su acceso en todos los subsistemas.');">Dar de baja</button>
            </form>
        </div>
        @endunless
    </div>
    @endcan

    {{-- Datos del usuario --}}
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([
        'CPF' => $gestorUser->cpf,
        'Usuario' => $gestorUser->usuario,
        'Empresa' => $gestorUser->empresa,
        'E-mail personal' => $gestorUser->email_personal ?: 'No informado',
        'Teléfono personal' => $gestorUser->telefono_personal ?: 'No informado',
        'Teléfono de trabajo' => $gestorUser->telefono_trabajo ?: 'No informado',
        ] as $etiqueta => $valor)
        <div class="rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4">
            <dt class="mb-1 text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">{{ $etiqueta }}</dt>
            <dd class="break-words text-[#17211b] {{ $valor === 'No informado' ? 'text-[#a3b0a8]' : '' }}">{{ $valor }}</dd>
        </div>
        @endforeach
    </dl>

    @if ($gestorUser->estaSuspendido())
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/60 p-5" role="status">
        <h2 class="font-bold text-amber-900">Gestor suspendido</h2>
        <p class="mt-1 text-sm text-[#68756d]">Las cuentas que estaban activas al suspenderlo se deshabilitaron. Las demás conservaron su estado.</p>
        @if ($gestorUser->suspendido_at)
        <p class="mt-2 text-sm text-[#68756d]">Suspensión registrada el {{ $gestorUser->suspendido_at->format('d/m/Y') }}.</p>
        @endif
        @if ($gestorUser->fin_suspension_gestor)
        <p class="mt-2 text-sm text-[#68756d]">Fin previsto de la suspensión: {{ $gestorUser->fin_suspension_gestor->format('d/m/Y') }}.</p>
        @endif
        @if ($gestorUser->motivo_suspension_gestor)
        <p class="mt-2 text-sm text-[#68756d]">Motivo: {{ $gestorUser->motivo_suspension_gestor }}</p>
        @endif
    </div>
    @endif

    {{-- Usuario dado de baja --}}
    @if ($gestorUser->estaDadoDeBaja())
    <div class="mt-6 rounded-xl border border-red-200 bg-red-50/60 p-5" role="status">
        <h2 class="font-bold text-red-800">Usuario dado de baja</h2>
        <p class="mt-1 text-sm text-[#68756d]">
            Sus cuentas están deshabilitadas en todos los subsistemas.
            @if ($gestorUser->baja_at)
            Baja registrada el {{ $gestorUser->baja_at->format('d/m/Y') }}.
            @endif
        </p>
        @if ($gestorUser->motivo_baja)
        <p class="mt-2 text-sm text-[#68756d]">Motivo: {{ $gestorUser->motivo_baja }}</p>
        @endif
    </div>
    @endif

    {{-- Suspensión del gestor y captura de sus cuentas activas --}}
    @can('suspendManager', $gestorUser)
    @if ($gestorUser->estado === \App\Enums\GestorUserStatus::Activo)
    <div x-show="showManagerSuspension"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
        <div class="absolute inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="showManagerSuspension = false"></div>
        <div class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Suspender gestor</h2>
                <button type="button" @click="showManagerSuspension = false" class="text-[#68756d] hover:text-[#17211b]" aria-label="Cerrar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <form action="{{ route('gestor-users.suspend-manager', $gestorUser) }}" method="POST" class="p-6">
                @csrf
                <p class="mb-4 text-sm text-[#68756d]">Se deshabilitarán únicamente las cuentas activas ahora. Se guardará esa selección para reactivar solo esas mismas cuentas.</p>
                <label class="{{ $label }}" for="motivo_suspension_gestor">Motivo</label>
                <input id="motivo_suspension_gestor" class="{{ $input }}" name="motivo_suspension_gestor" type="text" minlength="5" maxlength="500" required value="{{ old('motivo_suspension_gestor') }}">
                @error('motivo_suspension_gestor')<small class="{{ $err }}">{{ $message }}</small>@enderror
                <label class="{{ $label }} mt-4" for="fin_suspension_gestor">Fecha de fin (opcional)</label>
                <input id="fin_suspension_gestor" class="{{ $input }}" name="fin_suspension_gestor" type="date" min="{{ now()->toDateString() }}" value="{{ old('fin_suspension_gestor') }}">
                @error('fin_suspension_gestor')<small class="{{ $err }}">{{ $message }}</small>@enderror
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="showManagerSuspension = false" class="{{ $btnSecondary }}">Cancelar</button>
                    <button class="{{ $btnDanger }}" type="submit" onclick="return confirm('¿Suspender al gestor y deshabilitar sus cuentas activas?');">Suspender gestor</button>
                </div>
            </form>
        </div>
    </div>
    @endif
    @endcan

    {{-- Suspender cuentas --}}
    @can('suspend', $gestorUser)
    @if ($gestorUser->subsystemAccounts->isNotEmpty())
    <div x-show="showSuspension"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">

        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="showSuspension = false"></div>

        {{-- Modal Content --}}
        <div class="relative w-full max-w-2xl rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Suspender cuentas</h2>
                <button type="button" @click="showSuspension = false" class="text-[#68756d] hover:text-[#17211b]">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="p-6">
                <form action="{{ route('gestor-users.suspend', $gestorUser) }}" method="POST">
                    @csrf

                    <fieldset class="mb-6">
                        <legend class="mb-2 text-sm font-bold text-[#17211b]">Subsistemas</legend>
                        <p class="mb-4 text-xs text-[#68756d]">Sin marcar ninguno, se suspenderán todas las cuentas del usuario.</p>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($gestorUser->subsystemAccounts as $cuenta)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] transition hover:bg-[#f7faf8] has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/50" for="subsistema-{{ $cuenta->id }}">
                                <input
                                    id="subsistema-{{ $cuenta->id }}"
                                    class="h-4 w-4 rounded border-[#d9e2dc] text-emerald-700 focus:ring-emerald-600"
                                    name="subsistemas[]"
                                    type="checkbox"
                                    value="{{ $cuenta->subsystem?->slug }}"
                                    @checked(in_array($cuenta->subsystem?->slug, old('subsistemas', []) ?? []))
                                >
                                <span>{{ $cuenta->subsystem?->nombre ?: 'Subsistema no disponible' }} <span class="font-mono text-xs text-[#68756d]">({{ $cuenta->credencial_usuario }})</span></span>
                            </label>
                            @endforeach
                        </div>
                        @error('subsistemas')<small class="{{ $err }}">{{ $message }}</small>@enderror
                        @error('subsistemas.*')<small class="{{ $err }}">{{ $message }}</small>@enderror
                    </fieldset>

                    <div class="mb-6 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="{{ $label }}" for="motivo_suspension">Motivo*</label>
                            <input id="motivo_suspension" class="{{ $input }}" name="motivo_suspension" type="text" maxlength="500" required value="{{ old('motivo_suspension') }}">
                            @error('motivo_suspension')<small class="{{ $err }}">{{ $message }}</small>@enderror
                        </div>
                        <div>
                            <label class="{{ $label }}" for="inicio_suspension">Inicio*</label>
                            <input id="inicio_suspension" class="{{ $input }}" name="inicio_suspension" type="date" value="{{ old('inicio_suspension') }}">
                            <small class="{{ $hint }}">Inicio del periodo de suspensión.</small>
                            @error('inicio_suspension')<small class="{{ $err }}">{{ $message }}</small>@enderror
                        </div>
                        <div>
                            <label class="{{ $label }}" for="fin_suspension">Fin*</label>
                            <input id="fin_suspension" class="{{ $input }}" name="fin_suspension" type="date" value="{{ old('fin_suspension') }}">
                            <small class="{{ $hint }}">Fin del periodo de suspensión.</small>
                            @error('fin_suspension')<small class="{{ $err }}">{{ $message }}</small>@enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" @click="showSuspension = false" class="{{ $btnSecondary }}">Cancelar</button>
                        <button class="{{ $btnDanger }}" type="submit" onclick="return confirm('¿Deseas suspender las cuentas marcadas?');">Suspender</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
    @endcan


    {{-- Crear cuenta --}}
    @can('createAccount', $gestorUser)
    <div x-show="showCreateAccount"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">

        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="showCreateAccount = false"></div>

        {{-- Modal Content --}}
        <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Nuevas cuentas</h2>
                <button type="button" @click="showCreateAccount = false" class="text-[#68756d] hover:text-[#17211b]" aria-label="Cerrar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="p-6">
                @if ($subsistemasDisponibles->isEmpty())
                <p class="text-sm text-[#68756d]">Este gestor ya tiene cuenta en todos los subsistemas activos.</p>
                <div class="mt-6 flex justify-end">
                    <button type="button" @click="showCreateAccount = false" class="{{ $btnSecondary }}">Cerrar</button>
                </div>
                @else
                <form action="{{ route('gestor-users.accounts.store-many', $gestorUser) }}" method="POST">
                    @csrf

                    <fieldset class="mb-4">
                        <legend class="{{ $label }}">Subsistemas*</legend>
                        <p class="mb-3 text-xs text-[#68756d]">Se crearán cuentas con los datos del gestor en los subsistemas activos en los que aún no tiene cuenta. El ID externo y el estado se toman del resultado de la creación.</p>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($subsistemasDisponibles as $subsystem)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] transition hover:bg-[#f7faf8] has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/50" for="nueva-cuenta-subsistema-{{ $subsystem->id }}">
                                <input
                                    id="nueva-cuenta-subsistema-{{ $subsystem->id }}"
                                    class="h-4 w-4 rounded border-[#d9e2dc] text-emerald-700 focus:ring-emerald-600"
                                    name="subsystem_ids[]"
                                    type="checkbox"
                                    value="{{ $subsystem->id }}"
                                    @checked(in_array($subsystem->id, (array) old('subsystem_ids', [])))
                                >
                                <span>{{ $subsystem->nombre }}</span>
                            </label>
                            @endforeach
                        </div>
                        @error('subsystem_ids')<small class="{{ $err }}">{{ $message }}</small>@enderror
                        @error('subsystem_ids.*')<small class="{{ $err }}">{{ $message }}</small>@enderror

                        @if ($chatwootSubsistema)
                        <div id="nueva-cuenta-chatwoot-panel" class="mt-3 hidden rounded-lg border border-[#d9e2dc] bg-slate-50 p-3">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <strong class="text-xs text-[#17211b]">Equipos disponibles</strong>
                                <small id="nueva-cuenta-chatwoot-status" class="text-[10px] text-[#68756d]">Marca Chatwoot para seleccionar equipos.</small>
                            </div>
                            <div id="nueva-cuenta-chatwoot-options" class="grid w-full gap-2 sm:grid-cols-2"></div>
                            @error('subsystem_config.chatwoot.teams.*')<small class="{{ $err }}">{{ $message }}</small>@enderror
                        </div>
                        @endif
                    </fieldset>

                    <div class="mb-6">
                        <label class="{{ $label }}" for="nueva_cuenta_password_patron">Contraseña patrón</label>
                        <input id="nueva_cuenta_password_patron" class="{{ $input }}" name="password_patron" type="password" minlength="8" maxlength="255" autocomplete="new-password">
                        <small class="{{ $hint }}">Si el subsistema necesita contraseña, se usará esta al crear las cuentas. La contraseña del gestor no se reutiliza porque está guardada con hash.</small>
                        @error('password_patron')<small class="{{ $err }}">{{ $message }}</small>@enderror
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" @click="showCreateAccount = false" class="{{ $btnSecondary }}">Cancelar</button>
                        <button class="{{ $btnPrimary }}" type="submit">Crear cuentas</button>
                    </div>
                </form>
                @endif
            </div>
        </div>
    </div>
    @endcan



    {{-- Cuentas en subsistemas --}}
    <div class="mt-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <h2 class="text-xl font-bold text-[#17211b]">Cuentas en subsistemas</h2>

            <div class="flex flex-wrap items-center gap-2">
                @can('suspend', $gestorUser)
                @if ($gestorUser->subsystemAccounts->contains(fn ($account) => ($account->estado?->value ?? $account->estado) === 'activo'))
                <button class="{{ $btnSecondary }} {{ $btnIcon }}" type="button" @click="showSuspension = true" aria-label="Suspender cuentas de {{ $gestorUser->nombre_completo }}" title="Suspender cuentas"><svg class="h-4 w-4" aria-hidden="true">
                        <use href="#icon-user-pause"></use>
                    </svg><span class="sr-only">Suspender cuentas</span></button>
                @endif
                @endcan
                @can('createAccount', $gestorUser)
                <button class="{{ $btnPrimary }}" type="button" @click="showCreateAccount = true" aria-label="Crear cuenta para {{ $gestorUser->nombre_completo }}" title="Crear cuenta"><svg class="h-4 w-4" aria-hidden="true">
                        <use href="#icon-user-plus"></use>
                    </svg><span class="sr-only">Crear cuenta</span></button>
                @endcan
                @can('resetPassword', $gestorUser)
                <form action="{{ route('gestor-users.reset-password', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Deseas restablecer las contraseñas de todas las cuentas?');">
                    @csrf
                    <button class="{{ $btnDanger }} {{ $btnIcon }}" type="submit" aria-label="Restablecer contraseñas de {{ $gestorUser->nombre_completo }}" title="Restablecer contraseñas"><svg class="h-4 w-4" aria-hidden="true">
                            <use href="#icon-key"></use>
                        </svg><span class="sr-only">Restablecer contraseñas</span></button>
                </form>
                @endcan
                @can('update', $gestorUser)
                <form action="{{ route('gestor-users.sync-subsystems', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Propagar los datos de contacto a todas las cuentas en subsistemas?');">
                    @csrf
                    <button class="{{ $btnWarning  }} {{ $btnIcon }}" type="submit" aria-label="Sincronizar datos con subsistemas de {{ $gestorUser->nombre_completo }}" title="Sincronizar datos con subsistemas"><svg class="h-4 w-4" aria-hidden="true">
                            <use href="#icon-sync"></use>
                        </svg><span class="sr-only">Sincronizar datos con subsistemas</span></button>
                </form>
                @endcan
            </div>
        </div>

        @if ($gestorUser->subsystemAccounts->isEmpty())
        <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">Este usuario no tiene cuentas vinculadas.</div>
        @else
        <div class="overflow-x-auto rounded-xl border border-[#d9e2dc]">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                    <tr>
                        <th scope="col" class="{{ $th }}">Subsistema</th>
                        <th scope="col" class="{{ $th }}">Credencial</th>
                        <th scope="col" class="{{ $th }}">ID externo</th>
                        <th scope="col" class="{{ $th }}">Estado</th>
                        <th scope="col" class="{{ $th }}">Suspensión</th>
                        <th scope="col" class="{{ $th }}"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e8eee9]">
                    @foreach ($gestorUser->subsystemAccounts as $account)
                    <tr class="align-middle transition hover:bg-[#f7faf8]">
                        <td class="px-4 py-3.5 font-bold text-[#17211b]">
                            @if ($account->subsystem)
                            <a class="hover:text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" href="{{ route('subsystems.show', [$account->subsystem->id]) }}">{{ $account->subsystem->nombre }}</a>
                            @else
                            <span class="font-normal text-[#a3b0a8]">No disponible</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 font-mono text-[#17211b]">{{ $account->credencial_usuario }}</td>
                        <td class="px-4 py-3.5 font-mono {{ $account->external_account_id ? 'text-[#17211b]' : 'text-[#a3b0a8]' }}">{{ $account->external_account_id ?: 'No informado' }}</td>
                        <td class="px-4 py-3.5">@include('components.account-status', ['status' => $account->estado])</td>
                        <td class="px-4 py-3.5">
                            @if ($account->motivo_suspension || $account->inicio_suspension || $account->fin_suspension)
                            <div class="space-y-0.5 text-xs text-[#17211b]">
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
                            <span class="text-[#a3b0a8]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5">
                            @if ($account->subsystem)
                            <div class="flex items-center justify-start gap-2 whitespace-nowrap sm:justify-end">
                                @if ($account->estado === \App\Enums\SubsystemAccountStatus::Activo)
                                @can('disable', $account)
                                <form action="{{ route('subsystems.accounts.action', [$account->subsystem, $account]) }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="return_to" value="gestor-user">
                                    <input type="hidden" name="operation" value="disable">
                                    <button class="{{ $iconBtn }} border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100" type="submit" aria-label="Deshabilitar cuenta {{ $account->credencial_usuario }}" title="Deshabilitar"><svg class="h-4 w-4" aria-hidden="true">
                                            <use href="#icon-ban"></use>
                                        </svg><span class="sr-only">Deshabilitar</span></button>
                                </form>
                                @endcan
                                @else
                                @can('reactivate', $account)
                                <form action="{{ route('subsystems.accounts.action', [$account->subsystem, $account]) }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="return_to" value="gestor-user">
                                    <input type="hidden" name="operation" value="enable">
                                    <button class="{{ $iconBtn }} border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100" type="submit" aria-label="Habilitar cuenta {{ $account->credencial_usuario }}" title="Habilitar"><svg class="h-4 w-4" aria-hidden="true">
                                            <use href="#icon-check"></use>
                                        </svg><span class="sr-only">Habilitar</span></button>
                                </form>
                                @endcan
                                @endif
                                @can('delete', $account)
                                <form action="{{ route('subsystems.accounts.action', [$account->subsystem, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">
                                    @csrf
                                    <input type="hidden" name="return_to" value="gestor-user">
                                    <input type="hidden" name="operation" value="delete">
                                    <button class="{{ $iconBtn }} border-red-200 bg-red-50 text-red-700 hover:bg-red-100" type="submit" aria-label="Eliminar cuenta {{ $account->credencial_usuario }}" title="Eliminar"><svg class="h-4 w-4" aria-hidden="true">
                                            <use href="#icon-trash"></use>
                                        </svg><span class="sr-only">Eliminar</span></button>
                                </form>
                                @endcan
                            </div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{--
            Operaciones (Fase 3.2).

            El panel se refresca solo con fetch nativo contra la ruta de polling.
            Cuando una operación termina se recarga la página una sola vez, para
            que la tabla de cuentas y el histórico muestren ya el estado final.
        --}}
    <div class="mt-8"
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
        <h2 class="mb-1 text-xl font-bold text-[#17211b]">Operaciones</h2>
        <p class="mb-4 text-sm text-[#68756d]">
            Últimas acciones enviadas a los subsistemas. Mientras haya alguna en curso, esta tabla se actualiza sola.
        </p>

        @if ($operaciones->isEmpty())
        <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">Aún no se ha enviado ninguna operación a los subsistemas.</div>
        @else
        <ol class="divide-y divide-[#e8eee9] overflow-hidden rounded-xl border border-[#d9e2dc]">
            @foreach ($operaciones as $operacion)
            <li class="px-4 py-3.5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-bold text-[#17211b]">{{ $operacion->tipo->etiqueta() }}</span>
                    @if ($operacion->estaTerminada())
                    <span class="text-sm text-[#68756d]">{{ $operacion->estado->etiqueta() }} · {{ $operacion->resumen() }}</span>
                    @else
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500"></span>{{ $operacion->estado->etiqueta() }} · {{ $operacion->resumen() }}
                    </span>
                    @endif
                </div>
                <div class="mt-1 text-sm text-[#68756d]">
                    {{ $operacion->iniciada_at?->format('d/m/Y H:i') ?: $operacion->created_at->format('d/m/Y H:i') }}
                    · por {{ $operacion->autor() }}
                    · <span class="uppercase">{{ $operacion->origen }}</span>
                </div>

                <ul class="mt-2 space-y-1">
                    @foreach ($operacion->cuentas as $cuenta)
                    <li class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="inline-flex items-center gap-1 font-bold {{ $cuenta->estado->clase() }}">
                            <svg class="h-4 w-4" aria-hidden="true">
                                <use href="#{{ $cuenta->estado->icono() }}"></use>
                            </svg>
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
                            <button type="submit" class="rounded-md border border-[#d9e2dc] bg-white px-2.5 py-1 text-xs font-bold text-[#17211b] transition hover:bg-[#f7faf8] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
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

    {{-- Histórico --}}
    <div class="mt-8">
        <h2 class="mb-1 text-xl font-bold text-[#17211b]">Histórico</h2>
        <p class="mb-4 text-sm text-[#68756d]">Altas, cambios de estado y eliminaciones de sus cuentas en subsistemas.</p>

        @if ($historial->isEmpty())
        @if ($historial->total() === 0)
        <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">Aún no hay cambios registrados.</div>
        @else
        {{-- Página fuera de rango: hay historial, pero no en esta página.
                     Decir aquí "Aún no hay cambios registrados" sería mentira: el
                     total va en cientos y el operador buscaría un motivo que no
                     existe. --}}
        <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">
            <p>Esta página del histórico está fuera de rango.</p>
            <a class="mt-3 inline-flex font-bold text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" href="{{ route('gestor-users.show', $gestorUser) }}">Ir a la primera página</a>
        </div>
        @endif
        @else
        {{-- Acordeón: una sola variable (`abierto`) para todas las entradas, así
             abrir una cierra la anterior en vez de dejarlas apiladas. El id de
             la entrada va en el DOM, no en el estado, para que `aria-controls`
             apunte a algo real. --}}
        <ol class="divide-y divide-[#e8eee9] overflow-hidden rounded-xl border border-[#d9e2dc]" x-data="{ abierto: null }">
            @foreach ($historial as $entrada)
            <li>
                <h3>
                    <button type="button"
                        class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition hover:bg-[#f7faf8] focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-600"
                        @click="abierto = abierto === {{ $entrada->id }} ? null : {{ $entrada->id }}"
                        aria-expanded="false"
                        {{-- Alpine sobrescribe aria-expanded al iniciar; el literal
                             es lo que un lector de pantalla lee antes de que el JS
                             corra (o si no corre), y sin él el botón de un
                             disclosure no declara su estado. --}}
                        :aria-expanded="abierto === {{ $entrada->id }} ? 'true' : 'false'"
                        aria-controls="historial-{{ $entrada->id }}">
                        <span class="min-w-0">
                            <span class="block font-bold text-[#17211b]">{{ $entrada->descripcion }}</span>
                            <span class="mt-0.5 block text-sm text-[#68756d]">
                                {{ $entrada->subsystem?->nombre ?: 'Subsistema no disponible' }}
                                · por {{ $entrada->autor() }}
                                · <span class="uppercase">{{ $entrada->origen }}</span>
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="font-mono text-xs text-[#68756d]">{{ $entrada->created_at->format('d/m/Y H:i') }}</span>
                            <svg class="h-4 w-4 text-[#68756d] transition-transform" :class="abierto === {{ $entrada->id }} && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </span>
                    </button>
                </h3>
                <div id="historial-{{ $entrada->id }}"
                    x-show="abierto === {{ $entrada->id }}"
                    x-cloak
                    class="px-4 pb-4 pt-0">
                    @if ($entrada->cambios)
                    <dl class="space-y-0.5 rounded-lg bg-[#f7faf8] px-3 py-2 text-sm">
                        @foreach ($entrada->cambios as $atributo => $cambio)
                        <div class="flex flex-wrap gap-2">
                            <dt class="font-bold text-[#68756d]">{{ str_replace('_', ' ', $atributo) }}:</dt>
                            <dd class="text-[#17211b]">
                                @if (array_key_exists('desde', $cambio) && $cambio['desde'] !== null)
                                <span class="text-[#68756d] line-through">{{ $cambio['desde'] }}</span>
                                <span aria-hidden="true">→</span>
                                @endif
                                <span>{{ $cambio['hasta'] ?? '(vacío)' }}</span>
                            </dd>
                        </div>
                        @endforeach
                    </dl>
                    @else
                    <p class="text-sm text-[#68756d]">Esta entrada no registró cambios de atributos.</p>
                    @endif
                </div>
            </li>
            @endforeach
        </ol>

        {{-- El histórico está paginado, no recortado: el enlace es la única vía
             para llegar a las entradas anteriores a las de esta página. --}}
        @if ($historial->hasPages())
        <div class="mt-6">{{ $historial->links() }}</div>
        @endif
        @endif
    </div>
</section>
@endsection

@if ($chatwootSubsistema)
    @include('gestor-users._chatwoot-teams-script', [
        'chatwootCheckboxSelector' => '#nueva-cuenta-subsistema-'.$chatwootSubsistema->id,
        'chatwootPanelSelector' => '#nueva-cuenta-chatwoot-panel',
        'chatwootOptionsSelector' => '#nueva-cuenta-chatwoot-options',
        'chatwootStatusSelector' => '#nueva-cuenta-chatwoot-status',
        'chatwootTeamsRoute' => route('subsystems.chatwoot.teams', $chatwootSubsistema),
        'chatwootEmpresa' => $gestorUser->empresa,
        'chatwootEmpresaSelector' => null,
        'chatwootInitialStatus' => 'Marca Chatwoot para seleccionar equipos.',
        'chatwootEmptyEmpresaMessage' => 'El gestor no tiene empresa informada.',
        'chatwootSelectedTeams' => old('subsystem_config.chatwoot.teams', []),
    ])
@endif