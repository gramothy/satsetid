document.querySelector('[data-print-qr]')?.addEventListener('click', () => window.print());
document.querySelector('[data-copy-url]')?.addEventListener('click', async event => {
    const status = document.querySelector('[data-copy-status]');
    try {
        await navigator.clipboard.writeText(event.currentTarget.dataset.copyUrl);
        status.textContent = 'URL tersalin.';
    } catch {
        const input = document.querySelector('[data-url-input]');
        input.focus();
        input.select();
        status.textContent = 'URL dipilih. Salin dari kolom di bawah.';
    }
});

const userForm = document.querySelector('[data-user-form]');
if (userForm) {
    const picker = userForm.querySelector('[data-outlet-picker]');
    userForm.querySelectorAll('[data-role-input]').forEach(input => input.addEventListener('change', () => {
        picker.hidden = userForm.querySelector('[data-role-input]:checked')?.value === 'owner';
    }));
}

// Hubungkan WhatsApp: refresh the gateway status and QR every 5 seconds until the number is connected.
const waConnect = document.querySelector('[data-wa-connect]');
if (waConnect) {
    const refresh = async () => {
        if (!document.hidden) {
            try {
                const response = await fetch(waConnect.dataset.statusUrl, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                if (response.redirected) {
                    return; // Session expired: the next page load shows the login form.
                }
                if (response.ok) {
                    const data = await response.json();
                    waConnect.innerHTML = data.html;
                    waConnect.dataset.state = data.state;
                }
            } catch {
                // Network hiccup: try again on the next tick.
            }
        }
        if (waConnect.dataset.state !== 'open') {
            setTimeout(refresh, 5000);
        }
    };
    if (waConnect.dataset.state !== 'open') {
        setTimeout(refresh, 5000);
    }
}
