@extends('layouts.app')

@section('title', 'Usuários')

@section('content')
    <section class="w-full max-w-5xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        <div class="mb-8 flex flex-col items-start justify-between gap-6 sm:flex-row">
            <div>
                <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-emerald-700">Gestão de acessos</p>
                <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Usuários</h1>
                <p class="max-w-prose leading-6 text-[#68756d]">Consulte e administre os acessos cadastrados.</p>
            </div>
            <a class="inline-flex min-h-11 items-center justify-center rounded-md bg-emerald-700 px-5 py-2.5 font-bold text-white no-underline transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-700/20" href="{{ route('users.create') }}">Novo usuário</a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
        @endif

        @if ($users->isEmpty())
            <div class="grid gap-2 border border-dashed border-[#d9e2dc] px-6 py-11 text-center text-[#68756d]">
                <strong class="text-[#17211b]">No hay usuarios registrados.</strong>
                <span>Agrega el primero para comenzar.</span>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Nombre</th>
                            <th class="px-4 py-3.5 font-bold">E-mail</th>
                            <th class="px-4 py-3.5 font-bold">Empresa</th>
                            <th class="px-4 py-3.5 font-bold">Tipo</th>
                            <th class="px-4 py-3.5 font-bold"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr class="border-t border-[#e8eee9] align-middle">
                                <td class="px-4 py-3.5 font-bold text-[#17211b]">{{ $user->name }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $user->email ?: 'Sem e-mail' }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $user->empresa ?: 'Não informada' }}</td>
                                <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2 py-1 text-xs font-bold {{ $user->externo ? 'bg-amber-50 text-amber-800' : 'bg-green-50 text-green-800' }}">{{ $user->externo ? 'Externo' : 'Interno' }}</span></td>
                                <td class="px-4 py-3.5">
                                    <div class="flex justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        <a class="inline-flex min-h-9 items-center justify-center rounded-md bg-sky-600 px-3 py-2 text-sm font-bold text-white no-underline transition hover:bg-sky-700 focus:outline-none focus:ring-4 focus:ring-sky-600/25" href="{{ route('users.show', $user) }}">Ver</a>
                                        <a class="inline-flex min-h-9 items-center justify-center rounded-md bg-amber-500 px-3 py-2 text-sm font-bold text-white no-underline transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-500/25" href="{{ route('users.edit', $user) }}">Editar</a>
                                        <form class="inline-flex" action="{{ route('users.destroy', $user) }}" method="POST" onsubmit="return confirm('¿Eliminar este usuario?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="inline-flex min-h-9 items-center justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-bold text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-600/25" type="submit">Eliminar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection