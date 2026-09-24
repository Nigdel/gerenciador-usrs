<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'Laravel'))</title>
    <link rel="stylesheet" href="{{ asset('user-form.css') }}">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @stack('head')
</head>
<body>
    @php
        $notifications = collect([
            'success' => session('success'),
            'warning' => session('warning'),
            'error' => session('error'),
        ])->filter();
    @endphp

    @if ($notifications->isNotEmpty())
        <div class="toast-container" aria-live="polite" aria-atomic="true">
            @foreach ($notifications as $type => $message)
                <div class="toast toast-{{ $type }}" role="{{ $type === 'error' ? 'alert' : 'status' }}" data-toast>
                    <span class="toast-message">{{ $message }}</span>
                    <button class="toast-dismiss" type="button" aria-label="Cerrar notificación" data-toast-dismiss>&times;</button>
                </div>
            @endforeach
        </div>
    @endif

    <main class="page-shell">
        @yield('content')
    </main>

    @stack('scripts')

    <script>
        document.querySelectorAll('[data-toast]').forEach((toast) => {
            let dismissed = false;

            const dismiss = () => {
                if (dismissed) {
                    return;
                }

                dismissed = true;
                toast.classList.add('toast-hiding');
                toast.addEventListener('animationend', () => toast.remove(), { once: true });
            };

            toast.querySelector('[data-toast-dismiss]').addEventListener('click', dismiss);
            window.setTimeout(dismiss, 6500);
        });
    </script>
</body>
</html>
