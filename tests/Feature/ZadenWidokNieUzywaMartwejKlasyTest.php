<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Żaden widok nie używa klasy, która nie ma reguły w arkuszu — po #581.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * W #581 znalazły się dwa ekrany panelu z `class="lead"`. Takiej reguły nie
 * ma w żadnym arkuszu i nigdy nie było: system marki generuje `text-lead`
 * z tokenu `--text-lead` w bloku `@theme`. Akapit wprowadzający renderował
 * się więc wielkością tekstu podstawowego (18 px) zamiast wprowadzenia
 * (22 px) — nic nie wyglądało na zepsute, po prostu nic się nie działo.
 *
 * Tamta poprawka dodała test na DWA konkretne ekrany panelu. Dzień później
 * ta sama martwa klasa siedziała jeszcze w czterech miejscach poza panelem,
 * w tym w pliku scalonym tego samego dnia. Test na wyliczone ekrany nie mógł
 * ich złapać, bo wymienia ekrany z nazwy.
 *
 * DLACZEGO TO JEST TEST NA ŹRÓDŁO, A NIE NA ODPOWIEDŹ HTTP
 * Bo pytanie brzmi „czy KTÓRYKOLWIEK widok w repozytorium jej używa", a nie
 * „czy używa jej ten jeden ekran". Postawienie żądania do każdego widoku
 * wymagałoby trasy, uprawnień i danych dla każdego z nich — a i tak
 * pominęłoby widoki osiągalne tylko w rzadkim stanie. Odczyt źródeł obejmuje
 * wszystkie, bez wyjątku i bez bazy.
 *
 * Test na odpowiedź HTTP zostaje tam, gdzie jest sensowny:
 * `PanelUzywaTypografiiMarkiTest` sprawdza, że akapit panelu NAPRAWDĘ dostaje
 * rozmiar z systemu. Te dwa testy odpowiadają na różne pytania i żaden nie
 * zastępuje drugiego.
 *
 * JAK DOPISAĆ KOLEJNĄ MARTWĄ KLASĘ
 * Do `MARTWE` — wraz z nazwą tej, która jest prawidłowa. Warunek wejścia
 * jest jeden i ten test go sprawdza: klasa nie może mieć reguły w żadnym
 * arkuszu ŹRÓDŁOWYM. Gdyby ktoś kiedyś taką regułę dopisał, ten test
 * upomni się o skreślenie wpisu z listy, zamiast po cichu zabraniać czegoś,
 * co zaczęło działać.
 */
class ZadenWidokNieUzywaMartwejKlasyTest extends TestCase
{
    /**
     * Klasa martwa => klasa, którą trzeba wpisać zamiast niej.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function MARTWE(): array
    {
        return [
            'lead' => ['lead', 'text-lead'],
        ];
    }

    /** @return list<\SplFileInfo> */
    private function widoki(): array
    {
        $katalog = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        $pliki = [];

        foreach ($katalog as $plik) {
            if ($plik instanceof \SplFileInfo && str_ends_with($plik->getFilename(), '.blade.php')) {
                $pliki[] = $plik;
            }
        }

        sort($pliki);

        return $pliki;
    }

    /**
     * @return list<string> klasy wypisane w atrybutach `class="…"` tego pliku
     */
    private function klasyZPliku(string $tresc): array
    {
        preg_match_all('/class="([^"]*)"/', $tresc, $trafienia);

        $klasy = [];

        foreach ($trafienia[1] as $atrybut) {
            foreach (preg_split('/\s+/', $atrybut, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $klasa) {
                $klasy[] = $klasa;
            }
        }

        return $klasy;
    }

    #[DataProvider('MARTWE')]
    public function test_klasa_z_listy_naprawde_nie_ma_reguly_w_arkuszach(string $martwa, string $zywa): void
    {
        $arkusze = glob(resource_path('css').'/*.css') ?: [];

        $this->assertNotSame([], $arkusze, 'Nie znalazłem arkuszy źródłowych — test nie ma czego sprawdzić.');

        foreach ($arkusze as $arkusz) {
            $tresc = (string) file_get_contents($arkusz);

            // `(?<![\w-])` odcina `text-lead` i `hero-lead`: myślnik NIE jest
            // granicą słowa w `\b`, więc `\.lead\b` łapałoby je oba i test
            // oblewałby na poprawnym kodzie. Zmierzone przy #581.
            $this->assertSame(
                0,
                preg_match('/(?<![\w-])\.'.preg_quote($martwa, '/').'(?![\w-])/', $tresc),
                basename($arkusz).": klasa „{$martwa}” ma tu regułę, więc nie jest już martwa — skreśl ją z listy zamiast zabraniać czegoś, co działa.",
            );
        }

        // Kontrola dodatnia: klasa zastępcza musi mieć z czego powstać,
        // inaczej ten test kazałby wpisywać drugą martwą klasę.
        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));
        $this->assertStringContainsString('--'.$zywa.':', $tokeny,
            "Token `--{$zywa}` nie stoi w `tokens.css`, więc Tailwind nie wygeneruje klasy „{$zywa}”.");
    }

    #[DataProvider('MARTWE')]
    public function test_zaden_widok_nie_uzywa_martwej_klasy(string $martwa, string $zywa): void
    {
        $winni = [];

        foreach ($this->widoki() as $plik) {
            $tresc = (string) file_get_contents($plik->getPathname());

            if (in_array($martwa, $this->klasyZPliku($tresc), true)) {
                $winni[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $plik->getPathname());
            }
        }

        $this->assertSame([], $winni, sprintf(
            'Klasa „%s” nie ma reguły w żadnym arkuszu — użyj „%s”. Widoki: %s',
            $martwa,
            $zywa,
            implode(', ', $winni),
        ));
    }

    /**
     * Kontrola dodatnia dla mechanizmu wyżej: gdyby czytanie atrybutów
     * `class` przestało działać, oba testy przechodziłyby na pustej liście
     * i nie pilnowały niczego. Ta klasa JEST w widokach i ma regułę —
     * musi się znaleźć.
     */
    public function test_czytanie_klas_z_widokow_w_ogole_dziala(): void
    {
        $znalezione = false;

        foreach ($this->widoki() as $plik) {
            if (in_array('text-lead', $this->klasyZPliku((string) file_get_contents($plik->getPathname())), true)) {
                $znalezione = true;
                break;
            }
        }

        $this->assertTrue($znalezione, 'Nie znalazłem klasy „text-lead” w żadnym widoku — odczyt atrybutów `class` nie działa.');
    }
}
