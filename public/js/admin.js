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

// Kelola antrean: the live part of the page is re-fetched every 10 seconds, so the counter sees new tickets
// without reloading. A refresh waits while a button is being pressed or a text field has focus, so a click is
// never swallowed and typing is never overwritten. The page is skipped while hidden or signed out.
const liveQueue = document.querySelector('[data-live-queue]');
if (liveQueue) {
    const QUEUE_REFRESH_MS = 10000;
    let holdUntil = 0;
    let timer = null;
    let refreshing = false;

    liveQueue.addEventListener('pointerdown', () => { holdUntil = Date.now() + 1500; });
    const typing = () => ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
        && liveQueue.contains(document.activeElement);

    const refreshQueue = async () => {
        clearTimeout(timer);
        if (!refreshing && !document.hidden && Date.now() > holdUntil && !typing()) {
            refreshing = true;
            try {
                const response = await fetch(window.location.href, {
                    headers: { Accept: 'text/html' },
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                if (response.ok && !response.redirected) {
                    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const fresh = page.querySelector('[data-live-queue]');
                    if (fresh && !typing()) {
                        liveQueue.innerHTML = fresh.innerHTML;
                    }
                }
            } catch {
                // Network hiccup: the next tick tries again.
            } finally {
                refreshing = false;
            }
        }
        timer = setTimeout(refreshQueue, QUEUE_REFRESH_MS);
    };

    timer = setTimeout(refreshQueue, QUEUE_REFRESH_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshQueue(); });
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
