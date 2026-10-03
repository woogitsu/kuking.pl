/* Fizyczna kontrola #2814: bez zapisu zakończenia powrót z alarmu nie daje +5. */
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFileSync, statSync, writeFileSync} from 'node:fs';

const sciezka = 'resources/js/app.js';
const bajty = readFileSync(sciezka);
const stat = statSync(sciezka, {bigint: true});
const kotwica = "if (aktualny !== null) sessionStorage.setItem(kluczPoAlarmie(klucz), String(Date.now()));";
const zrodlo = bajty.toString('utf8');
assert.equal(zrodlo.split(kotwica).length, 2, 'Brak jednej kotwicy #2814; nie wykonuję pozornej mutacji');

const uruchom = (args) => {
    const wynik = spawnSync(process.execPath, args, {encoding: 'utf8', env: process.env, maxBuffer: 8 * 1024 * 1024});
    return {status: wynik.status, tekst: `${wynik.stdout ?? ''}\n${wynik.stderr ?? ''}`};
};
const zbuduj = () => {
    const wynik = uruchom(['node_modules/vite/bin/vite.js', 'build']);
    assert.equal(wynik.status, 0, `Nie zbudowano assetów, wynik mutacji nieważny:\n${wynik.tekst}`);
};
const przywrocCzas = () => {
    let wynik;
    if (process.platform === 'win32') {
        // NTFS i .NET zachowują 100 ns; fs.utimesSync na Windows obcina tu ułamek ms.
        const ticks = 621355968000000000n + stat.mtimeNs / 100n;
        wynik = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
            '[IO.File]::SetLastWriteTimeUtc($env:KUKING_PLIK, [DateTime]::new([long]$env:KUKING_TICKS, [DateTimeKind]::Utc))'],
        {encoding: 'utf8', env: {...process.env, KUKING_PLIK: sciezka, KUKING_TICKS: String(ticks)}});
    } else {
        const sekundy = stat.mtimeNs / 1000000000n;
        const nano = String(stat.mtimeNs % 1000000000n).padStart(9, '0');
        wynik = spawnSync('touch', ['-m', '-d', `@${sekundy}.${nano}`, sciezka], {encoding: 'utf8'});
    }
    assert.equal(wynik.status, 0, `Nie przywrócono mtime: ${wynik.stderr ?? ''}`);
};

try {
    writeFileSync(sciezka, zrodlo.replace(kotwica, '/* #2814 mutant: brak zapisu zakończenia */'));
    zbuduj();
    const mutant = uruchom(['scripts/minutnik-powrot-http-2814.mjs']);
    assert.equal(mutant.status, 1, `Mutant nie oblał testu:\n${mutant.tekst}`);
    assert.match(mutant.tekst, /MINUTNIK_2814_POWROT_ALARMU_DODAJE_CZAS/, `Mutant oblał z obcej przyczyny:\n${mutant.tekst}`);
} finally {
    writeFileSync(sciezka, bajty);
    przywrocCzas();
    assert.deepEqual(readFileSync(sciezka), bajty, 'Źródło nie zostało odtworzone bajtowo');
    assert.equal(statSync(sciezka, {bigint: true}).mtimeNs, stat.mtimeNs, 'Czas modyfikacji źródła nie został odtworzony');
    zbuduj();
}

const dodatni = uruchom(['scripts/minutnik-powrot-http-2814.mjs']);
assert.equal(dodatni.status, 0, `Po przywróceniu źródła test nie przeszedł:\n${dodatni.tekst}`);
assert.match(dodatni.tekst, /MINUTNIK_2814_POWROT_ALARMU_DODAJE_CZAS/);
console.log('MINUTNIK_2814_KONTROLA_UJEMNA: mutant FAIL z właściwym markerem, kod przywrócony PASS');
