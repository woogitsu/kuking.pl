#!/usr/bin/env python3
"""Test argumentów i domyślnych ustawień phone-proxy.py (issue #1850).

Uruchom: python3 .codex/heic-119/phone-proxy.test.py
Bez frameworka (jak scripts/dr594-sprawdz.py) — same asercje, exit 0 = ok.
"""
from pathlib import Path
import runpy
import sys

MODULE_PATH = Path(__file__).resolve().parent / 'phone-proxy.py'
proxy = runpy.run_path(str(MODULE_PATH), run_name='phone_proxy_pod_testem')

parse_args = proxy['parse_args']
is_loopback = proxy['is_loopback']
resolve_token = proxy['resolve_token']
main = proxy['main']


def test_domyslny_host_to_localhost():
    args = parse_args([])
    assert args.host == '127.0.0.1', 'Domyślny host musi być localhost'
    assert args.allow_lan is False, 'Domyślnie nasłuch w LAN musi być wyłączony'
    assert is_loopback(args.host), 'Domyślny host powinien być rozpoznany jako loopback'


def test_domyslny_cel_forwardowania_jest_lokalny():
    args = parse_args([])
    assert args.target_host == '127.0.0.1'
    assert args.target_port == 8118
    # Skrypt nie przyjmuje żadnej flagi, która pozwalałaby wskazać cel
    # per-żądanie (np. z Host: albo ze ścieżki) — cel jest stały z CLI.


def test_lan_bez_allow_lan_konczy_z_bledem():
    try:
        main(['--host', '192.168.0.242'])
    except SystemExit as exc:
        assert exc.code == 2
    else:
        raise AssertionError('Nasłuch w LAN bez --allow-lan powinien się nie powieść')


def test_allow_lan_wymaga_jawnej_flagi():
    args = parse_args(['--host', '192.168.0.242', '--allow-lan'])
    assert args.allow_lan is True
    assert not is_loopback(args.host)


def test_token_generowany_gdy_brak_allow_ip():
    args = parse_args([])
    token = resolve_token(args)
    assert token is not None and len(token) >= 16, 'Token powinien być wygenerowany domyślnie'


def test_allow_ip_zastepuje_token():
    args = parse_args(['--allow-ip', '192.168.0.50'])
    assert resolve_token(args) is None, 'Podanie --allow-ip powinno wyłączyć wymóg tokenu'
    assert args.allow_ip == ['192.168.0.50']


def test_wlasny_token_ma_pierwszenstwo():
    args = parse_args(['--token', 'moj-token'])
    assert resolve_token(args) == 'moj-token'



def test_naglowek_z_nowa_linia_odrzucony():
    assert proxy['bezpieczny_naglowek']('X-Test', 'ok') is True
    assert proxy['bezpieczny_naglowek']('X-Test', 'a\r\nSet-Cookie: x=1') is False
    assert proxy['bezpieczny_naglowek']('X-Test', 'a\nb') is False
    assert proxy['bezpieczny_naglowek']('Zły Nagłówek', 'ok') is False
    assert proxy['bezpieczny_naglowek']('X\r\nY', 'ok') is False


def test_allowlista_zwraca_stala_nazwe_i_wartosc():
    f = proxy['naglowek_do_przekazania']
    assert f('content-type', 'text/html') == ('Content-Type', 'text/html')
    assert f('ETAG', '"abc"') == ('ETag', '"abc"')
    assert f('X-Nieznany', 'ok') is None, 'Nagłówek spoza allowlisty nie jest przekazywany'
    assert f('Connection', 'close') is None
    assert f('Content-Length', '5') is None


def test_allowlista_odrzuca_cr_lf_nul_w_nazwie_i_wartosci():
    f = proxy['naglowek_do_przekazania']
    assert f('Location', '/x\r\nSet-Cookie: a=1') is None
    assert f('Location', '/x\nX: y') is None
    assert f('Location', '/x\x00') is None
    assert f('Content-Type\r\nX-Evil: 1', 'text/html') is None


def test_odpowiedz_upstream_nie_wstrzykuje_naglowkow():
    """Prawdziwy serwer upstream zwraca NUL w wartości i nagłówek spoza allowlisty; odpowiedź proxy ma go nie zawierać."""
    import http.client
    import socket
    import threading

    gniazdo = socket.socket()
    gniazdo.bind(('127.0.0.1', 0))
    gniazdo.listen(1)
    port_up = gniazdo.getsockname()[1]

    def upstream():
        polaczenie, _ = gniazdo.accept()
        polaczenie.recv(65536)
        polaczenie.sendall(
            b'HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n'
            b'Location: /ok\r\nX-Evil: 1\r\n'
            b'Cache-Control: a\x00b\r\nContent-Length: 2\r\nConnection: close\r\n\r\nhi'
        )
        polaczenie.close()

    threading.Thread(target=upstream, daemon=True).start()
    from http.server import ThreadingHTTPServer
    handler = proxy['make_handler']('127.0.0.1', port_up, 'tok', [])
    serwer = ThreadingHTTPServer(('127.0.0.1', 0), handler)
    threading.Thread(target=serwer.serve_forever, daemon=True).start()
    try:
        c = http.client.HTTPConnection('127.0.0.1', serwer.server_address[1], timeout=10)
        c.request('GET', '/', headers={'X-Phone-Proxy-Token': 'tok'})
        r = c.getresponse()
        r.read()
        assert r.status == 200
        assert r.getheader('Content-Type') == 'text/plain'
        assert r.getheader('Location') == '/ok'
        assert r.getheader('Cache-Control') is None, 'Wartość z NUL jest pomijana'
        assert r.getheader('X-Evil') is None
    finally:
        serwer.shutdown()
        gniazdo.close()


if __name__ == '__main__':
    testy = [v for k, v in list(globals().items()) if k.startswith('test_')]
    for test in testy:
        test()
        print(f'OK: {test.__name__}')
    print(f'Wszystkie testy ({len(testy)}) przeszły.')
    sys.exit(0)
