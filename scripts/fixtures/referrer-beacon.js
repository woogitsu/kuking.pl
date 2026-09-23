// NASZA minimalna atrapa, nie kopia ani pełna emulacja beacona Cloudflare.
// Mierzy tylko granicę dokumentów i payload z referrerem. Osobny pomiar
// rzeczywistego publicznego skryptu jest opisany w raporcie #1052.
(() => {
    const clean = value => {
        if (!value) return value;
        const url = new URL(value);
        url.search = '';
        url.hash = '';
        return url.href;
    };
    navigator.sendBeacon('https://cloudflareinsights.com/cdn-cgi/rum', JSON.stringify({
        fixture: 'kuking-referrer-only',
        location: clean(location.href),
        referrer: clean(document.referrer),
    }));
})();
