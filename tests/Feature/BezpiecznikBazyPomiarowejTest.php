<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Przyrządy pomiarowe nie skasują cudzej bazy (#736).
 *
 * DLACZEGO TEN PLIK ISTNIEJE, CHOĆ TEST JEST W NODE
 * -------------------------------------------------
 * Bo `scripts/*.mjs` to JavaScript uruchamiany Node'em, a `php artisan test`
 * go nie widzi — ten sam powód i ten sam kształt, co
 * `ServiceWorkerOdswiezaMarkeTest`. Zabezpieczenie, o którym wie tylko CI,
 * jest zabezpieczeniem, o którego zniknięciu nikt się lokalnie nie dowie.
 *
 * CZEGO TO PILNUJE
 * Kilkanaście przyrządów w `scripts/` robi `php artisan migrate:fresh --seed`,
 * czyli kasuje całą zawartość bazy z `DB_DATABASE`. Do #736 dwa z nich miały
 * listę ZAKAZÓW `['kuking', 'kuking_test']`, a czternaście nie miało nic.
 * Po zmianie nazewnictwa baz testowych (`tests/nazwa-bazy.php`) nazwa bazy
 * kopii roboczej prawie nigdy nie brzmi już dokładnie `kuking_test` — ma
 * sufiks worktree albo `_kat_<katalog>_<skrót>`. Lista dosłownych nazw nie
 * trafiałaby więc niemal nigdy, zostając w kodzie jako zabezpieczenie,
 * którego w praktyce nie ma. Dlatego bezpiecznik rozpoznaje RODZINY nazw.
 *
 * Najważniejszy z przypadków w pliku Node'a to ostatni: skan wymagający, żeby
 * KAŻDY skrypt z `migrate:fresh` wołał `ustalBazePomiarowa()`. Sam bezpiecznik
 * może być bez zarzutu i nikomu niepotrzebny, jeśli skrypty przestaną go wołać.
 *
 * @see scripts/bezpiecznik-bazy.mjs
 * @see scripts/bezpiecznik-bazy.test.mjs
 *
 * @bez-kontroli-dodatniej base_path() podaje tylko ścieżkę do node --test, a asercje tekstowe czytają wyjście tego przebiegu (liczbę zdanych i nazwę skanu), nie treść źródła; kontrolę ujemną niesie sam skan (próg 15 skryptów) i bezpiecznik-bazy.test.mjs.
 */
class BezpiecznikBazyPomiarowejTest extends TestCase
{
    #[Test]
    public function test_bezpiecznik_baz_pomiarowych_przechodzi_wlasne_testy(): void
    {
        $skrypt = base_path('scripts/bezpiecznik-bazy.test.mjs');

        // Pułapka 2 z docs/PULAPKI_TESTOW.md: bez tej asercji test byłby
        // zielony także wtedy, gdyby plik testów zniknął.
        $this->assertFileExists(
            $skrypt,
            'Nie ma scripts/bezpiecznik-bazy.test.mjs — bez niego nic nie pilnuje, '.
            'żeby przyrządy pomiarowe nie robiły `migrate:fresh` na cudzej bazie.',
        );

        $proces = new Process(['node', '--test', $skrypt], base_path());
        $proces->setTimeout(120);
        $proces->run();

        $wyjscie = $proces->getOutput().$proces->getErrorOutput();

        $this->assertSame(
            0,
            $proces->getExitCode(),
            "Testy bezpiecznika baz pomiarowych OBLEWAJĄ SIĘ. Uruchom je wprost:\n".
            "  node --test scripts/bezpiecznik-bazy.test.mjs\n\n".$wyjscie,
        );

        // KONTROLA DODATNIA. Kod 0 dostalibyśmy także od pliku, z którego
        // wycięto wszystkie przypadki.
        //
        // Wzorzec łapie OBA podsumowania Node'a: TAP-owe „# pass N" i to
        // z domyślnego reportera 24.x, „ℹ pass N". Pierwsza wersja znała
        // tylko TAP i oblała się na żywym Node 24 — czyli nie na tym,
        // czego pilnuje.
        $this->assertMatchesRegularExpression(
            '/(?:^#|\x{2139})\s*pass\s+(\d+)/mu',
            $wyjscie,
            "Node nie zameldował liczby zdanych przypadków.\n\n".$wyjscie,
        );

        preg_match('/(?:^#|\x{2139})\s*pass\s+(\d+)/mu', $wyjscie, $zdane);

        $this->assertGreaterThanOrEqual(
            10,
            (int) ($zdane[1] ?? 0),
            'Plik testów bezpiecznika skurczył się poniżej 10 przypadków — '.
            "sprawdź, czy któregoś nie wycięto.\n\n".$wyjscie,
        );

        $this->assertStringContainsString(
            'kazdy skrypt robiacy migrate:fresh wola bezpiecznik',
            $wyjscie,
            'W przebiegu nie ma skanu wymagającego, żeby skrypty faktycznie wołały '.
            'bezpiecznik. Bez niego można go po cichu odłączyć od wszystkich '.
            "przyrządów i testy zostaną zielone.\n\n".$wyjscie,
        );
    }
}
