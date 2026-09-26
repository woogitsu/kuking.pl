/*
 * #1989: po zalogowaniu innej osoby usuń z serwera subskrypcję poprzedniego
 * konta z tej przeglądarki. Nie pytamy o zgodę ani nie włączamy pushu nowej
 * osobie. Inne urządzenia poprzedniej osoby pozostają włączone.
 */

export async function uzgodnijUrzadzenie(nav, wyslij) {
    if (!nav?.serviceWorker?.getRegistration) return false;

    try {
        const rejestracja = await nav.serviceWorker.getRegistration('/');
        const subskrypcja = await rejestracja?.pushManager?.getSubscription();
        if (!subskrypcja) return false;

        const dane = subskrypcja.toJSON();
        if (!dane?.endpoint || !dane?.keys?.p256dh || !dane?.keys?.auth) return false;

        const odpowiedz = await wyslij({ endpoint: dane.endpoint, keys: dane.keys });
        if (!odpowiedz.ok) return false;
        return (await odpowiedz.json()).odlaczone === true;
    } catch {
        // Słaby zasięg nie może blokować korzystania z serwisu. Kolejna
        // wizyta ponowi uzgodnienie z tą samą przeglądarką.
        return false;
    }
}

function start() {
    const root = document.body;
    const url = root?.dataset.pushUzgodnij;
    if (!url || !('serviceWorker' in navigator)) return;

    uzgodnijUrzadzenie(navigator, (dane) => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': root.dataset.pushCsrf || '',
        },
        body: JSON.stringify(dane),
    })).then((odlaczone) => {
        if (odlaczone) {
            const komunikat = document.querySelector('[data-push-uzgodnij-komunikat]');
            if (komunikat) komunikat.hidden = false;
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}
