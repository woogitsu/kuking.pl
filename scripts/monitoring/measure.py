"""Lokalny pomiar połączeń. Uruchamiaj po przygotowaniu runtime, bez innych testów tej bazy."""
import json
import os
from pathlib import Path
import signal
import subprocess
import threading
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
os.chdir(ROOT)
os.environ.update(DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT='55439',
                  DB_DATABASE='kuking_flota_gpt-monitoring', DB_USERNAME='kuking', DB_PASSWORD='kuking',
                  APP_ENV='local', CACHE_STORE='database', SESSION_DRIVER='database',
                  QUEUE_CONNECTION='database', MAIL_MAILER='array', LOG_BLAD_WEBHOOK_URL='',
                  APP_URL='http://127.0.0.1:8598', SESSION_SECURE_COOKIE='false')
os.environ.pop('DB_URL', None)
PHP = '/opt/kuking-php-8.4-avif/bin/php'
HELPER = [PHP, 'scripts/monitoring/local.php']


def run(args):
    return subprocess.run(args, check=True, capture_output=True, text=True, timeout=120).stdout


samples = []
sampler = subprocess.Popen(HELPER + ['sample'], stdout=subprocess.PIPE, text=True)


def read_samples():
    for line in sampler.stdout:
        samples.append(json.loads(line))


thread = threading.Thread(target=read_samples, daemon=True)
thread.start()
results = {}


def measure(name, action):
    begin = time.time()
    detail = action()
    time.sleep(.15)
    rows = [s for s in samples if s['time'] >= begin]
    if not rows:
        raise RuntimeError('Próbnik nie zwrócił żadnej próbki')
    results[name] = dict(samples=len(rows), peak=max(int(s['total']) for s in rows),
                         peak_active=max(int(s['active']) for s in rows),
                         peak_idle=max(int(s['idle']) for s in rows), detail=detail)
    print(name, json.dumps(results[name]), flush=True)


def control():
    time.sleep(.3)
    children = [subprocess.Popen(HELPER + ['hold'], stdout=subprocess.DEVNULL) for _ in range(6)]
    for child in children:
        if child.wait() != 0:
            raise RuntimeError('Proces kontrolny nie zakończył się poprawnie')


server = None
try:
    measure('idle_before', lambda: time.sleep(.5))
    measure('dynamic_control_6', control)
    assert results['idle_before']['peak'] == 0
    assert results['dynamic_control_6']['peak'] == 6
    routes = json.loads(Path('storage/monitoring-routes.json').read_text())
    server_log = open('storage/monitoring-http.txt', 'w')
    server = subprocess.Popen([PHP, '-S', '127.0.0.1:8598',
                               str(ROOT / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
                              cwd=ROOT / 'public', stdout=server_log, stderr=server_log, start_new_session=True)
    time.sleep(1)

    def http_load():
        timings = []
        for i in range(60):
            start = time.monotonic()
            with urllib.request.urlopen('http://127.0.0.1:8598' + routes[i % len(routes)], timeout=30) as response:
                assert response.status == 200
                assert len(response.read()) > 1000
            timings.append((time.monotonic() - start) * 1000)
        timings.sort()
        return {'requests': len(timings), 'p50_ms': timings[29], 'p95_ms': timings[56],
                'p99_ms': timings[59], 'http': '200 x 60'}

    measure('web_one_process', http_load)
    measure('worker_12_images', lambda: run([PHP, 'artisan', 'queue:work', '--queue=media',
                                          '--stop-when-empty', '--tries=1', '--no-interaction']))
    measure('scheduler_10_runs', lambda: [run(HELPER + ['scheduler']) for _ in range(10)])
    measure('migrate_no_pending', lambda: run([PHP, 'artisan', 'migrate', '--force', '--no-interaction']))
    measure('idle_after', lambda: time.sleep(.5))
    verification = json.loads(run(HELPER + ['verify']))
    assert verification['ready'] == 12 and verification['jobs'] == 0 and verification['failed'] == 0
    assert results['idle_after']['peak'] == 0
    Path('storage/monitoring-measurement.json').write_text(json.dumps(
        {'measured_at_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
         'results': results, 'verification': verification, 'samples': samples}, indent=2))
finally:
    if server:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait()
    sampler.terminate()
    sampler.wait()
