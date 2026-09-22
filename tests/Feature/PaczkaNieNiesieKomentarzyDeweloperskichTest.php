<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;

/**
 * Paczka z danymi nie niesie komentarzy deweloperskich (#492).
 *
 * SKĄD SIĘ WZIĘŁA TA USTERKA — I DLACZEGO ŻADEN TEST JEJ NIE WIDZIAŁ
 *
 * Uzasadnienia reguł CSS przeniesiono z komentarzy `/* *\/` do komentarza
 * Blade właśnie po to, żeby NIE jechały do paczki: arkusz idzie w całości
 * do każdego pliku HTML archiwum, więc każdy akapit uzasadnienia mnożył się
 * przez liczbę plików. Jedno zdanie tego komentarza cytowało jednak znacznik
 * ZAMYKAJĄCY komentarz Blade — a Blade nie odróżnia cytatu od znacznika
 * i kończył komentarz w tym miejscu.
 *
 * Skutek był dokładnie odwrotny od zamierzonego: cała reszta uzasadnień
 * — cytat z `AGENTS.md`, numer wytycznej WCAG, selektor CSS w prozie
 * i ścieżka do dowodów — wychodziła w archiwum jako WIDOCZNY TEKST STRONY,
 * nad nagłówkiem „Twoje dane z Kuking”. Zmierzone na paczkach zbudowanych
 * normalną drogą: `index.html` pustego konta miał 8660 bajtów przed poprawką
 * i ma 7000 po niej; sam wyciekły fragment to 1605 bajtów w KAŻDYM pliku
 * HTML paczki.
 *
 * Człowiek dostaje ten plik przed skasowaniem konta. Pierwsze, co widział,
 * to zdanie o `p > a:only-child`.
 *
 * Testy tego nie łapały, bo wszystkie pytały o OBECNOŚĆ zdań, a nie
 * o nieobecność cudzych. Ten pyta w drugą stronę.
 */
class PaczkaNieNiesieKomentarzyDeweloperskichTest extends EksportWygladStylPaczki
{
    /**
     * Ślady, które w pliku dla człowieka nie mają czego szukać.
     *
     * Każdy z osobna, nie jeden wspólny wzorzec: przy jednej asercji
     * zniknięcie połowy komentarza chowałoby się za drugą (pułapka 3b).
     * Kotwice są dobrane tak, żeby NIE mogły paść w treści pisanej dla
     * czytelnika paczki — to nazwy z warsztatu, nie z kuchni.
     */
    private const SLADY_WARSZTATU = [
        'niedomknięty komentarz Blade' => '--}}',
        'instrukcja dla agentów' => 'AGENTS.md',
        'selektor CSS w prozie' => ':only-child',
        'numer wytycznej WCAG w prozie' => 'WCAG 2.2 AA 2.5.8',
        'ścieżka do dowodów w repozytorium' => 'docs/design/evidence',
    ];

    /*
     * DLACZEGO NIE `PULAPKI_TESTOW` — I DLACZEGO NIE `break-inside`.
     *
     * Pierwsza wersja tej listy miała kotwicę `PULAPKI_TESTOW`. Sprawdzone
     * na wycieku z `8537e42`: tej nazwy w wyciekłym fragmencie NIE BYŁO —
     * zdanie o pułapkach stoi w arkuszu PRZED zepsutym znacznikiem, więc
     * nigdy nie wyszło. Asercja na nią nie oblałaby się ani przed poprawką,
     * ani po niej: pusta kotwica, która tylko wygląda na dowód (pułapka 4).
     *
     * `break-inside` i `max-height: 16cm` odpadają z odwrotnego powodu —
     * to prawdziwy CSS, który w `<style>` paczki MA stać. Kotwica trafiłaby
     * w arkusz i oblewałaby się zawsze.
     *
     * Zostają łańcuchy, które wyciekły naprawdę i mogą paść wyłącznie
     * w prozie dla programisty.
     */

    public function test_zaden_plik_paczki_nie_niesie_komentarza_dla_programisty(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        Recipe::factory()->for($basia, 'author')->create([
            'title' => 'Rosół z kury',
            'hero_media_id' => $this->zdjecieDla($basia, 'rosol')->getKey(),
        ]);

        $export = $this->zbudujPaczke($basia);

        $html = array_values(array_filter(
            $this->plikiPaczki($export),
            static fn (string $plik): bool => str_ends_with($plik, '.html'),
        ));

        // Pułapka 2: skan, który nie znajduje ŻADNEGO pliku, jest dla testu
        // sukcesem. Paczka tego konta ma spis treści, wpisy i przepis.
        $this->assertGreaterThanOrEqual(3, count($html),
            'Paczka nie ma plików HTML — skan nie miałby czego sprawdzić.');

        foreach ($html as $plik) {
            $tresc = $this->zPaczki($export, $plik);

            foreach (self::SLADY_WARSZTATU as $co => $slad) {
                $this->assertStringNotContainsString(
                    $slad,
                    $tresc,
                    "Plik {$plik} niesie do człowieka {$co} („{$slad}”).",
                );
            }
        }
    }

    public function test_arkusz_stylow_w_paczce_dalej_dziala(): void
    {
        /*
         * Kontrola dodatnia (pułapka 4): asercje wyżej przeszłyby również
         * wtedy, gdyby z paczki wyleciał CAŁY arkusz — a wtedy strona
         * wygląda jak surowy tekst. Reguły mają zostać; wychodzi wyłącznie
         * proza o nich.
         */
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $export = $this->zbudujPaczke($basia);

        $arkusz = $this->arkusz($this->zPaczki($export, 'index.html'));

        $this->assertStringContainsString('min-height: 48px', $arkusz);
        $this->assertStringContainsString('@media print', $arkusz);
    }
}
