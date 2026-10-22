// The whole client-side surface: confirmation dialogs, the mobile drawer and
// toast dismissal. Everything else is server-rendered (spec §7.4).

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');

    if (opener) {
        event.preventDefault();
        const dialog = document.querySelector(opener.dataset.dialogOpen);

        if (dialog) {
            dialog.showModal();
        }

        return;
    }

    const dismiss = event.target.closest('[data-toast-dismiss]');

    if (dismiss) {
        dismiss.closest('[data-toast]')?.remove();

        return;
    }

    const toggle = event.target.closest('[data-drawer-toggle]');

    if (toggle) {
        const drawer = document.querySelector('#sidebar');

        if (drawer) {
            const hidden = drawer.classList.toggle('hidden');
            toggle.setAttribute('aria-expanded', String(! hidden));
        }
    }
});
