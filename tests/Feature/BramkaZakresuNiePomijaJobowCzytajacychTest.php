<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * BRAMKA „ZAKRES ZMIANY" NIE MOŻE POMIJAĆ JOBA, KTÓRY CZYTA WYKLUCZONY KATALOG.
 *
 * CO BYŁO ZEPSUTE (zmierzone 22.09.2026)
 * Bramka `zakres` w `.github/workflows/ci.yml` uznawała `docs/` i `README.md`
 * za „nie kod" i przy zmianie wyłącznie tam ustawiała `kod=false`, przez co
 * WSZYSTKIE ciężkie joby kończyły się jako `skipped`. W sprawdzonym przebiegu
 * 12 z 13. Siedem przebiegów zameldowało `success`, nie mierząc niczego —
 * i tą dziurą weszły na `main` dwie wady, PR-ami dokumentacyjnymi:
 *
 *   1. Pint pada na pliku `.php` leżącym w `docs/` — bo Pint skanuje CAŁE
 *      repozytorium, nie tylko `app/`;
 *   2. strażnik martwych odnośników czyta pliki `.md` — czyli dokładnie to,
 *      co bramka uznawała za niewarte sprawdzenia.
 *
 * ZAKRES BRAMKI I ZAKRES NARZĘDZI SIĘ ROZJECHAŁY. Ten test pilnuje, żeby się
 * nie rozjechały znowu — i robi to OD STRONY DOWODU, nie deklaracji:
 *
 *   - wykluczenia CZYTA z `ci.yml` (nie ma ich tu przepisanych),
 *   - czytelników CZYTA Z DYSKU (kto naprawdę otwiera pliki spod wykluczeń),
 *   - a decyzję bramki BIERZE Z URUCHOMIENIA JEJ WŁASNEGO SKRYPTU, nie
 *     z drugiej kopii tej logiki w PHP.
 *
 * Dzięki temu zapala się wtedy, kiedy ma: gdy ktoś DOŁOŻY do bramki
 * wykluczenie obejmujące katalog, z którego któreś narzędzie czyta —
 * niezależnie od tego, jak ten warunek zapisze.
 */
#[Group('ci')]
class BramkaZakresuNiePomijaJobowCzytajacychTest extends TestCase
{
    /**
     * Które narzędzie chodzi w którym jobie — jedyna rzecz, której z dysku
     * wyczytać się nie da. Nazwy jobów są sprawdzane niżej wobec `ci.yml`,
     * więc zmiana nazwy zapali ten test, a nie wyciszy go po cichu.
     */
    private const JOB_PINTA = 'lint';

    private const JOB_TESTOW = 'test';

    /**
     * Joby przeglądarkowe uruchamiane przez skrypty z `scripts/` — te same,
     * które `PortMarkiMaWlasnaBramkeCiTest` trzyma na filtrze warstwy widoku.
     */
    private const JOBY_SKRYPTOW = ['port_panelu', 'port_marki', 'port_funkcje'];

    // -----------------------------------------------------------------
    //  Właściwe kontrole
    // -----------------------------------------------------------------

    public function test_pint_nie_jest_pomijany_przy_zmianie_pliku_php_spod_wykluczenia(): void
    {
        foreach ($this->plikiPhpPodWykluczeniami() as $plik) {
            $this->assertTrue(
                $this->jobRusza(self::JOB_PINTA, [$plik]),
                "Bramka pomija Pinta przy zmianie `{$plik}`, a Pint skanuje całe repozytorium i ten plik "
                .'sprawdza. Dokładnie tą drogą weszła na `main` czerwień 22.09.2026.',
            );
        }
    }

    public function test_testy_nie_sa_pomijane_przy_zmianie_pliku_ktory_otwieraja(): void
    {
        foreach ($this->sciezkiOtwieraneZ('tests') as $sciezka => $ktoCzyta) {
            $this->assertTrue(
                $this->jobRusza(self::JOB_TESTOW, [$sciezka]),
                "Bramka pomija zestaw testów przy zmianie `{$sciezka}`, a otwiera go `{$ktoCzyta}`. "
                .'Zielony przebieg znaczyłby wtedy „nie sprawdzono".',
            );
        }
    }

