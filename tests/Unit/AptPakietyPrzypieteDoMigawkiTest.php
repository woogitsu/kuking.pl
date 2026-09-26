<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Audyt, issue #1868 — obrazy bazowe są przypięte do digestu (#952), ale
 * pakiety APT instalowane W ŚRODKU obrazu nie miały żadnego przypięcia.
 *
 * `deb.debian.org/debian trixie` to mirror NAJNOWSZEGO PUNKTU WYDANIA, nie
 * archiwum — starsza wersja pakietu znika z niego, gdy wyjdzie kolejna
 * poprawka. Ten sam commit i ten sam digest obrazu bazowego mogły więc dać
 * dwa różne `postgresql-client`/`tini`/`openssl`/`curl` zależnie od DNIA
 * builda, bez żadnej zmiany w repozytorium.
 *
 * Naprawą jest migawka `snapshot.debian.org` — pełne, zamrożone co ~6 godzin
 * i NIGDY nie kasowane archiwum całej historii Debiana (dokładnie ta usługa,
 * którą Debian rekomenduje do odtwarzania starych buildów). Ten test pilnuje
 * KSZTAŁTU tego przypięcia w każdym Dockerfile, nie treści konkretnej daty —
 * podniesienie `SNAPSHOT_DEBIAN` na nowszą migawkę jest świadomą, celową
 * zmianą i ten test jej nie blokuje.
 *
 * Czego ten test NIE sprawdza: czy migawka pod tą datą NAPRAWDĘ istnieje
 * i ma te pakiety. To wie tylko snapshot.debian.org, więc sprawdza to
 * pierwszy przebieg joba „Build obrazu” w CI po zmianie tej daty.
 */
final class AptPakietyPrzypieteDoMigawkiTest extends TestCase
{
    private const SNAPSHOT_URL = 'https://snapshot.debian.org/archive/debian/';

    private const TIMESTAMP = '/^\d{8}T\d{6}Z$/';

    public function test_kazdy_apt_get_install_idzie_przez_przypieta_migawke(): void
    {
        $bledy = [];
        $instalacji = 0;

        foreach (self::dockerfile() as $plik) {
            $tresc = (string) file_get_contents($plik);
            $wzgledna = self::wzgledna($plik);

            foreach (self::liniePoleceń($tresc) as [$numer, $polecenie]) {
                // Każde WYWOŁANIE `apt-get` z osobna (nie cały blok `&&`) —
                // inaczej `Dir::Etc::sourcelist=` przy TOWARZYSZĄCYM
                // `apt-get update` przepuszczałby `apt-get install` bez tej
                // opcji, dopóki oba stoją w tym samym poleceniu (pułapka
                // złapana ręczną kontrolą ujemną przy pisaniu tego testu).
                foreach (self::wywolaniaAptGet($polecenie) as $wywolanie) {
                    if (! preg_match('/\binstall\b/', $wywolanie)) {
                        continue;
                    }

                    $instalacji++;

                    if (! str_contains($wywolanie, 'Dir::Etc::sourcelist=')) {
                        $bledy[] = "{$wzgledna}:{$numer} — `apt-get install` bez `-o Dir::Etc::sourcelist=`, ".
                            'czyli bierze pakiety ze zwykłego, ruchomego mirrora Debiana zamiast z przypiętej migawki.';

                        continue;
                    }

                    $maToWarzyszaceUpdate = false;

                    foreach (self::wywolaniaAptGet($polecenie) as $inne) {
                        if (preg_match('/\bupdate\b/', $inne) === 1 && str_contains($inne, 'Dir::Etc::sourcelist=')) {
                            $maToWarzyszaceUpdate = true;

                            break;
                        }
                    }

                    if (! $maToWarzyszaceUpdate) {
                        // `apt-get update` musi wystąpić w tym samym poleceniu
                        // (ten sam blok `&&`/`;`) z TĄ SAMĄ opcją
                        // `Dir::Etc::sourcelist=`, inaczej `install` czyta
                        // metadane spoza migawki.
                        $bledy[] = "{$wzgledna}:{$numer} — `apt-get install` z `Dir::Etc::sourcelist=`, ale bez ".
                            'towarzyszącego `apt-get update` z TĄ SAMĄ opcją w tym samym poleceniu.';
                    }
                }
            }

            self::sprawdzKsztaltMigawki($plik, $tresc, $bledy);
        }

        // Parser, który nic nie znajduje, przepuszcza wszystko. Dwa pliki,
        // dwie instalacje: głównego `Dockerfile` i `docker/kopia/Dockerfile`.
        $this->assertGreaterThanOrEqual(2, $instalacji, 'Test przestał widzieć `apt-get install` — stracił przedmiot.');

        $this->assertSame([], $bledy, implode("\n", [
            'Instalacja pakietów APT bez przypięcia do migawki (audyt, issue #1868):',
            ...$bledy,
            '',
            'Wzorzec (patrz Dockerfile, etap `runtime`):',
            '  ARG SNAPSHOT_DEBIAN=<RRRRMMDDTHHMMSSZ>',
            "  RUN printf 'deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg check-valid-until=no] ".
            'https://snapshot.debian.org/archive/debian/%s/ <codename> main\n\' "$SNAPSHOT_DEBIAN" > .../snapshot.list \\',
            '   && apt-get -o Dir::Etc::sourcelist=.../snapshot.list -o Dir::Etc::sourceparts=.../puste update \\',
            '   && apt-get -o Dir::Etc::sourcelist=.../snapshot.list -o Dir::Etc::sourceparts=.../puste install -y ...',
        ]));
    }

