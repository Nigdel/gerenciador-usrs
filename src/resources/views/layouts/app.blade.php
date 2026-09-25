<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'Laravel'))</title>
    <link rel="stylesheet" href="{{ asset('user-form.css') }}">
    <style>
        a:has(> svg),
        button:has(> svg) {
            cursor: pointer;
        }
    </style>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @stack('head')
</head>
<body>
    <svg aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden">
        <symbol id="icon-eye" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/><circle cx="12" cy="12" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
        <symbol id="icon-edit" viewBox="0 0 24 24"><path d="M12 20h9" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"/><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></symbol>
        <symbol id="icon-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></symbol>
        <symbol id="icon-ban" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="m5.6 5.6 12.8 12.8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"/></symbol>
        <symbol id="icon-check" viewBox="0 0 24 24"><path d="m5 12 4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></symbol>
    </svg>
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
