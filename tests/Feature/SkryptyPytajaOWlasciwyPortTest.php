<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Żaden skrypt nie pyta `pg_isready` o domyślny port (#736).
 *
 * CO SIĘ STAŁO
 * `scripts/check.sh` wołał gołe `pg_isready -q`. Gołe `pg_isready` pyta o port
 * 5432 i o gniazdo miejscowe. Testy Kukinga chodzą na porcie z `DB_PORT`
 * (u nas 55439, bo klaster 5432 należy do innego projektu). Kontrola przed
 * wysłaniem zmian meldowała więc „PostgreSQL działa", patrząc na cudzą bazę,
 * a push kończył się 3737 porażkami `password authentication failed …
 * Port: 5432` — wyglądającymi na regresję kodu.
 *
 * DLACZEGO TEST NA GREPIE, A NIE NA ZACHOWANIU
 * Bo zachowania nie da się tu odtworzyć bez drugiego klastra PostgreSQL-a na
 * maszynie testowej, a wada jest czysto składniowa: wywołanie albo podaje
 * port, albo go nie podaje. Ten test pilnuje dokładnie tej jednej rzeczy
 * i nie udaje, że pilnuje więcej.
 *
 * Kontrola ujemna: dopisz gdziekolwiek `pg_isready -q` bez `-p` — test ma
 * oblać się i wskazać plik z numerem linii.
 */
class SkryptyPytajaOWlasciwyPortTest extends TestCase
{
    #[Test]
    public function test_zadne_wywolanie_pg_isready_nie_idzie_na_port_domyslny(): void
    {
        $katalogi = ['scripts', 'tests/skrypty', 'docker', '.claude/hooks'];

        $pliki = [];

        foreach ($katalogi as $katalog) {
            $sciezka = base_path($katalog);

            if (! is_dir($sciezka)) {
                continue;
            }

            foreach (glob($sciezka.'/*.sh') ?: [] as $plik) {
                $pliki[] = $plik;
            }
        }

        // Pułapka 2 z docs/PULAPKI_TESTOW.md: skan, który nic nie znalazł,
        // jest zielony i nic nie znaczy. Wiemy, że te skrypty istnieją.
        $this->assertGreaterThan(
            10,
            count($pliki),
            'Skan nie znalazł skryptów powłoki — sprawdza wtedy pustkę, nie kod.',
        );

        $bezPortu = [];

        foreach ($pliki as $plik) {
            foreach (file($plik, FILE_IGNORE_NEW_LINES) ?: [] as $nr => $linia) {
                if (! str_contains($linia, 'pg_isready')) {
                    continue;
                }

                // Komentarz mówiący o `pg_isready` nie jest wywołaniem.
                if (preg_match('/^\s*#/', $linia)) {
                    continue;
                }

                // `command -v pg_isready` pyta, czy narzędzie w ogóle jest —
                // nie łączy się z niczym, więc port nie ma tam sensu.
                if (str_contains($linia, 'command -v')) {
                    continue;
                }

                if (str_contains($linia, '-p ')) {
                    continue;
                }

                $bezPortu[] = str_replace(base_path().'/', '', $plik).':'.($nr + 1).'  '.trim($linia);
            }
        }

        $this->assertSame(
            [],
            $bezPortu,
            "Te wywołania `pg_isready` pytają o port domyślny (5432), a nie o ten, \n".
            "na którym pojadą testy. Zielone „baza działa\" o niewłaściwym porcie jest \n".
            "gorsze niż brak kontroli. Użyj `scripts/port-bazy.sh`:\n  ".
            implode("\n  ", $bezPortu),
        );
    }
}
