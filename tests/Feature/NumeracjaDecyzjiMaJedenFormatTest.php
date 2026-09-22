<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * KAŻDY WPIS W DZIENNIKU MA NUMER, I TO NUMER W JEDNYM KSZTAŁCIE (D-235).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TO ŁAPIE — I DLACZEGO NIE ŁAPAŁ TEGO ISTNIEJĄCY STRAŻNIK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `NumeryDecyzjiMajaWpisyTest` pilnuje DWÓCH rzeczy: że numer cytowany
 * w kodzie ma wpis (sierota) i że jeden numer nie ma dwóch wpisów (duplikat).
 * Obie chodzą po wzorcu `^## D-(\d{3})\b` — czyli po wpisach, które są
 * w formacie. Wpis POZA formatem jest dla nich po prostu niewidzialny:
 * nie liczy się ani jako istniejący, ani jako duplikat.
 *
 * Zmierzone na `main` 22 września 2026, dwa takie wpisy:
 *
 *   • `## D-1009-ROBOCZA — Pierwszy wkład…` — stał między D-222 a D-224
 *     i czytało się go jako D-100, czyli numer, który w tym dzienniku NIE MA
 *     wpisu. Gdyby ktoś napisał w kodzie „(D-100)", `NumeryDecyzjiMajaWpisyTest`
 *     zgłosiłby sierotę, a człowiek szukający przyczyny znalazłby nagłówek,
 *     który wygląda na odpowiedź i nią nie jest;
 *   • `## Uzupełnienie #369 — Próg prezentacji…` — wpis bez numeru, na końcu
 *     pliku. Nie da się na niego powołać z kodu, bo cały mechanizm odnośników
 *     w tym repozytorium stoi na numerze.
 *
 * Oba dostały numery (D-231 i D-232), a ten test pilnuje, żeby trzeci taki
 * nie wszedł po cichu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN TEST NIE UMIE — I CO TO ZA NIEGO ROBI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie widzi CUDZYCH GAŁĘZI, bo czyta jeden plik w jednym drzewie. Dwie
 * gałęzie dopisujące własne „## D-226" są tu OBIE zielone — i to jest
 * dokładnie ta awaria, o której mówi D-228. Odpowiada na nią
 * `scripts/numery-decyzji.sh`, który porównuje numery tej gałęzi z numerami
 * WSZYSTKICH gałęzi zdalnych i chodzi w `scripts/check.sh` przed PR-em.
 *
 * Dlatego ostatnia asercja niżej pilnuje, żeby ten skrypt istniał i był
 * wpięty w `check.sh`. Bez niej ten plik cicho przejąłby rolę, której nie
 * umie pełnić: zielony wynik znaczyłby „numeracja sprawdzona", a sprawdzona
 * byłaby połowa.
 */
class NumeracjaDecyzjiMaJedenFormatTest extends TestCase
{
    /**
     * Nagłówki drugiego poziomu, które NIE są wpisem decyzji i mają prawo
     * istnieć: wstęp, spis, sekcje porządkowe. Lista jest WĄSKA celowo —
     * szeroka reguła („wszystko, co nie zaczyna się od D-") przepuściłaby
     * z powrotem „## Uzupełnienie #369".
     *
     * @var list<string>
     */
    private const WZORCE_NAGLOWKOW_NIEBEDACYCH_WPISEM = [
        // Wpis decyzji — jedyny dopuszczalny kształt.
        '/^## D-\d{3}(\s|$)/',
    ];

