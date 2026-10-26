@extends('layouts.app')

@section('title', 'Painel de Controle')

@section('content')
    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        <div class="mb-8">
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Painel de Métricas</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Visão geral do estado do sistema e provisionamento.</p>
        </div>

        {{-- Primary Stats --}}
        <div class="grid gap-6 mb-10 md:grid-cols-4">
            <div class="rounded-lg border border-[#d9e2dc] p-5 bg-[#f7faf8]">
                <strong class="block mb-2 text-sm uppercase tracking-wide text-[#68756d]">Usuários Totais</strong>
                <span class="text-3xl font-bold text-emerald-700">{{ $metrics['users']['total'] ?? 0 }}</span>
            </div>
            <div class="rounded-lg border border-[#d9e2dc] p-5 bg-[#f7faf8]">
                <strong class="block mb-2 text-sm uppercase tracking-wide text-[#68756d]">Contas Totais</strong>
                <span class="text-3xl font-bold text-emerald-700">{{ $metrics['accounts']['total'] ?? 0 }}</span>
            </div>
            <div class="rounded-lg border border-[#d9e2dc] p-5 bg-[#f7faf8]">
                <strong class="block mb-2 text-sm uppercase tracking-wide text-[#68756d]">Em Curso</strong>
                <span class="text-3xl font-bold text-blue-600">{{ $metrics['operations']['in_progress'] ?? 0 }}</span>
            </div>
            <div class="rounded-lg border border-red-200 p-5 bg-red-50">
                <strong class="block mb-2 text-sm uppercase tracking-wide text-red-700">Operações Falhas</strong>
                <span class="text-3xl font-bold text-red-600">{{ $metrics['operations']['failed'] ?? 0 }}</span>
            </div>
        </div>

        <div class="grid gap-8 md:grid-cols-2">
            {{-- User Status Breakdown --}}
            <div class="rounded-xl border border-[#d9e2dc] overflow-hidden">
                <div class="bg-[#f7faf8] px-5 py-3 border-b border-[#d9e2dc]">
                    <h2 class="font-bold text-[#17211b]">Distribuição de Usuários</h2>
                </div>
                <div class="p-5">
                    <div class="space-y-3">
                        @forelse($metrics['users']['by_status'] ?? [] as $status => $count)
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-[#68756d] capitalize">{{ $status }}</span>
                                <span class="font-mono font-bold text-[#17211b]">{{ $count }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-[#68756d]">Nenhum dado disponível.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Account Subsystem Breakdown --}}
            <div class="rounded-xl border border-[#d9e2dc] overflow-hidden">
                <div class="bg-[#f7faf8] px-5 py-3 border-b border-[#d9e2dc]">
                    <h2 class="font-bold text-[#17211b]">Contas por Subsistema</h2>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 gap-4">
                        @forelse($metrics['accounts']['by_subsystem'] ?? [] as $subsystem => $count)
                            <div class="flex justify-between items-center text-sm border-b border-[#f0f4f2] pb-1">
                                <span class="text-[#68756d]">{{ $subsystem }}</span>
                                <span class="font-mono font-bold text-[#17211b]">{{ $count }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-[#68756d] col-span-2">Nenhum dado disponível.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Operational Alerts --}}
            <div class="rounded-xl border border-orange-200 overflow-hidden bg-orange-50/30">
                <div class="bg-orange-100/50 px-5 py-3 border-b border-orange-200">
                    <h2 class="font-bold text-orange-800">Alertas de Suspensão</h2>
                </div>
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-orange-700">Vencem nos próximos 7 dias:</p>
                        <span class="text-2xl font-bold text-orange-600">{{ $metrics['expiring_suspensions'] ?? 0 }}</span>
                    </div>
                </div>
            </div>

            {{-- Connectivity Status --}}
            <div class="rounded-xl border border-[#d9e2dc] overflow-hidden">
                <div class="bg-[#f7faf8] px-5 py-3 border-b border-[#d9e2dc]">
                    <h2 class="font-bold text-[#17211b]">Conectividade dos Subsistemas</h2>
                </div>
                <div class="p-5">
                    <div class="space-y-2">
                        @forelse($metrics['connectivity'] ?? [] as $conn)
                            <div class="flex justify-between items-center text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full {{ $conn['success'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    <span class="text-[#68756d]">{{ $conn['nombre'] }}</span>
                                </div>
                                <span class="text-xs text-[#a3b0a8] italic">{{ $conn['last_test'] ?? 'Nunca' }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-[#68756d]">Nenhum dado disponível.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Gestores suspendidos: pendientes de reactivación --}}
        @php($gestoresSuspendidos = $metrics['suspended_managers'] ?? [])
        <section class="mt-8 overflow-hidden rounded-xl border border-amber-200 bg-amber-50/30" aria-labelledby="suspended-managers-title">
            <div class="flex items-center justify-between border-b border-amber-200 bg-amber-100/50 px-5 py-3">
                <h2 id="suspended-managers-title" class="font-bold text-amber-800">Gestores suspensos — pendentes de reativação</h2>
                <span class="rounded-full border border-amber-200 bg-white px-2.5 py-0.5 text-xs font-bold text-amber-800">{{ count($gestoresSuspendidos) }}</span>
            </div>
            <div class="p-5">
                @forelse ($gestoresSuspendidos as $gestor)
                    <div class="flex flex-col gap-3 border-b border-amber-200/60 py-3 first:pt-0 last:border-b-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <a class="font-bold text-[#17211b] hover:text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" href="{{ $gestor['url'] }}">{{ $gestor['nombre'] }}</a>
                            <span class="ml-2 text-sm text-[#68756d]">{{ $gestor['empresa'] }} · {{ $gestor['usuario'] }}</span>
                            <p class="mt-1 text-sm text-[#68756d]">
                                @if ($gestor['motivo'])
                                    Motivo: {{ $gestor['motivo'] }}
                                @endif
                                @if ($gestor['suspendido_at'])
                                    · Suspenso em {{ $gestor['suspendido_at'] }}
                                @endif
                                @if ($gestor['fin'])
                                    · Fim previsto: {{ $gestor['fin'] }}
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <a class="inline-flex items-center justify-center rounded-lg border border-[#d9e2dc] bg-white px-4 py-2 text-sm font-bold text-[#17211b] transition hover:bg-[#f7faf8] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2" href="{{ $gestor['url'] }}">Ver ficha</a>
                            @if ($gestor['can_reactivate'])
                                <form action="{{ $gestor['reactivate_url'] }}" method="POST" onsubmit="return confirm('¿Reactivar el gestor y las cuentas que estaban activas antes de la suspensión?');">
                                    @csrf
                                    <button class="inline-flex items-center justify-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2" type="submit">Reactivar</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-[#68756d]">Nenhum gestor suspenso pendente de reativação.</p>
                @endforelse
            </div>
        </section>
    </section>
@endsection
