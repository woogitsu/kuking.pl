"""Przyjmuje prawdziwe HTTP tylko na loopback; niczego nie przekazuje dalej."""
import http.server
import json
import os
from pathlib import Path
import subprocess
import threading

ROOT = Path(__file__).resolve().parents[2]
os.chdir(ROOT)
os.environ.update(DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT='55439',
                  DB_DATABASE='kuking_flota_gpt-monitoring', DB_USERNAME='kuking', DB_PASSWORD='kuking',
                  APP_ENV='local', CACHE_STORE='database', QUEUE_CONNECTION='database', MAIL_MAILER='array')
os.environ.pop('DB_URL', None)
received = []


class Receiver(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        received.append(body)
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'ok')

    def log_message(self, *_):
        pass


server = http.server.HTTPServer(('127.0.0.1', 8599), Receiver)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    subprocess.run(['/opt/kuking-php-8.4-avif/bin/php', 'scripts/monitoring/local.php', 'alerts'], check=True)
    expected = ['PRÓBA', 'próg ostrzegawczy 1', 'próg krytyczny 1', 'wróciły do normy',
                '900 s', 'wróciła do normy', 'padło 1 zadań', 'wróciła do normy',
                'zawieszonych 1', 'wróciła do normy', 'nie udało się', 'nie udało się']
    if len(received) != len(expected):
        raise RuntimeError(f'Odebrano {len(received)}, oczekiwano {len(expected)}')
    for message, fragment in zip(received, expected):
        text = message['text']
        if fragment.casefold() not in text.casefold():
            raise RuntimeError(f'Brak oczekiwanego fragmentu: {fragment}; treść: {text}')
        assert text.count('[Kuking/local]') == 1
        assert 'kuking_flota' not in text and '127.0.0.1' not in text
    Path('storage/monitoring-received.json').write_text(json.dumps(received, ensure_ascii=False, indent=2))
    print(f'PASS: odebrano i sprawdzono {len(received)} wiadomości przez prawdziwe HTTP.')
finally:
    server.shutdown()
    server.server_close()
