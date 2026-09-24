<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Pagination\Paginator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Ekrany użytkownika stronicują przyciskiem „Pokaż więcej”, nie numerami.
 *
 * PO CO TO JEST
 * AGENTS.md §5 mówi wprost: „Paginacja to przycisk «Pokaż więcej», nie
 * infinite scroll". Reguła powstała dla grupy 50+ i ma konkretny powód:
 * numerowane odnośniki Laravela to rząd małych celów dotykowych obok siebie,
 * często poniżej 48 px, a przy skali tekstu 150% zawijają się w plątaninę
 * cyfr. Jeden szeroki przycisk z pełnym zdaniem jest tu jedyną sensowną
 * odpowiedzią.
 *
 * Mimo to `->links()` siedziało na profilu (trzy zakładki), w powiadomieniach,
 * w zeszycie, na stronie tematu i na liście obserwujących — czyli na
 * większości ekranów, na których człowiek w ogóle dochodzi do końca listy.
 * Reguła istniała w AGENTS.md i w komponencie, a widoki robiły co innego:
 * ten sam kształt błędu, co reszta usterek w tym repozytorium.
 *
 * DLACZEGO STRAŻNIK, A NIE „PRZECIEŻ POPRAWILIŚMY"
 * `{{ $x->links() }}` to najkrótsza droga do stronicowania w Laravelu i
 * pierwsza rzecz, którą podpowie każdy przykład z dokumentacji. Bez tego
 * testu wróci przy pierwszym nowym ekranie z listą.
 *
 * PANEL MODERACJI JEST WYŁĄCZONY I NIE JEST TO WYJĄTEK „NA SKRÓTY”
 * `pages/admin/**` ogląda jedna albo dwie osoby (D-012), które przeglądają
 * KOLEJKĘ: chcą wiedzieć, ile stron zostało, i wracać do konkretnej. Numery
 * robią tam robotę, której „Pokaż więcej" nie zrobi. To jest inny odbiorca
 * i inne zadanie niż czytanie cudzego profilu. Gdyby właściciel zdecydował
 * inaczej, wystarczy usunąć ten katalog z listy niżej.
 */
class PaginacjaToPokazWiecejTest extends TestCase
{
    /** Katalogi widoków wyłączone spod reguły, wraz z powodem. */
    private const WYLACZONE = [
        'pages/admin' => 'kolejka moderacji — patrz opis klasy',
    ];

    public function test_zaden_widok_uzytkownika_nie_stronicuje_numerami(): void
    {
        $winne = [];
        $przejrzane = 0;

        foreach ($this->widoki() as $sciezka) {
            $przejrzane++;

            $wzgledna = str_replace(base_path('resources/views').'/', '', $sciezka);

            foreach (array_keys(self::WYLACZONE) as $wyjatek) {
                if (str_starts_with($wzgledna, $wyjatek.'/')) {
                    continue 2;
                }
            }

            $tresc = (string) file_get_contents($sciezka);

            // Komentarze Blade wycinamy PRZED szukaniem: gdyby ktoś opisał
            // w komentarzu, czego tu nie wolno, asercja trafiłaby w to
            // wyjaśnienie zamiast w kod.
            $bezKomentarzy = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc);

            if (preg_match('/->links\(/', $bezKomentarzy) === 1) {
                $winne[] = $wzgledna;
            }
        }

        // ASERCJA KONTROLNA, BEZ KTÓREJ CAŁY TEN TEST JEST PUSTĄ PĘTLĄ.
        //
        // `$winne` zostaje tablicą pustą także wtedy, gdy nie przejrzeliśmy
        // ANI JEDNEGO widoku — a to nie jest przypadek teoretyczny: wystarczy,
        // że katalog widoków się przeniesie albo zmieni się rozszerzenie
        // szablonów. Zmierzone: po podmianie skanowanego katalogu na taki,
        // w którym nie ma żadnego `.blade.php`, test był dalej zielony.
        // Ten sam strażnik stoi już w `ObiecujemyTylkoFormatyKtoreUmiemyTest`.
        $this->assertGreaterThan(
            50,
            $przejrzane,
            'Nie przejrzałem widoków (znalazłem '.$przejrzane.'). Ten test nie sprawdza wtedy niczego.',
        );

        $this->assertSame(
            [],
            $winne,
            "Te widoki stronicują numerami zamiast przyciskiem „Pokaż więcej”:\n  "
            .implode("\n  ", $winne)
            ."\nAGENTS.md §5 wymaga przycisku. Użyj `<x-show-more :paginator=\"\$x\" "
            .'czego="przepisów" />` — parametr `czego` dobierz do tego, co jest na '
            .'liście, bo domyślne „wpisów” pod listą osób byłoby nieprawdą.',
        );
    }

    public function test_komponent_pokaz_wiecej_bez_skryptu_jest_odnosnikiem_do_nastepnej_strony(): void
    {
        // Renderujemy komponent, zamiast czytać jego źródło: liczy się to,
        // co dostaje przeglądarka bez skryptu. Zwykły odnośnik z adresem
        // następnej strony i etykietą, która mówi, że to NASTĘPNA STRONA —
        // bo dokładanie porcji do listy robi dopiero `pokaz-wiecej.js`
        // (#986). Bez skryptu, przy słabym zasięgu, ekran nadal działa.
        $paginator = new Paginator(range(1, 3), 2, 1, ['path' => '/lista']);
        $html = (string) $this->blade(
            '<x-show-more :paginator="$p" czego="przepisów" lista="lista-przepisow" />',
            ['p' => $paginator],
        );

        $this->assertMatchesRegularExpression(
            '#<a class="btn btn-secondary" href="/lista\?page=2">Następna strona przepisów</a>#u',
            $html,
        );
        $this->assertStringNotContainsString('<button', $html, 'Przycisk bez skryptu byłby martwy (D-053).');
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('x-on:', $html);
        $this->assertStringNotContainsString('wire:', $html);
    }

    /** @return iterable<string> */
    private function widoki(): iterable
    {
        $katalog = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('resources/views')),
        );

        foreach ($katalog as $plik) {
            if ($plik->isFile() && str_ends_with($plik->getFilename(), '.blade.php')) {
                yield $plik->getPathname();
            }
        }
    }
}
