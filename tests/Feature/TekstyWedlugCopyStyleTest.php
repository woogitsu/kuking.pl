<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reguły z `docs/brand/COPY_STYLE.md`, których do issue #38 nie pilnowało nic.
 *
 * DLACZEGO TEN PLIK W OGÓLE ISTNIEJE
 * Przejście po ekranach z listą w ręku jest jednorazowe. Reguły, które to
 * przejście wyprostowało, są łatwe do złamania przy KAŻDEJ następnej zmianie
 * tekstu — i to złamanie nie objawia się niczym: strona działa, testy są
 * zielone, tylko głos serwisu przestaje być jeden. Cztery reguły poniżej
 * dają się sprawdzić maszynowo i dlatego są tu sprawdzane:
 *
 *   1. `kuKING` NIE MA w komunikacie błędu, w moderacji ani w tekście prawnym
 *      (§2, „Dawkowanie"). To jest twardy zakaz, nie preferencja: człowiek
 *      z problemem albo z decyzją moderacyjną w ręku nie jest w nastroju
 *      na żart o nazwie serwisu.
 *   2. Gra słowem NAJWYŻEJ RAZ NA EKRAN (§2). Dwa razy na jednej stronie
 *      zamieniają dowcip w nachalność.
 *   3. ZERO EMOJI w tekstach interfejsu (§4). Emoji w nawigacji zniknęły już
 *      wcześniej (`components/ikona.blade.php`) — ta reguła pilnuje, żeby
 *      nie wróciły bocznymi drzwiami, na przykład w komunikacie flash.
 *   4. Konstrukcja NIE ZAKŁADA RODZAJU ukośnikiem (§2, §7). „ugotowała/ugotował"
 *      oblewa pierwszy test dokumentu — tego nie da się przeczytać na głos.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE SPRAWDZA
 * Licznika społeczności w stopce („{n} kuKINGów", `App\Domain\Analytics\LiczbaKukingow`).
 * Stopka jest na KAŻDEJ stronie, więc gdyby liczyć ją jako „grę słowem na tym
 * ekranie", reguła „najwyżej raz" zabraniałaby licznika w ogóle — a §8 wymienia
 * go wprost jako jedno z trzech-czterech dozwolonych miejsc. Licznik jest
 * elementem obudowy strony, nie zdaniem komunikatu, i tak jest tu traktowany:
 * `bezStopki()` wycina go przed liczeniem. Gdyby właściciel zdecydował inaczej,
 * zmienia się `bezStopki()`, nie każdy widok z osobna.
 */
class TekstyWedlugCopyStyleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gra słowem „kuKING" ma w kodzie dokładnie dwie postacie: literał
     * z wersalikami w środku i komponent, który go renderuje.
     */
    private const ZAPISY_GRY_SLOWEM = ['kuKING', 'x-kuking-word', 'class="kuking-word"'];

    // ---------------------------------------------------------------
    // 1. kuKING poza błędem, moderacją i tekstem prawnym
    // ---------------------------------------------------------------

    /**
     * Teksty prawne — pliki źródłowe, nie tylko wyrenderowana strona.
     * Regulamin, polityka prywatności i zasady są w `resources/legal/*.md`
     * i to one są tekstem zobowiązania.
     */
    public function test_kuking_nie_wystepuje_w_tekstach_prawnych(): void
    {
        $pliki = glob(base_path('resources/legal/*.md')) ?: [];

        $this->assertNotEmpty($pliki, 'Nie znalazłem żadnego tekstu prawnego — sprawdź ścieżkę.');

        foreach ($pliki as $plik) {
            $tresc = (string) file_get_contents($plik);

            foreach (self::ZAPISY_GRY_SLOWEM as $zapis) {
                $this->assertStringNotContainsString(
                    $zapis,
                    $tresc,
                    basename($plik).' zawiera grę słowem „kuKING". '
                    .'docs/brand/COPY_STYLE.md §2 zabrania jej w regulaminie, polityce '
                    .'prywatności i zasadach — w tekście prawnym piszemy „Kuking".',
                );
            }
        }
    }

    /**
     * Wyrenderowane strony prawne — bo tekst prawny może dostać grę słowem
     * także z widoku, który go opakowuje, a nie tylko z pliku Markdown.
     */
    public function test_strony_prawne_nie_pokazuja_gry_slowem(): void
    {
        foreach (['rules', 'terms', 'privacy'] as $trasa) {
            $html = $this->bezStopki($this->get(route($trasa))->assertOk()->getContent());

            $this->assertStringNotContainsString(
                'kuking-word',
                $html,
                "Strona {$trasa} pokazuje grę słowem „kuKING” w treści. COPY_STYLE §2: "
                .'tekst prawny jest na poziomie „poważnym” i żartu nie dostaje.',
            );
        }
    }

    /**
     * Komunikaty i wiadomości składane w PHP — błędy walidacji, wyjątki
     * dla człowieka, powiadomienia, decyzje moderacyjne.
     *
     * Sprawdzamy SAME NAPISY, nie komentarze: `app/` jest pełne komentarzy,
     * które o tym zakazie piszą wprost („Bez gry słowem «kuKING» — D-009"),
     * i naiwny `grep` po pliku zapalałby się na nich. `token_get_all()`
     * oddziela jedno od drugiego pewnie, bez zgadywania regularnym wyrażeniem.
     */
    public function test_kuking_nie_wystepuje_w_napisach_skladanych_w_php(): void
    {
        $winowajcy = [];

        foreach ($this->plikiPhp(app_path()) as $plik) {
            foreach ($this->napisyZPliku($plik) as $numerLinii => $napis) {
                if (str_contains($napis, 'kuKING')) {
                    $winowajcy[] = $this->skrot($plik).':'.$numerLinii;
                }
            }
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Gra słowem „kuKING” trafiła do napisu w PHP: \n".implode("\n", $winowajcy)."\n"
            .'Kod PHP w tym repozytorium składa komunikaty błędów, wiadomości moderacyjne '
            .'i powiadomienia — czyli dokładnie te trzy miejsca, w których COPY_STYLE §2 '
            .'zabrania tej gry. Nazwę serwisu w takim tekście piszemy „Kuking".',
        );
    }

    /** Pliki tłumaczeń to w całości komunikaty systemowe i błędy walidacji. */
    public function test_kuking_nie_wystepuje_w_plikach_jezykowych(): void
    {
        $pliki = array_merge(
            glob(base_path('lang/*.json')) ?: [],
            glob(base_path('lang/pl/*.php')) ?: [],
        );

        $this->assertNotEmpty($pliki, 'Nie znalazłem plików językowych — sprawdź ścieżkę.');

        foreach ($pliki as $plik) {
            $this->assertStringNotContainsString(
                'kuKING',
                (string) file_get_contents($plik),
                basename($plik).' zawiera grę słowem „kuKING". Ten plik to same '
                .'komunikaty błędów i wiadomości systemowe — COPY_STYLE §2 zabrania jej tam.',
            );
        }
    }

    /**
     * Widoki, których REJESTR jest „poważny" (COPY_STYLE §3): strony błędu,
     * panel moderacji, odwołania, zgłoszenia. Żart z góry tu nie schodzi.
     */
    public function test_widoki_bledow_i_moderacji_nie_uzywaja_gry_slowem(): void
    {
        $katalogi = [
            resource_path('views/errors'),
            resource_path('views/pages/appeals'),
            resource_path('views/pages/zgloszenia'),
        ];

        $winowajcy = [];

        foreach ($katalogi as $katalog) {
            foreach ($this->plikiBlade($katalog) as $plik) {
                $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

                foreach (self::ZAPISY_GRY_SLOWEM as $zapis) {
                    if (str_contains($tresc, $zapis)) {
                        $winowajcy[] = $this->skrot($plik).' → '.$zapis;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Gra słowem „kuKING” na ekranie o rejestrze „poważnym”:\n".implode("\n", $winowajcy),
        );
    }

    /**
     * Rzecz, której żaden statyczny skan nie złapie: komunikat błędu
     * wyrenderowany naprawdę, razem z całym formularzem wokół niego.
     */
    public function test_komunikat_bledu_na_zywym_formularzu_nie_ma_gry_slowem(): void
    {
        // Za przekierowaniem, bo błędy widać dopiero na przerysowanym
        // formularzu — sama odpowiedź na POST to 302 bez treści.
        $html = $this->bezStopki(
            $this->followingRedirects()
                ->from(route('register'))
                ->post(route('register'), ['username' => 'nie ma takiej nazwy'])
                ->assertOk()
                ->getContent(),
        );

        $podsumowanie = $this->wytnij($html, 'error-summary', 'form');

        $this->assertNotSame('', $podsumowanie, 'Nie znalazłem podsumowania błędów na ekranie rejestracji.');
        $this->assertStringNotContainsString('kuking-word', $podsumowanie);
        $this->assertStringNotContainsString('kuKING', $podsumowanie);
    }

    // ---------------------------------------------------------------
    // 2. Najwyżej jedna gra słowem na ekran
    // ---------------------------------------------------------------

    public function test_gra_slowem_wystepuje_najwyzej_raz_na_ekranie(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $widz = $this->user('widz');

        $ekrany = [
            'strona powitalna' => fn () => $this->get('/'),
            'rejestracja' => fn () => $this->get(route('register')),
            'Świeżo z Kuking' => fn () => $this->get(route('discover')),
            'strona główna' => fn () => $this->actingAs($widz)->get(route('home')),
            'szukaj' => fn () => $this->actingAs($widz)->get(route('search')),
            'o Kuking' => fn () => $this->get(route('about')),
        ];

        foreach ($ekrany as $nazwa => $zadanie) {
            $html = $this->bezStopki($zadanie()->assertOk()->getContent());
            $ile = substr_count($html, 'class="kuking-word"');

            $this->assertLessThanOrEqual(
                1,
                $ile,
                "Ekran „{$nazwa}” używa gry słowem „kuKING” {$ile} razy. "
                .'docs/brand/COPY_STYLE.md §2: najwyżej raz na ekran — „dwa razy na jednej '
                .'stronie zamieniają dowcip w nachalność”.',
            );
        }
    }

    // ---------------------------------------------------------------
    // 3. Zero emoji
    // ---------------------------------------------------------------

    /**
     * `\p{Extended_Pictographic}` zamiast wypisanych zakresów: łapie emoji
     * i nie łapie strzałki „→", ptaszka „✓" ani półpauzy, których interfejs
     * używa świadomie i które emoji nie są.
     */
    public function test_interfejs_nie_uzywa_emoji(): void
    {
        $winowajcy = [];

        foreach ($this->plikiBlade(resource_path('views')) as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                if (preg_match('/\p{Extended_Pictographic}/u', $linia) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → '.trim($linia);
                }
            }
        }

        foreach ($this->plikiPhp(app_path()) as $plik) {
            foreach ($this->napisyZPliku($plik) as $numerLinii => $napis) {
                if (preg_match('/\p{Extended_Pictographic}/u', $napis) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.$numerLinii.' → '.trim($napis);
                }
            }
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Emoji w tekście interfejsu:\n".implode("\n", $winowajcy)."\n"
            .'docs/brand/COPY_STYLE.md §4: zero emoji w tekstach interfejsu. Emoji '
            .'w nawigacji zniknęły już wcześniej — patrz components/ikona.blade.php.',
        );
    }

    // ---------------------------------------------------------------
    // 4. Bez zakładania rodzaju ukośnikiem
    // ---------------------------------------------------------------

    public function test_interfejs_nie_zaklada_rodzaju_ukosnikiem(): void
    {
        // „ugotowała/ugotował", „napisała/napisał", „dostałeś/aś", „prosiłeś/aś".
        $wzor = '/\p{L}+(?:ła|łeś|ał|eś)\s*\/\s*\p{L}+/u';

        $winowajcy = [];

        foreach ($this->plikiBlade(resource_path('views')) as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                if (preg_match($wzor, $linia) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → '.trim($linia);
                }
            }
        }

        // Także napisy składane w PHP: połowa tych form siedziała nie
        // w widoku, tylko w komunikacie kontrolera i w wyjątku domenowym.
        foreach ($this->plikiPhp(app_path()) as $plik) {
            foreach ($this->napisyZPliku($plik) as $numerLinii => $napis) {
                if (preg_match($wzor, $napis) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.$numerLinii.' → '.trim($napis);
                }
            }
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Forma z ukośnikiem („ugotowała/ugotował”):\n".implode("\n", $winowajcy)."\n"
            .'docs/brand/COPY_STYLE.md §2: zamiast szukać żeńskiej formy, zmieniamy '
            .'konstrukcję zdania — imiesłów („ugotowane") albo czas teraźniejszy '
            .'(„zaczyna Cię obserwować"). Ukośnika nie da się przeczytać na głos.',
        );
    }

    // ---------------------------------------------------------------
    // 5. Cytat na stronie powitalnej mówi to, co mówi kod
    // ---------------------------------------------------------------

    /**
     * Strona powitalna cytuje powiadomienie „ktoś ugotował z Twojego przepisu"
     * i podpisuje ten cytat jako prawdziwe brzmienie z serwisu. Kopia i oryginał
     * mają się nie rozjechać — to jest obietnica wobec kogoś, kto konta jeszcze
     * nie ma i sprawdzić tego nie może.
     */
    public function test_cytat_na_stronie_powitalnej_zgadza_sie_z_powiadomieniem(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharka', ['display_name' => 'Halina']);

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół babci',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Notification::create([
            'user_id' => $autor->getKey(),
            'actor_id' => $kucharz->getKey(),
            'type' => Notification::TYPE_COOKED,
            'data' => ['recipe_title' => $przepis->title],
        ]);

        // Strona powitalna NAJPIERW, jako gość: `actingAs()` zostaje na kolejne
        // żądania w teście, a zalogowanego `/` przekierowuje na `/home`.
        $powitalna = $this->get('/')->assertOk()->getContent();
        $powiadomienia = $this->actingAs($autor)->get(route('notifications.index'))->assertOk()->getContent();

        $zdanie = '— ugotowane z Twojego przepisu';

        $this->assertStringContainsString($zdanie, $powiadomienia, 'Powiadomienie o ugotowaniu zmieniło brzmienie.');
        $this->assertStringContainsString(
            $zdanie,
            $powitalna,
            'Cytat na stronie powitalnej rozjechał się z powiadomieniem, które cytuje. '
            .'Zmieniając jedno, zmień drugie — patrz komentarz przy `blockquote` '
            .'w resources/views/pages/landing.blade.php.',
        );
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    /**
     * Stopka jest obudową strony, nie jej treścią — patrz komentarz klasy.
     */
    private function bezStopki(string $html): string
    {
        $start = strpos($html, '<footer class="site-footer">');

        if ($start === false) {
            return $html;
        }

        $koniec = strpos($html, '</footer>', $start);

        return $koniec === false
            ? substr($html, 0, $start)
            : substr($html, 0, $start).substr($html, $koniec + strlen('</footer>'));
    }

    /** Kawałek HTML od pierwszego wystąpienia znacznika do domykającego. */
    private function wytnij(string $html, string $od, string $doZnacznika): string
    {
        $start = strpos($html, $od);

        if ($start === false) {
            return '';
        }

        $koniec = strpos($html, '</'.$doZnacznika.'>', $start);

        return $koniec === false ? substr($html, $start) : substr($html, $start, $koniec - $start);
    }

    private function bezKomentarzyBlade(string $tresc): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc);
    }

    /**
     * Napisy z pliku PHP, bez komentarzy — patrz uzasadnienie przy teście,
     * który tego używa.
     *
     * @return array<int, string> numer linii => napis
     */
    private function napisyZPliku(string $plik): array
    {
        $napisy = [];

        foreach (token_get_all((string) file_get_contents($plik)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $napisy[$token[2]] = $token[1];
            }
        }

        return $napisy;
    }

    /**
     * Pliki PHP produktu, BEZ `app/Console`.
     *
     * Komendy artisan piszą do terminala właściciela, nie na ekran
     * użytkownika: opis `kuking:policz-kukingow` mówi wprost, że przelicza
     * „N kuKINGów" w stopce, i tak ma być — to jest zdanie o funkcji, nie
     * komunikat dla człowieka, który właśnie ma problem. Zakazy z §2 dotyczą
     * błędu, moderacji i tekstu prawnego, a te powstają w kontrolerach,
     * regułach, akcjach domenowych i powiadomieniach — czyli w tym, co tu
     * zostaje.
     *
     * @return list<string>
     */
    private function plikiPhp(string $katalog): array
    {
        return array_values(array_filter(
            $this->pliki($katalog, '.php'),
            fn (string $plik): bool => ! str_starts_with($plik, app_path('Console').'/'),
        ));
    }

    /** @return list<string> */
    private function plikiBlade(string $katalog): array
    {
        return $this->pliki($katalog, '.blade.php');
    }

    /** @return list<string> */
    private function pliki(string $katalog, string $rozszerzenie): array
    {
        if (! is_dir($katalog)) {
            return [];
        }

        $znalezione = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog));

        foreach ($iterator as $plik) {
            if ($plik->isFile() && str_ends_with($plik->getFilename(), $rozszerzenie)) {
                $znalezione[] = $plik->getPathname();
            }
        }

        sort($znalezione);

        return $znalezione;
    }

    private function skrot(string $sciezka): string
    {
        return str_replace(base_path().'/', '', $sciezka);
    }
}
