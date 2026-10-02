import http from 'node:http';
import { readFileSync } from 'node:fs';

/** Ten sam lokalny serwer służy testowi DOM i osobnej kontroli jego wejścia HTTP. */
export function utworzSerwer({ strona, skladniki, modul, css, zadaniaPost }) {
    return http.createServer((req, res) => {
        const url = new URL(req.url, 'http://x');
        if (req.method !== 'GET') {
            zadaniaPost.push(url.pathname);
            res.writeHead(303, { Location: '/?krok=1' });
            res.end();

            return;
        }
        if (url.pathname === '/skladniki-gotowania.js') {
            res.writeHead(200, { 'Content-Type': 'text/javascript; charset=utf-8' });
            res.end(readFileSync(modul, 'utf8'));

            return;
        }
        if (url.pathname === '/app.css') {
            res.writeHead(200, { 'Content-Type': 'text/css; charset=utf-8' });
            res.end(css);

            return;
        }
        const porcje = Number(url.searchParams.get('porcje') ?? '4');
        if (!Number.isSafeInteger(porcje) || porcje < 1) {
            res.writeHead(400, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end('Niepoprawna liczba porcji.');

            return;
        }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(strona(skladniki(), Number(url.searchParams.get('krok') ?? 1), porcje));
    });
}