    public function test_joby_przegladarkowe_nie_sa_pomijane_przy_zmianie_pliku_ktory_otwieraja(): void
    {
        foreach ($this->sciezkiOtwieraneZ('scripts') as $sciezka => $ktoCzyta) {
            foreach (self::JOBY_SKRYPTOW as $job) {
                $this->assertTrue(
                    $this->jobRusza($job, [$sciezka]),
                    "Bramka pomija job `{$job}` przy zmianie `{$sciezka}`, a otwiera ją `{$ktoCzyta}` — "
                    .'czyli wejście tego pomiaru. Podmiana wzorca bez przebiegu znaczy, że port marki '
                    .'mierzy się wobec pliku, którego nikt nie sprawdził.',
                );
            }
        }
    }

    /**
     * JOB NIE MOŻE BYĆ POMIJANY, GDY ZMIENIA SIĘ AKCJA, KTÓREJ SAM UŻYWA.
     *
     * Joby przeglądarkowe stoją na filtrze warstwy widoku, a ten wymienia
     * skrypty pomiarowe i `ci.yml` — świadomie, żeby „zmiana przyrządu
     * uruchomiła pomiar" (komentarz przy samym filtrze). Ta sama reguła musi
     * obejmować LOKALNE AKCJE, które te joby wołają przez `uses: ./…`:
     * konfigurację PHP i sprawdzenie sekretu hasła demonstracyjnego.
     *
     * Bez tego dało się zepsuć albo odwrócić warunek w akcji-strażniku i nie
     * zobaczyć ani jednego czerwonego przebiegu — bo joby, których ta akcja
     * pilnuje, byłyby przy takiej zmianie `skipped`.
     *
     * Zmierzone przed poprawką: zmiana `.github/actions/haslo-demo/action.yml`
     * dawała `kod=true`, ale `widok=false`, czyli wszystkie trzy joby
     * przeglądarkowe pominięte.
     */
    public function test_zmiana_lokalnej_akcji_uruchamia_joby_ktore_jej_uzywaja(): void
    {
        $znalezione = 0;

        foreach ($this->jobyNaFiltrzeWidoku() as $job) {
            foreach ($this->lokalneAkcjeJoba($job) as $akcja) {
                $znalezione++;

                $this->assertTrue(
                    $this->jobRusza($job, [$akcja]),
                    "Bramka pomija job `{$job}` przy zmianie `{$akcja}`, a ten job tej akcji UŻYWA "
                    .'(`uses: ./…`). Dałoby się zepsuć przyrząd bez ani jednego czerwonego przebiegu.',
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $znalezione,
            'Nie znaleziono ani jednej lokalnej akcji w jobach na filtrze warstwy widoku — '
            .'przyrząd nic nie sprawdził.',
        );
    }

    /**
     * Joby, których warunek wymaga `widok` — czyli te, które bramka potrafi
     * pominąć mimo `kod=true`. Czytane z `ci.yml`, nie wypisane tutaj.
     *
     * @return list<string>
     */
    private function jobyNaFiltrzeWidoku(): array
    {
        $joby = [];

        foreach ($this->blokiJobow() as $job => $blok) {
            foreach ($this->wiersze($blok) as $wiersz) {
                if (str_starts_with($wiersz, '    if: ') && str_contains($wiersz, 'outputs.widok')) {
                    $joby[] = $job;

                    break;
                }
            }
        }

        return $joby;
    }

    /**
     * Lokalne akcje wołane przez `uses: ./…` wewnątrz joba — jako ścieżka
     * pliku, który naprawdę by się zmienił (`<katalog>/action.yml`).
     *
     * @return list<string>
     */
    private function lokalneAkcjeJoba(string $job): array
    {
        $blok = $this->blokiJobow()[$job] ?? '';

        $this->assertNotSame('', $blok, "Nie znaleziono joba `{$job}` w `ci.yml`.");

        preg_match_all('~uses:\s*\./([A-Za-z0-9_./-]+)~', $blok, $trafienia);

        $akcje = [];

        foreach ($trafienia[1] as $katalog) {
            $plik = rtrim($katalog, '/').'/action.yml';

            if (is_file(base_path($plik))) {
                $akcje[$plik] = true;
            }
        }

        return array_keys($akcje);
    }

    /**
     * Treść każdego joba z `ci.yml`, po nazwie. Podział po wcięciu dwóch
     * spacji — tak samo, jak robią to pozostali strażnicy tego pliku.
     *
     * @return array<string, string>
     */
    private function blokiJobow(): array
    {
        $wiersze = $this->wiersze($this->workflow());
        $granice = [];

        foreach ($wiersze as $i => $w) {
            if (preg_match('/^  ([a-z0-9_]+):$/', $w, $m) === 1) {
                $granice[$i] = $m[1];
            }
        }

        $numery = array_keys($granice);
        $bloki = [];

        foreach ($numery as $k => $od) {
            $do = $numery[$k + 1] ?? count($wiersze);
            $bloki[$granice[$od]] = implode("\n", array_slice($wiersze, $od, $do - $od));
        }

        return $bloki;
    }

    /**
     * KONTROLA DODATNIA I UJEMNA PRZYRZĄDU (docs/PULAPKI_TESTOW.md §4).
     *
     * Trzy kontrole wyżej przechodziłyby ŚPIEWAJĄCO, gdyby:
     *   (a) skanowanie dysku nic nie znajdowało — a właśnie tak zachowuje się
     *       enumeracja katalogów na złym systemie plików (zmierzone: PHP widzi
     *       41 z 637 plików testowych na dysku montowanym z Windowsa);
     *   (b) `jobRusza()` zwracało `true` zawsze, bo np. skrypt bramki przestał
     *       się w ogóle wykonywać.
     *
     * Ten test sprawdza jedno i drugie, zanim tamte cokolwiek orzekną.
     */
    public function test_przyrzad_naprawde_widzi_repozytorium_i_naprawde_pyta_bramke(): void
    {
        // (a) Enumeracja. `tests/` ma w tym repozytorium setki plików; wynik
        //     rzędu kilkudziesięciu znaczy zły system plików, a nie porządki.
        $this->assertGreaterThan(
            400,
            count($this->pliki('tests', '*.php')),
            'Skanowanie `tests/` widzi podejrzanie mało plików — na takim systemie plików ŻADEN wynik '
            .'tego pliku nie jest wiarygodny (patrz `docs/PULAPKI_TESTOW.md`).',
        );

        $this->assertNotSame([], $this->wykluczenia(), 'Nie odczytano wykluczeń z bramki `zakres`.');

        // (b) Bramka odpowiada, i odpowiada RÓŻNIE. Gdyby zwracała stale to
        //     samo, kontrole wyżej byłyby ozdobą.
        $this->assertTrue(
            $this->jobRusza(self::JOB_TESTOW, ['app/Models/Notification.php']),
            'Bramka pomija testy przy zmianie kodu produktu — przyrząd albo bramka są zepsute.',
        );

        $this->assertFalse(
            $this->jobRusza('dostepnosc', ['docs/PRODUCT.md']),
            'Bramka uruchamia job dostępności przy zmianie samego `docs/PRODUCT.md`. Ten job niczego '
            .'stamtąd nie czyta, więc albo bramka przestała rozróżniać, albo przyrząd zawsze mówi „rusza".',
        );
    }

    // -----------------------------------------------------------------
    //  Przyrząd
    // -----------------------------------------------------------------

    private function workflow(): string
    {
        $sciezka = base_path('.github/workflows/ci.yml');
        $tresc = is_file($sciezka) ? file_get_contents($sciezka) : false;

        $this->assertIsString($tresc, 'Nie da się odczytać `.github/workflows/ci.yml`.');

        return $tresc;
    }

    /**
     * Ścieżki, które bramka uznaje za „nie kod" — odczytane z jej wzorca,
     * a nie przepisane tutaj.
     *
     * @return list<string>
     */
    private function wykluczenia(): array
    {
        $this->assertSame(
            1,
            preg_match("/grep -vE '\\^\\(([^']+)\\)'/", $this->workflow(), $m),
            'W bramce `zakres` nie ma filtra „co jest dokumentacją" albo jest go więcej niż jeden.',
        );

        $sciezki = [];

        foreach (explode('|', $m[1]) as $wariant) {
            $sciezka = str_replace(['\\.', '$'], ['.', ''], $wariant);

            if ($sciezka !== '') {
                $sciezki[] = $sciezka;
            }
        }

        return $sciezki;
    }

    /**
     * Pliki `.php` leżące pod wykluczeniami bramki — czyli te, które Pint
     * sprawdza, a bramka uznawałaby za niewarte przebiegu.
     *
     * ZAKRESEM PINTA JEST CAŁE REPOZYTORIUM poza tym, co wyłącza `pint.json`.
     * Jego `exclude`/`notPath` czytamy tutaj i ODEJMUJEMY: plik, którego Pint
     * nie sprawdza, nie jest powodem, żeby uruchamiać joba. Gdyby ten test
     * zamiast tego wymagał, żeby `pint.json` nie miał wykluczeń, zapaliłby
     * się na cudzej poprawce zamiast na usterce bramki.
     *
     * @return list<string>
     */
    private function plikiPhpPodWykluczeniami(): array
    {
        $pint = json_decode((string) file_get_contents(base_path('pint.json')), true);
        $pint = is_array($pint) ? $pint : [];

        /** @var list<string> $poza */
        $poza = array_merge(
            is_array($pint['exclude'] ?? null) ? $pint['exclude'] : [],
            is_array($pint['notPath'] ?? null) ? $pint['notPath'] : [],
        );

        $znalezione = [];

        foreach ($this->wykluczenia() as $wykluczenie) {
            if (! str_ends_with($wykluczenie, '/')) {
                continue;
            }

            foreach ($this->pliki(rtrim($wykluczenie, '/'), '*.php') as $plik) {
                foreach ($poza as $pominiete) {
                    if ($plik === $pominiete || str_starts_with($plik, rtrim((string) $pominiete, '/').'/')) {
                        continue 2;
                    }
                }

                $znalezione[] = $plik;
            }
        }

        return $znalezione;
    }

    /**
     * Ścieżki spod wykluczeń, które pliki z `$katalog` NAPRAWDĘ OTWIERAJĄ —
     * nie takie, o których tylko wspominają w komentarzu. Stąd wymóg, żeby
     * ścieżka stała w wywołaniu otwierającym plik.
     *
     * @return array<string, string> ścieżka => plik, który ją otwiera
     */
    private function sciezkiOtwieraneZ(string $katalog): array
    {
        $otwarcia = 'file_get_contents|file_exists|base_path|readFileSync|readFile|->open|json_decode';
        $znalezione = [];

        foreach ($this->wykluczenia() as $wykluczenie) {
            if (! str_ends_with($wykluczenie, '/')) {
                continue;
            }

            $wzorzec = '~(?:'.$otwarcia.')[^\n]*?[\'"]('.preg_quote($wykluczenie, '~').'[A-Za-z0-9_./-]+)~';

            foreach ($this->pliki($katalog) as $plik) {
                $tresc = (string) file_get_contents(base_path($plik));

                if (preg_match_all($wzorzec, $tresc, $trafienia) < 1) {
                    continue;
                }

                foreach ($trafienia[1] as $sciezka) {
                    // Tylko istniejące pliki: ścieżka złożona w kodzie
                    // z kawałków albo wzorzec z gwiazdką nie są dowodem.
                    if (is_file(base_path($sciezka))) {
                        $znalezione[$sciezka] ??= $plik;
                    }
                }
            }
        }

        return $znalezione;
    }

    /**
     * Lista plików — `find`, a NIE `RecursiveDirectoryIterator`. Ta druga
     * droga na dysku montowanym z Windowsa widzi ułamek plików i milczy
     * o tym, przez co strażnik enumerujący katalog świeci fałszywą zielenią.
     *
     * @return list<string>
     */
    private function pliki(string $katalog, string $maska = '*'): array
    {
        $proces = new Process(['find', $katalog, '-type', 'f', '-name', $maska], base_path());
        $proces->run();

        $pliki = [];

        foreach ($this->wiersze($proces->getOutput()) as $linia) {
            if ($linia !== '') {
                $pliki[] = $linia;
            }
        }

        return $pliki;
    }

    // -----------------------------------------------------------------
    //  Uruchomienie PRAWDZIWEJ bramki
    // -----------------------------------------------------------------

    /**
     * Czy job `$job` ruszy, gdy zmienią się dokładnie te pliki.
     *
     * Wyjścia bramki biorą się z URUCHOMIENIA jej skryptu powłoki — druga
     * kopia tej logiki w PHP rozjechałaby się z oryginałem i to ona byłaby
     * wtedy sprawdzana.
     *
     * @param  list<string>  $zmienione
     */
    private function jobRusza(string $job, array $zmienione): bool
    {
        return $this->spelniony($this->warunekJoba($job), $this->wyjsciaBramki($zmienione));
    }

    /** @return array<string, string> */
    private function wyjsciaBramki(array $zmienione): array
    {
        $skrypt = $this->skryptBramki();
        $wyjscie = tempnam(sys_get_temp_dir(), 'bramka');

        $this->assertIsString($wyjscie);

        $proces = new Process(['bash', '-c', $skrypt, 'bramka', ...$zmienione], base_path(), [
            'GITHUB_OUTPUT' => $wyjscie,
        ]);
        $proces->run();

        $this->assertSame(
            0,
            $proces->getExitCode(),
            'Skrypt bramki `zakres` padł: '.$proces->getErrorOutput(),
        );

        $wynik = [];

        foreach ($this->wiersze((string) file_get_contents($wyjscie)) as $linia) {
            if (str_contains($linia, '=')) {
                [$klucz, $wartosc] = explode('=', $linia, 2);
                $wynik[$klucz] = $wartosc;
            }
        }

        @unlink($wyjscie);

        $this->assertArrayHasKey('kod', $wynik, 'Bramka nie wystawiła `kod`.');

        return $wynik;
    }

    /**
     * Skrypt kroku `sprawdz` z podmienionym ŹRÓDŁEM listy zmian: zamiast
     * `git diff` bierze argumenty. Reszta — cała logika decyzji — zostaje
     * dokładnie ta, która chodzi w CI.
     */
    private function skryptBramki(): string
    {
        $linie = $this->wiersze($this->workflow());

        $poczatek = null;

        foreach ($linie as $i => $linia) {
            if (str_contains($linia, 'ZMIENIONE="$(git diff')) {
                $poczatek = $i;
                break;
            }
        }

        $this->assertIsInt($poczatek, 'W bramce `zakres` nie ma kroku liczącego listę zmian.');

        // Blok `run: |` ma wcięcie dziesięciu spacji. Ciało kroku kończy się
        // na pierwszej niepustej linii płycej wciętej — tam zaczyna się już
        // co innego w pliku.
        $skrypt = [];

        for ($i = (int) $poczatek + 1; $i < count($linie); $i++) {
            $linia = $linie[$i];

            if ($linia === '') {
                $skrypt[] = '';

                continue;
            }

            if (! str_starts_with($linia, '          ')) {
                break;
            }

            $skrypt[] = substr($linia, 10);
        }

        $this->assertNotSame([], $skrypt, 'Ciało kroku bramki wyszło puste — zmienił się kształt `ci.yml`.');

        return "set -euo pipefail\n"
            ."ZMIENIONE=\"\$(printf '%s\\n' \"\$@\" | grep -v '^\$' || true)\"\n"
            .implode("\n", $skrypt);
    }

    /**
     * Podział na wiersze BEZ `preg_split('/\R/')`.
     *
     * To nie jest ozdoba: `\R` bez modyfikatora `u` pracuje na bajtach
     * i dopasowuje 0x85 (NEL), a 0x85 jest DRUGIM BAJTEM litery „ą" (U+0105,
     * C4 85) w UTF-8. Na polskich komentarzach w `ci.yml` taki podział tnie
     * w środku znaku i przyrząd czyta połowę linii — a strażnik czytający
     * połowę pliku świeci zielenią, która nic nie znaczy.
     *
     * @return list<string>
     */
    private function wiersze(string $tekst): array
    {
        return explode("\n", str_replace("\r\n", "\n", $tekst));
    }

    private function warunekJoba(string $job): string
    {
        $this->assertSame(
            1,
            preg_match('/^  '.preg_quote($job, '/').':$.*?^    if: ([^\n]+)$/ms', $this->workflow(), $m),
            "Job `{$job}` nie istnieje w `ci.yml` albo nie ma warunku — zmieniła się nazwa, a ten test "
            .'ma o tym powiedzieć, a nie zamilknąć.',
        );

        return trim($m[1]);
    }

    /**
     * Ocena warunku joba. Świadomie obsługuje TYLKO kształty, które w tym
     * pliku występują: porównania `needs.zakres.outputs.X == 'true'` złączone
     * `&&` oraz `||`, bez nawiasów. Warunek spoza tego zbioru ma zapalić test,
     * a nie zostać po cichu uznany za spełniony.
     *
     * @param  array<string, string>  $wyjscia
     */
    private function spelniony(string $warunek, array $wyjscia): bool
    {
        $this->assertStringNotContainsString(
            '(',
            $warunek,
            "Warunek „{$warunek}".'" ma nawiasy, a ten przyrząd ich nie ocenia. Dopisz go, zamiast '
            .'zostawiać kontrolę, która nic nie sprawdza.',
        );

        foreach (explode('||', $warunek) as $alternatywa) {
            $wszystkie = true;

            foreach (explode('&&', $alternatywa) as $skladnik) {
                $this->assertSame(
                    1,
                    preg_match("/^\\s*needs\\.zakres\\.outputs\\.([a-z]+) == '([a-z]+)'\\s*$/", $skladnik, $m),
                    "Nieznany kształt warunku: „{$skladnik}\".",
                );

                $wszystkie = $wszystkie && (($wyjscia[$m[1]] ?? '') === $m[2]);
            }

            if ($wszystkie) {
                return true;
            }
        }

        return false;
    }
}
