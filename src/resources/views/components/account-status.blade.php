@php
    $statusValue = is_object($status) ? $status->value : (string) $status;
    [$icon, $color] = match ($statusValue) {
        'activo' => ['icon-check', 'account-status-active'],
        // Comparte color con 'suspendido' a propósito: una suspensión agendada
        // es una suspensión que aún no se aplicó. La clase nueva
        // 'account-status-pending' no se añade porque estas clases solo existen
        // en el CSS ya compilado y un npm run build la borraría.
        'pendiente' => ['icon-clock', 'account-status-suspended'],
        'deshabilitado' => ['icon-ban', 'account-status-disabled'],
        'suspendido' => ['icon-clock', 'account-status-suspended'],
        'borrado', 'eliminado' => ['icon-trash', 'account-status-deleted'],
        default => ['icon-ban', 'account-status-unknown'],
    };
    $statusLabel = $statusValue === 'pendiente' ? 'Suspensión programada' : ucfirst($statusValue);
@endphp

<span class="inline-flex items-center justify-center {{ $color }}" title="{{ $statusLabel }}">
    <svg class="h-5 w-5" aria-hidden="true"><use href="#{{ $icon }}"></use></svg>
    <span class="sr-only">{{ $statusLabel }}</span>
</span>