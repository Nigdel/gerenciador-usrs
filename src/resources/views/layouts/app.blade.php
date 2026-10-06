<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Styles -->
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased">
        <!-- Toast notifications container -->
        <div id="toast-container" class="toast-container fixed top-4 right-4 z-50"></div>

        <!-- Session flash messages -->
        @if (session('success'))
            <div class="toast toast-success fixed top-4 right-4 z-50">{{ session('success') }}</div>
        @endif
        @if (session('warning'))
            <div class="toast toast-warning fixed top-4 right-4 z-50">{{ session('warning') }}</div>
        @endif
        @if (session('error'))
            <div class="toast toast-error fixed top-4 right-4 z-50">{{ session('error') }}</div>
        @endif
        @if (session('provisioning_results'))
            @php($fallos = collect(session('provisioning_results'))->where('exito', false))
            @if ($fallos->isNotEmpty())
                <div class="toast toast-error fixed top-4 right-4 z-50">
                    @foreach ($fallos as $result)
                        <div>{{ $result['subsistema'] ?? 'Unknown' }}: {{ $result['mensaje'] }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        @auth
            <div class="min-h-screen bg-gray-100">
                @include('layouts.navigation')

                <!-- Page Heading -->
                @isset($header)
                    <header class="bg-white shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- Page Content -->
                <main>
                    {{ $slot ?? '' }}
                    @yield('content')
                </main>
            </div>
        @else
            <div class="min-h-screen bg-gray-100">
                <main>
                    {{ $slot ?? '' }}
                    @yield('content')
                </main>
            </div>
        @endauth
    </body>
</html>