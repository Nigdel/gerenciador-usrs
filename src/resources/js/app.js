import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

function initializeToasts() {
    const container = document.getElementById('toast-container');

    document.querySelectorAll('.toast').forEach((toast) => {
        if (container) {
            toast.classList.remove('fixed', 'top-4', 'right-4', 'z-50');
            container.appendChild(toast);
        }
        if (toast.dataset.toastInitialized === 'true') {
            return;
        }

        toast.dataset.toastInitialized = 'true';

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'toast-close';
        closeButton.setAttribute('aria-label', 'Cerrar notificación');
        closeButton.textContent = '×';

        let timeoutId;

        function dismissToast() {
            window.clearTimeout(timeoutId);

            if (toast.dataset.toastDismissed === 'true') {
                return;
            }

            toast.dataset.toastDismissed = 'true';
            toast.classList.add('toast-leaving');

            window.setTimeout(() => {
                toast.remove();
            }, 250);
        }

        closeButton.addEventListener('click', dismissToast);
        toast.appendChild(closeButton);

        timeoutId = window.setTimeout(dismissToast, 5000);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeToasts);
} else {
    initializeToasts();
}
