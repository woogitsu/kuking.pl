import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// To test transmisji korpusu, nie jakości zdjęć ani wydajności portalu.
const dir = mkdtempSync(join(tmpdir(), 'kuking-korpus-605-'));
const seen = [];
let rejectAll = false;
const server = createServer(async (req, res) => {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const body = Buffer.concat(chunks).toString();
  const name = body.match(/filename="([^"]+)"/)?.[1];
  if (name) seen.push(name);
  res.writeHead(req.method === 'POST' ? 302 : 200, { location: (rejectAll || name === 'broken.jpg') ? '/dodaj/zdjecie' : '/wpisy/00000000-0000-4000-8000-000000000001' });
  res.end('ok');
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
try {
  const corpus = ['phone.jpg', 'camera.jpg', 'broken.jpg'].map((name) => {
    const bytes = Buffer.from(`bajty pliku ${name}`);
    writeFileSync(join(dir, name), bytes);
    return { path: join(dir, name), sha256: createHash('sha256').update(bytes).digest('hex'), mime: 'image/jpeg', expected: (rejectAll || name === 'broken.jpg') ? 'rejected' : 'accepted' };
  });
  writeFileSync(join(dir, 'kuking-b605-12mpx.jpg'), 'stary pojedynczy plik');
  writeFileSync(join(dir, 'corpus.json'), JSON.stringify(corpus));
  writeFileSync(join(dir, 'random.cjs'), 'Math.random = () => 0.999;');
  writeFileSync(join(dir, 'manifest.json'), JSON.stringify({ sesje: [{ ciasteczka: 'test=1', token: 'test' }], cele: { przepisy: ['/przepisy/test'], wpisy: ['/wpisy/test'], tagi: ['/tag/test'], profile: ['/@test'], media: ['/zdjecia/test/feed'] } }));
  const args = ['--require', join(dir, 'random.cjs'), fileURLToPath(new URL('./generator-obciazenia-605.mjs', import.meta.url)), 'seria', '--baza', `http://127.0.0.1:${server.address().port}`, '--manifest', join(dir, 'manifest.json'), '--zdjecia', dir, '--korpus', join(dir, 'corpus.json'), '--rps', '12', '--czas', '1', '--wynik', join(dir, 'result.json')];
  const child = spawn(process.execPath, args, { stdio: ['ignore', 'ignore', 'pipe'] });
  let error = ''; child.stderr.on('data', d => { error += d; });
  const timer = setTimeout(() => child.kill(), 10000);
  const code = await new Promise(r => child.on('exit', r)); clearTimeout(timer);
  assert.equal(code, 0, error);
  assert.deepEqual([...new Set(seen)].sort(), ['broken.jpg', 'camera.jpg', 'phone.jpg'], 'Rampa musi wysłać wszystkie pliki korpusu, nie jeden stały JPEG');
  const result = JSON.parse(readFileSync(join(dir, 'result.json')));
  assert.equal(result.uploady.length, 3);
  assert.ok(result.uploady.every(p => p.wyslanych > 0 && p.zgodnych > 0));
  assert.equal(result.razem.nieudanych, 0);
  rejectAll = true;
  const rejected = spawn(process.execPath, args, { stdio: 'ignore' });
  const rejectTimer = setTimeout(() => rejected.kill(), 10000);
  const rejectCode = await new Promise(r => rejected.on('exit', r)); clearTimeout(rejectTimer);
  assert.equal(rejectCode, 0);
  const failures = JSON.parse(readFileSync(join(dir, 'result.json')));
  assert.ok(failures.razem.nieudanych > 0, '302 wracające do formularza nie może udawać publikacji poprawnego zdjęcia');
  assert.ok(failures.uploady.filter(p => p.oczekiwane === 'accepted').every(p => p.zgodnych === 0));
  assert.ok(failures.uploady.find(p => p.oczekiwane === 'rejected').zgodnych > 0);
  writeFileSync(corpus[0].path, 'zmienione bajty');
  const invalid = spawn(process.execPath, args, { stdio: 'ignore' });
  assert.notEqual(await new Promise(r => invalid.on('exit', r)), 0, 'Zmieniony korpus ma zatrzymać pomiar przed napływem');
  console.log('PASS: korpus rotuje; odrzucenie nie udaje publikacji; zmienione bajty zatrzymują pomiar');
} finally {
  server.closeAllConnections(); await new Promise(r => server.close(r));
  rmSync(dir, { recursive: true, force: true });
}