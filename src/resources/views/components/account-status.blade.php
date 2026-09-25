@php
    $statusValue = is_object($status) ? $status->value : (string) $status;
    [$icon, $color] = match ($statusValue) {
        'activo' => ['icon-check', 'account-status-active'],
        'deshabilitado' => ['icon-ban', 'account-status-disabled'],
        'suspendido' => ['icon-clock', 'account-status-suspended'],
        'borrado', 'eliminado' => ['icon-trash', 'account-status-deleted'],
        default => ['icon-ban', 'account-status-unknown'],
    };
@endphp

<span class="inline-flex items-center justify-center {{ $color }}" title="{{ ucfirst($statusValue) }}">
    <svg class="h-5 w-5" aria-hidden="true"><use href="#{{ $icon }}"></use></svg>
    <span class="sr-only">{{ $statusValue }}</span>
</span>