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


if __name__ == '__main__':
    testy = [v for k, v in list(globals().items()) if k.startswith('test_')]
    for test in testy:
        test()
        print(f'OK: {test.__name__}')
    print(f'Wszystkie testy ({len(testy)}) przeszły.')
    sys.exit(0)
