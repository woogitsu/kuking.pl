<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `composer.json` NIE PRZEDSTAWIA PROJEKTU JAKO CZEGOŚ, CZYM NIE JEST —
 * i nie deklaruje wsparcia dla PHP, którego nikt nie testuje (D-074).
 *
 * CO BYŁO ZMIERZONE 10 WRZEŚNIA 2026
 * Manifest niósł trzy nieprawdy naraz:
 *
 *     "name": "laravel/laravel",
 *     "description": "The skeleton application for the Laravel framework.",
 *     "php": "^8.3",
 *
 * Dwie pierwsze to metadane szkieletu Laravela, nigdy niezmienione po
 * `composer create-project`. Trafiają do narzędzi zależności, SBOM-ów
 * i skanerów, i mówią następnej osobie (albo agentowi), że czyta pusty
 * szkielet frameworka, a nie Kuking. Trzecia była szersza niż prawda: CI
 * puszcza jedną wersję PHP (`env.PHP_VERSION`), obraz produkcyjny jest
 * przypięty do `dunglas/frankenphp:1-php8.4-trixie`, a 8.3 nie było
 * uruchamiane ani razu.
 *
 * DLACZEGO TO JEST TEST, A NIE JEDNORAZOWA POPRAWKA
 * Bo `require.php` jest OBIETNICĄ, a obietnica ma być prawdziwa nie tylko
 * w dniu, w którym się ją poprawia. Ten plik pilnuje jednej własności, którą
 * da się sprawdzić maszynowo: **zadeklarowana wersja PHP zgadza się z tą,
 * na której projekt jest naprawdę uruchamiany.** Rozjazd może pójść w obie
 * strony i obie są błędem:
 *
 *  - ktoś rozszerza deklarację (`^8.3`, `>=8.2`), nie dokładając wersji do
 *    CI → obiecujemy zgodność, której nikt nie sprawdza;
 *  - ktoś podnosi obraz i CI do 8.5, nie ruszając manifestu → Composer
 *    rozwiązuje zależności pod inną wersję niż ta, na której to działa.
 *
 * KOLEJNOŚĆ, KTÓREJ TEN TEST PILNUJE (D-074): najpierw obraz i CI, potem
 * manifest. Nigdy odwrotnie.
 */
class ManifestProjektuMowiPrawdeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        $tresc = file_get_contents(base_path('composer.json'));
        $this->assertIsString($tresc, 'Nie da się wczytać composer.json.');

        $dane = json_decode((string) $tresc, true);
        $this->assertIsArray($dane, 'composer.json nie jest poprawnym JSON-em.');

        return $dane;
    }

    /** @return array<string, mixed> */
    private function composerLock(): array
    {
        $tresc = file_get_contents(base_path('composer.lock'));
        $this->assertIsString($tresc, 'Nie da się wczytać composer.lock.');

        $dane = json_decode((string) $tresc, true);
        $this->assertIsArray($dane, 'composer.lock nie jest poprawnym JSON-em.');

        return $dane;
    }

    private function plik(string $sciezka): string
    {
        $tresc = file_get_contents(base_path($sciezka));
        $this->assertIsString($tresc, "Nie da się wczytać {$sciezka}.");

        return (string) $tresc;
    }

    /**
     * Wersja PHP, na której projekt jest NAPRAWDĘ uruchamiany — odczytana
     * z dwóch niezależnych miejsc, które muszą się zgadzać ze sobą.
     *
     * To jest kontrola metody pomiaru wpisana w samą metodę: gdyby CI i obraz
     * produkcyjny mówiły dwie różne wersje, żadne porównanie z manifestem nie
     * miałoby sensu i trzeba o tym powiedzieć wprost, zamiast wybrać jedną
     * z nich po cichu.
     */
    private function wersjaPhpZKoduWykonawczego(): string
    {
        $ci = $this->plik('.github/workflows/ci.yml');

        $this->assertSame(
            1,
            preg_match('/^\s*PHP_VERSION:\s*"([0-9]+\.[0-9]+)"/m', $ci, $zCi),
            'Nie znaleziono `PHP_VERSION` w `.github/workflows/ci.yml`. Jeśli CI '
            .'przeszło na macierz wersji, ten test wymaga przemyślenia razem '
            .'z D-074 — deklaracja w `composer.json` może się wtedy poszerzyć.',
        );

        $dockerfile = $this->plik('Dockerfile');

        $this->assertSame(
            2,
            preg_match_all('/frankenphp:1-php([0-9]+\.[0-9]+)-/', $dockerfile, $zObrazu),
            'Oczekiwane DWA etapy obrazu (`vendor` i `runtime`) przypięte do tej '
            .'samej wersji PHP w `Dockerfile`.',
        );

        $wersjeObrazu = array_unique($zObrazu[1]);

        $this->assertCount(
            1,
            $wersjeObrazu,
            'Etapy obrazu produkcyjnego są przypięte do RÓŻNYCH wersji PHP: '
            .implode(', ', $wersjeObrazu).'. Vendor zbudowany na innej wersji '
            .'niż runtime to najtrudniejszy do znalezienia rodzaj różnicy '
            .'między buildem a produkcją.',
        );

        $this->assertSame(
            $wersjeObrazu[0],
            $zCi[1],
            'CI testuje PHP '.$zCi[1].', a obraz produkcyjny stoi na '
            .$wersjeObrazu[0].'. Zielone CI nie mówi wtedy nic o produkcji.',
        );

        return $zCi[1];
    }

    /**
     * WŁAŚCIWY POMIAR. Deklaracja z manifestu zgadza się z tym, na czym to
     * naprawdę chodzi.
     */
    public function test_deklarowana_wersja_php_zgadza_sie_z_ci_i_obrazem(): void
    {
        $wersja = $this->wersjaPhpZKoduWykonawczego();

        $this->assertSame(
            '^'.$wersja,
            $this->composerJson()['require']['php'] ?? null,
            'composer.json deklaruje inną wersję PHP niż ta, na której projekt '
            .'jest budowany i testowany ('.$wersja.'). D-074: najpierw obraz '
            .'i CI, potem manifest — nigdy odwrotnie.',
        );
    }

    /**
     * `composer.lock` niesie WŁASNĄ kopię wymagania platformy i własny
     * `content-hash` z manifestu. Zmiana w `composer.json` bez odświeżenia
     * locka (`composer update --lock`) wywala `composer install` w CI
     * i w buildzie obrazu na „lock file is not up to date" — czyli błędem
     * nie jest wtedy kod, tylko niedokończona zmiana metadanych.
     */
    public function test_lock_niesie_te_sama_wersje_php_co_manifest(): void
    {
        $lock = $this->composerLock();

        $this->assertSame(
            $this->composerJson()['require']['php'] ?? null,
            $lock['platform']['php'] ?? null,
            '`composer.lock` pamięta inną wersję PHP niż `composer.json`. '
            .'Uruchom `composer update --lock` i dołóż lock do tej samej '
            .'zmiany — inaczej `composer install` padnie w CI, a nie u Ciebie.',
        );

        // KONTROLA: lock naprawdę ma czym się rozjechać. Puste `platform`
        // przeszłoby porównanie wyżej tylko wtedy, gdyby i manifest był
        // pusty — ale wtedy test miałby milczeć, a nie świecić zielono.
        $this->assertNotEmpty(
            $lock['platform']['php'] ?? null,
            'Kontrola: `composer.lock` nie ma zapisanego wymagania platformy.',
        );
    }

    /**
     * Manifest opisuje Kuking, a nie szkielet Laravela.
     *
     * Opis jest zgodny z `AGENTS.md`: Kuking to **społeczność ludzi, którzy
     * gotują**, nie baza ani „platforma do przepisów". Dlatego test pilnuje
     * także tego, czego w opisie BYĆ NIE MA — bo najłatwiejszą pomyłką przy
     * poprawianiu tej linijki jest napisanie „platforma z przepisami
     * kulinarnymi", czyli zamiana jednej nieprawdy na drugą.
     */
    public function test_nazwa_i_opis_nie_sa_metadanymi_szkieletu_laravela(): void
    {
        $manifest = $this->composerJson();

        $this->assertSame('woogitsu/kuking', $manifest['name'] ?? null);

        $opis = (string) ($manifest['description'] ?? '');

        $this->assertStringContainsString('Kuking', $opis);
        $this->assertStringContainsString('gotuj', $opis, 'Opis nie mówi, że to miejsce dla ludzi, którzy gotują.');

        foreach (['skeleton', 'Laravel framework', 'przepis'] as $zakazane) {
            $this->assertStringNotContainsString(
                $zakazane,
                $opis,
                "Opis projektu zawiera „{$zakazane}”. Kuking to społeczność ludzi, "
                .'którzy gotują (AGENTS.md) — nie szkielet frameworka i nie baza '
                .'przepisów.',
            );
        }
    }
}