    /**
     * KSZTAŁT samego przypięcia, nie tylko jego obecność: data w formacie
     * migawki, `check-valid-until=no` (migawka ma `Valid-Until` z przeszłości)
     * i `signed-by=` (podpis GPG oryginalnego wydania Debiana, nie
     * `[trusted=yes]`, który wyłączałby weryfikację w ogóle).
     *
     * @param  list<string>  $bledy
     */
    private static function sprawdzKsztaltMigawki(string $plik, string $tresc, array &$bledy): void
    {
        $wzgledna = self::wzgledna($plik);

        if (! str_contains($tresc, 'snapshot.debian.org')) {
            return;
        }

        if (preg_match('/ARG\s+SNAPSHOT_DEBIAN=(\S+)/', $tresc, $m) !== 1) {
            $bledy[] = "{$wzgledna} — wspomina snapshot.debian.org, ale nie ma `ARG SNAPSHOT_DEBIAN=<data>`.";

            return;
        }

        if (preg_match(self::TIMESTAMP, $m[1]) !== 1) {
            $bledy[] = "{$wzgledna} — `SNAPSHOT_DEBIAN` ({$m[1]}) nie wygląda jak znacznik czasu migawki ".
                '(oczekiwany kształt: RRRRMMDDTHHMMSSZ, np. 20260926T082540Z).';
        }

        // NAWIASY OPCJI SAMEJ LINII `deb [...]`, nie cały plik — komentarz
        // WYŻEJ w tym samym pliku tłumaczy `check-valid-until=no` słowami
        // i sam ten opis zawiera ten sam ciąg znaków. Sprawdzanie całego
        // pliku `str_contains`-em łapałoby więc komentarz zamiast prawdziwej
        // dyrektywy — złapane ręczną kontrolą ujemną przy pisaniu tego testu
        // (zmiana samej dyrektywy na `check-valid-until=yes`, zostawiając
        // komentarz bez zmian, i tak przechodziła).
        if (preg_match('/deb\s+\[([^\]]*)\]\s+https:\/\/snapshot\.debian\.org/', $tresc, $m) !== 1) {
            $bledy[] = "{$wzgledna} — wspomina snapshot.debian.org, ale nie znaleziono linii `deb [...] https://snapshot.debian.org/...` — sprawdź, czy adres i nawiasy opcji stoją w jednej linii `printf`.";

            return;
        }

        $opcje = $m[1];

        if (! str_contains($opcje, 'check-valid-until=no')) {
            $bledy[] = "{$wzgledna} — linia źródła migawki bez `check-valid-until=no` w nawiasie opcji. Migawka ma ".
                'w `Release` pole `Valid-Until` z przeszłości; bez tej flagi `apt-get update` odmówi pracy '.
                'z komunikatem „Release file expired”.';
        }

        if (! str_contains($opcje, 'signed-by=/usr/share/keyrings/debian-archive-keyring.gpg')) {
            $bledy[] = "{$wzgledna} — linia źródła migawki bez `signed-by=` w nawiasie opcji. Bez wskazania klucza ".
                'apt albo odrzuci niepodpisane repozytorium, albo (przy `trusted=yes`) w ogóle nie sprawdzi podpisu — '.
                'a to jest dokładnie ten sam oryginalny, podpisany przez Debiana `Release`, co na żywym mirrorze.';
        }

        if (str_contains($opcje, 'trusted=yes')) {
            $bledy[] = "{$wzgledna} — linia źródła migawki ma `trusted=yes` w nawiasie opcji, czyli wyłącza ".
                'weryfikację podpisu GPG zamiast wskazać klucz przez `signed-by=`.';
        }
    }