    public function test_kazdy_naglowek_wygladajacy_na_wpis_decyzji_ma_numer_w_jednym_ksztalcie(): void
    {
        $wiersze = $this->wierszeDziennika();
        $podejrzane = [];

        foreach ($wiersze as $numerLinii => $wiersz) {
            if (! str_starts_with($wiersz, '## ')) {
                continue;
            }

            // Nagłówek jest wpisem decyzji, jeśli zaczyna się od „D-" albo od
            // słowa, którym w tym pliku nazywano wpisy bez numeru.
            $wyglada = (bool) preg_match('/^## (D-|Uzupe[łl]nienie\b|Aneks\b|Dopisek\b)/u', $wiersz);

            if (! $wyglada) {
                continue;
            }

            foreach (self::WZORCE_NAGLOWKOW_NIEBEDACYCH_WPISEM as $wzorzec) {
                if (preg_match($wzorzec, $wiersz)) {
                    continue 2;
                }
            }

            $podejrzane[] = 'docs/DECISIONS.md:'.($numerLinii + 1).' — '.$wiersz;
        }

        $this->assertSame(
            [],
            $podejrzane,
            "Wpis dziennika poza formatem „## D-NNN\":\n\n    "
            .implode("\n    ", $podejrzane)
            ."\n\nTaki nagłówek jest dla strażników NIEWIDOCZNY: nie liczy się ani jako\n"
            ."istniejący wpis, ani jako duplikat, a odnośnik do niego z kodu byłby\n"
            ."martwy. Numer bierz z `./scripts/numery-decyzji.sh --nastepny-wolny`,\n"
            .'który patrzy także na gałęzie zdalne.',
        );
    }

    /**
     * PRÓG — bez niego ten test jest zielony na zawsze.
     *
     * Skan, który przeczytał pusty plik albo trafił w złą ścieżkę, nie
     * znajdzie żadnego podejrzanego nagłówka i przejdzie
     * (`docs/PULAPKI_TESTOW.md`, pułapka 2). Próg jest niski wobec stanu na
     * dziś (ponad 230 wpisów), bo pilnuje ścieżki i wzorca, a nie tempa pracy.
     */
    public function test_skan_naprawde_czyta_dziennik(): void
    {
        $wpisy = preg_match_all('/^## D-\d{3}(\s|$)/m', implode("\n", $this->wierszeDziennika()));

        $this->assertGreaterThanOrEqual(
            70,
            $wpisy,
            'W docs/DECISIONS.md widać mniej niż 70 wpisów (znaleziono '.$wpisy.'). '
            .'Dziennik się nie kurczy, więc to usterka tego testu: sprawdź ścieżkę '
            .'do pliku i wzorzec nagłówka.',
        );
    }

    /**
     * Strażnik międzygałęziowy istnieje i chodzi PRZED PR-em.
     *
     * Ten test świadomie sprawdza WPIĘCIE, a nie zachowanie skryptu: jego
     * własne zachowanie mierzy kontrola ujemna w nim samym
     * (`./scripts/numery-decyzji.sh --kontrola-ujemna`), która podstawia
     * zepsuty dziennik i wymaga, żeby strażnik go zgłosił. Tu chodzi o to,
     * żeby nikt nie usunął go z jedynej komendy, którą ludzie naprawdę
     * uruchamiają przed wysłaniem zmian.
     */
    public function test_straznik_miedzygaleziowy_jest_wpiety_w_check_sh(): void
    {
        $skrypt = base_path('scripts/numery-decyzji.sh');

        $this->assertFileExists(
            $skrypt,
            'Nie ma scripts/numery-decyzji.sh — a to jest jedyna kontrola, która '
            .'widzi numer wzięty równolegle na CUDZEJ gałęzi. Bez niej duplikat '
            .'wychodzi dopiero po scaleniu drugiej gałęzi (D-228).',
        );

        $this->assertStringContainsString(
            'numery-decyzji.sh',
            (string) file_get_contents(base_path('scripts/check.sh')),
            'scripts/check.sh przestał wołać strażnika numeracji. AGENTS.md §10 mówi, '
            .'że przed PR-em uruchamia się JEDNĄ komendę — kontrola, której ta '
            .'komenda nie woła, nie jest uruchamiana wcale.',
        );
    }

    /** @return list<string> */
    private function wierszeDziennika(): array
    {
        $sciezka = base_path('docs/DECISIONS.md');

        $this->assertFileExists($sciezka, 'Nie ma docs/DECISIONS.md.');

        return explode("\n", (string) file_get_contents($sciezka));
    }
}
