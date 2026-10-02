/* Celowany pomiar dwóch formularzy #2405 na bazie wysianej przez port-projektu. */
import { execFileSync } from 'node:child_process';
import { uruchomSerwer } from './lib/serwer-lokalny.mjs';

const serwer = await uruchomSerwer();
try {
  execFileSync(process.execPath, ['scripts/audyt-ux50plus.mjs', '--tylko-wybor-formy'], {
    env: { ...serwer.env, ADRES: serwer.adres },
    stdio: 'inherit',
  });
} finally {
  serwer.zamknij();
}