    /**
     * KONTROLA DODATNIA na samym adresie usługi: literówka w URL-u
     * (`snapshot.debian.og` itp.) przechodziłaby wszystkie testy wyżej, bo
     * szukają one fragmentów WOKÓŁ adresu, nie samego adresu.
     */
    public function test_adres_migawki_jest_poprawny(): void
    {
        $zNiewlasciwymAdresem = [];

        foreach (self::dockerfile() as $plik) {
            $tresc = (string) file_get_contents($plik);

            if (! str_contains($tresc, 'snapshot.debian.org') && ! str_contains($tresc, 'snapshot.debian')) {
                continue;
            }

            if (! str_contains($tresc, self::SNAPSHOT_URL)) {
                $zNiewlasciwymAdresem[] = self::wzgledna($plik);
            }
        }

        $this->assertSame([], $zNiewlasciwymAdresem,
            'Adres migawki nie zgadza się z `'.self::SNAPSHOT_URL."` w:\n".implode("\n", $zNiewlasciwymAdresem));
    }

    /**
     * Kontrola klasyfikatora `liniePoleceń()`: polecenie rozbite na wiele
     * linii backslashem musi zostać rozpoznane jako JEDNO polecenie — inaczej
     * `apt-get update` i `apt-get install` w osobnych liniach tego samego
     * `RUN` wyglądałyby jak dwa niepowiązane polecenia i test wyżej nie
     * złapałby braku `Dir::Etc::sourcelist=` przy `install`.
     */
    public function test_klasyfikator_laczy_linie_polaczone_backslashem(): void
    {
        $tresc = <<<'DOCKER'
            RUN apt-get -o Dir::Etc::sourcelist=/tmp/x update \
              && apt-get -o Dir::Etc::sourcelist=/tmp/x install -y --no-install-recommends \
                  tini
            DOCKER;

        $polecenia = self::liniePoleceń($tresc);

        $this->assertCount(1, $polecenia, 'Polecenie połączone `\\` ma zostać zwinięte w jeden wpis.');
        $this->assertMatchesRegularExpression('/apt-get(?:\s+-o\s+\S+)*\s+install\b/', $polecenia[0][1]);
        $this->assertStringContainsString('Dir::Etc::sourcelist=', $polecenia[0][1]);
    }

    /**
     * Rozbija JEDNO polecenie (już złączone z linii backslashem) na osobne
     * wywołania `apt-get …`, cięte na `&&`, `;` albo `|`. Dzięki temu
     * `Dir::Etc::sourcelist=` przy `update` nie „użycza" się cichcem
     * sąsiedniemu `install`, które w rzeczywistości tej opcji nie ma.
     *
     * @return list<string>
     */
    private static function wywolaniaAptGet(string $polecenie): array
    {
        $fragmenty = preg_split('/&&|;|\|/', $polecenie) ?: [];

        return array_values(array_filter(
            array_map('trim', $fragmenty),
            static fn (string $fragment): bool => str_contains($fragment, 'apt-get'),
        ));
    }

    /**
     * @return list<array{int, string}> [numer PIERWSZEJ linii polecenia, całe polecenie ze złączonych linii]
     */
    private static function liniePoleceń(string $tresc): array
    {
        $surowe = preg_split('/\r\n|\n|\r/', $tresc) ?: [];
        $polecenia = [];
        $biezace = null;
        $numerStartu = 0;

        foreach ($surowe as $i => $linia) {
            if ($biezace === null) {
                $numerStartu = $i + 1;
                $biezace = $linia;
            } else {
                $biezace .= "\n".$linia;
            }

            // Backslash na końcu linii (ignorując białe znaki) łączy ją
            // z następną — tak samo jak czyta to powłoka w `RUN`.
            if (preg_match('/\\\\\s*$/', $linia) === 1) {
                continue;
            }

            $polecenia[] = [$numerStartu, $biezace];
            $biezace = null;
        }

        if ($biezace !== null) {
            $polecenia[] = [$numerStartu, $biezace];
        }

        return $polecenia;
    }

    /** @return list<string> */
    private static function dockerfile(): array
    {
        $pliki = array_merge(
            glob(self::korzen().'/Dockerfile*') ?: [],
            glob(self::korzen().'/docker/*/Dockerfile*') ?: [],
        );

        if ($pliki === []) {
            self::fail('Nie znaleziono żadnego Dockerfile — test stracił przedmiot.');
        }

        return $pliki;
    }

    private static function wzgledna(string $plik): string
    {
        return ltrim(str_replace(self::korzen(), '', $plik), '/');
    }

    private static function korzen(): string
    {
        return dirname(__DIR__, 2);
    }
}
