#!/usr/bin/env python3
"""Proxy HTTP do testów na telefonie w tej samej sieci lokalnej (LAN).

Zadanie: telefon (np. do zrobienia zdjęcia HEIC) nie zawsze może wpiąć się
bezpośrednio pod lokalny serwer deweloperski (`php -S 127.0.0.1:8118 ...`),
więc ten skrypt nasłuchuje i przekazuje żądania dalej — zawsze na ten sam,
stały cel lokalnej aplikacji (domyślnie 127.0.0.1:8118). Cel nie jest brany
z żądania (Host / ścieżka), więc proxy nie da się użyć jako otwartego
przekierowania na dowolny host (ochrona przed SSRF).

Domyślnie serwer nasłuchuje WYŁĄCZNIE na 127.0.0.1 — nieosiągalny z innych
urządzeń w sieci. Żeby faktycznie przetestować z telefonu, trzeba świadomie
włączyć nasłuch w sieci lokalnej flagą --allow-lan i podać adres --host
(np. 192.168.0.242). W tym trybie każde żądanie musi nieść poprawny token
dostępu (nagłówek `X-Phone-Proxy-Token` albo parametr `?token=...`) —
token jest generowany losowo przy starcie i wypisywany na konsolę, chyba
że ustawisz go sam zmienną środowiskową PHONE_PROXY_TOKEN albo --token.
Alternatywnie można zamiast tokenu podać listę dozwolonych adresów
źródłowych (--allow-ip, powtarzalne) — wtedy żądania z innych adresów są
odrzucane niezależnie od tokenu.

Użycie:
    python3 .codex/heic-119/phone-proxy.py
        # tylko localhost, bez żadnej ekspozycji w sieci (domyślne, bezpieczne)

    python3 .codex/heic-119/phone-proxy.py --allow-lan --host 192.168.0.242
        # nasłuch w sieci lokalnej; wymaga tokenu z konsoli w każdym żądaniu

    python3 .codex/heic-119/phone-proxy.py --allow-lan --host 192.168.0.242 \
        --allow-ip 192.168.0.50
        # nasłuch w sieci lokalnej, bez tokenu, ale tylko z podanego adresu
"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import argparse
import http.client
import ipaddress
import os
import re
import secrets
import sys

DEFAULT_HOST = '127.0.0.1'
DEFAULT_PORT = 8119
DEFAULT_TARGET_HOST = '127.0.0.1'
DEFAULT_TARGET_PORT = 8118
TOKEN_HEADER = 'X-Phone-Proxy-Token'
TOKEN_PARAM = 'token'


MAX_BODY = 20 * 1024 * 1024
_NAZWA_NAGLOWKA = re.compile(r"^[!#$%&'*+.^_`|~0-9A-Za-z-]+$")


def bezpieczny_naglowek(nazwa, wartosc):
    """Nazwa wg RFC 7230 (token), wartość bez znaków sterujących CR/LF/NUL."""
    if not _NAZWA_NAGLOWKA.match(nazwa or ''):
        return False
    return not any(z in (wartosc or '') for z in ('\r', '\n', '\x00'))


def parse_args(argv=None):
    parser = argparse.ArgumentParser(
        description='Proxy HTTP do testów na telefonie w sieci lokalnej (tylko dev).')
    parser.add_argument('--host', default=DEFAULT_HOST,
                         help=f'adres, na którym nasłuchuje proxy (domyślnie {DEFAULT_HOST})')
    parser.add_argument('--port', type=int, default=DEFAULT_PORT,
                         help=f'port proxy (domyślnie {DEFAULT_PORT})')
    parser.add_argument('--target-host', default=DEFAULT_TARGET_HOST,
                         help='stały cel przekazywania żądań (domyślnie 127.0.0.1) — '
                              'nie da się zmienić przez samo żądanie')
    parser.add_argument('--target-port', type=int, default=DEFAULT_TARGET_PORT,
                         help=f'port lokalnej aplikacji, do której przekazujemy (domyślnie {DEFAULT_TARGET_PORT})')
    parser.add_argument('--allow-lan', action='store_true',
                         help='jawna zgoda na nasłuch na adresie spoza localhost (wymagana, '
                              'jeśli --host to nie 127.0.0.1/::1)')
    parser.add_argument('--token', default=None,
                         help='token dostępu; domyślnie z PHONE_PROXY_TOKEN albo losowy przy starcie')
    parser.add_argument('--allow-ip', action='append', default=[], metavar='ADRES',
                         help='dozwolony adres źródłowy (powtarzalne); jeśli podany, '
                              'zastępuje wymóg tokenu i odrzuca inne adresy')
    return parser.parse_args(argv)


def is_loopback(host):
    if host in ('localhost',):
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        # Nazwa hosta inna niż "localhost" — traktujemy ostrożnie jako nie-loopback.
        return False


def resolve_token(args):
    if args.allow_ip:
        # Lista dozwolonych adresów jest alternatywą dla tokenu.
        return None
    if args.token:
        return args.token
    env_token = os.environ.get('PHONE_PROXY_TOKEN')
    if env_token:
        return env_token
    return secrets.token_urlsafe(32)


def make_handler(target_host, target_port, token, allowed_ips):
    class Proxy(BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def _client_allowed(self):
            if not allowed_ips:
                return True
            return self.client_address[0] in allowed_ips

        def _token_ok(self):
            if token is None:
                return True
            supplied = self.headers.get(TOKEN_HEADER)
            if not supplied:
                query = self.path.split('?', 1)
                if len(query) == 2:
                    for pair in query[1].split('&'):
                        key, _, value = pair.partition('=')
                        if key == TOKEN_PARAM:
                            supplied = value
                            break
            return supplied is not None and secrets.compare_digest(supplied, token)

        def forward(self):
            if not self._client_allowed():
                self.send_response(403)
                self.send_header('Content-Length', '0')
                self.end_headers()
                return
            if not self._token_ok():
                self.send_response(401)
                self.send_header('Content-Length', '0')
                self.end_headers()
                return
            try:
                dlugosc = int(self.headers.get('Content-Length', 0))
            except ValueError:
                dlugosc = -1
            if dlugosc < 0 or dlugosc > MAX_BODY:
                self.send_response(400)
                self.send_header('Content-Length', '0')
                self.end_headers()
                return
            data = self.rfile.read(dlugosc)
            # Cel jest stały (target_host/target_port) — żądanie (Host, ścieżka)
            # nie może go zmienić, więc to proxy nie służy do dowolnego forwardowania.
            conn = http.client.HTTPConnection(target_host, target_port, timeout=120)
            headers = {k: v for k, v in self.headers.items() if k.lower() != 'x-phone-proxy-token'}
            headers['Connection'] = 'close'
            conn.request(self.command, self.path, data, headers)
            resp = conn.getresponse()
            body = resp.read()
            self.send_response(resp.status)
            for key, value in resp.getheaders():
                if key.lower() in ('connection', 'transfer-encoding', 'content-length'):
                    continue
                # Nagłówek z CR/LF albo spoza dozwolonego zestawu pomijamy —
                # inaczej można by wstrzyknąć własne nagłówki lub treść (HTTP Response Splitting).
                if not bezpieczny_naglowek(key, value):
                    continue
                self.send_header(key, value)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            conn.close()

        do_GET = forward
        do_POST = forward

        def log_message(self, *args):
            pass

    return Proxy


def main(argv=None):
    args = parse_args(argv)

    if not is_loopback(args.host) and not args.allow_lan:
        print(
            f'BŁĄD: adres {args.host} nie jest localhost. To proxy domyślnie nie wystawia '
            'lokalnej aplikacji do sieci. Jeśli świadomie chcesz testować z telefonu w tej '
            'samej sieci lokalnej, uruchom ponownie z flagą --allow-lan.',
            file=sys.stderr,
        )
        raise SystemExit(2)

    token = resolve_token(args)
    allowed_ips = set(args.allow_ip)

    print(f'phone-proxy: nasłuch na {args.host}:{args.port} -> {args.target_host}:{args.target_port}')
    if is_loopback(args.host):
        print('phone-proxy: adres localhost — proxy NIE jest widoczne z innych urządzeń.')
    else:
        print(
            f'phone-proxy: UWAGA — nasłuch w sieci lokalnej na {args.host}. Każde urządzenie '
            'w tej sieci, które zna adres i port, może wysyłać żądania do lokalnej aplikacji. '
            'Uruchamiaj tylko do doraźnych testów i wyłącz zaraz po nich.'
        )
    if allowed_ips:
        print(f'phone-proxy: dostęp ograniczony do adresów: {", ".join(sorted(allowed_ips))}')
    else:
        print(f'phone-proxy: wymagany token dostępu (nagłówek {TOKEN_HEADER} albo ?{TOKEN_PARAM}=...):')
        print(f'  {token}')

    handler = make_handler(args.target_host, args.target_port, token, allowed_ips)
    ThreadingHTTPServer((args.host, args.port), handler).serve_forever()


if __name__ == '__main__':
    main()
