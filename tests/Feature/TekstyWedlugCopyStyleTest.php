<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Reguły z `docs/brand/COPY_STYLE.md`, których do issue #38 nie pilnowało nic.
 *
 * DLACZEGO TEN PLIK W OGÓLE ISTNIEJE
 * Przejście po ekranach z listą w ręku jest jednorazowe. Reguły, które to
 * przejście wyprostowało, są łatwe do złamania przy KAŻDEJ następnej zmianie
 * tekstu — i to złamanie nie objawia się niczym: strona działa, testy są
 * zielone, tylko głos serwisu przestaje być jeden. Pięć reguł poniżej
 * daje się sprawdzić maszynowo i dlatego są tu sprawdzane:
 *
 *   1. `kuKING` NIE MA w komunikacie błędu, w moderacji ani w tekście prawnym
 *      (§2, „Dawkowanie"). To jest twardy zakaz, nie preferencja: człowiek
 *      z problemem albo z decyzją moderacyjną w ręku nie jest w nastroju
 *      na żart o nazwie serwisu.
 *   2. Nazwa NIE POWTARZA SIĘ W JEDNYM BLOKU TEKSTU (`GLOS_MARKI.md` §4).
 *      Do 11 września 2026 stała tu reguła „najwyżej raz na ekran" i była
 *      pilnowana testem. Właściciel ją odwrócił: dwukolorowy `kuKING` ma się
 *      pojawiać wszędzie, także jako nazwa serwisu w tekście bieżącym. Sufitu
 *      na ekran więc NIE MA — jest kryterium („nie konkuruje z zadaniem")
 *      i jedna jego część, którą da się sprawdzić maszynowo: dwa razy
 *      w JEDNYM akapicie, nagłówku albo punkcie listy. Tam dwukolorowy zapis
 *      zaczyna migotać w polu jednego spojrzenia i to jest już koszt
 *      czytania, nie charakter marki. Pomiar po wdrożeniu: najgęstsze ekrany
 *      (`/` i `/o-kuking`) mają 6 wystąpień na stronę, z czego 2 w stopce,
 *      i ANI JEDNEGO bloku z dwoma — czyli reguła opisuje stan, nie życzenie.
 *   3. ZERO EMOJI w tekstach interfejsu (§4). Emoji w nawigacji zniknęły już
 *      wcześniej (`components/ikona.blade.php`) — ta reguła pilnuje, żeby
 *      nie wróciły bocznymi drzwiami, na przykład w komunikacie flash.
 *   4. Konstrukcja NIE ZAKŁADA RODZAJU ukośnikiem (§2, §7). „ugotowała/ugotował"
 *      oblewa pierwszy test dokumentu — tego nie da się przeczytać na głos.
 *   5. INSTRUKCJA WSKAZUJE ELEMENT NAZWĄ, NIE KOLOREM. „Kliknij zielony
 *      przycisk" nie jest kwestią stylu, tylko WCAG 2.2 AA (1.4.1), do
 *      którego `AGENTS.md` §5 zobowiązuje się wprost — a w liście dochodzi
 *      klient pocztowy, który tło przycisku przemaluje po swojemu.
 *   6. CZERWIEŃ, KTÓRĄ PISZEMY „KING", MA KONTRAST 4,5:1 na każdym tle,
 *      na którym to słowo stoi, w obu motywach (WCAG 1.4.3). To jest tekst,
 *      nie dekoracja: od chwili, w której nazwa weszła do tekstu bieżącego,
 *      połowa wyrazu jest pisana kolorem i musi być czytelna.
 *   7. NAZWA NIE TRAFIA TAM, GDZIE KOLORU NIE MA — `alt`, `title`,
 *      `aria-label`, `<title>`, `meta`, temat listu, pliki eksportu.
 *      Znacznik by tam przeszkadzał, a dwukolorowość i tak nie istnieje.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE SPRAWDZA
 * Liczby wystąpień nazwy na ekran. Sufit „najwyżej raz na ekran" był tu
 * pilnowany do 11 września 2026 i został zdjęty razem z regułą, nie obok niej
 * (patrz punkt 2 wyżej i `docs/brand/GLOS_MARKI.md` §4). Nie ma go w żadnej
 * innej formie — także nie jako wyższy sufit, bo liczba nie była tym, co ta
 * reguła chroniła.
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
    // 2. Nazwa nie powtarza się w JEDNYM bloku tekstu
    // ---------------------------------------------------------------

    /**
     * TEN TEST ZASTĄPIŁ `test_gra_slowem_wystepuje_najwyzej_raz_na_ekranie`.
     *
     * Tamten pilnował sufitu „najwyżej raz na ekran" z `AGENTS.md` §11
     * i `COPY_STYLE.md` §2. Właściciel ten sufit zdjął (11 września 2026):
     * dwukolorowy `kuKING` ma stać wszędzie, także jako nazwa serwisu
     * w tekście bieżącym. Test nie został więc wyłączony ani podbity na
     * wyższą liczbę — bo liczba nie była tym, co tamta reguła chroniła.
     * Chroniła CZYTANIA, i dokładnie ta część zostaje tutaj:
     *
     *   dwa dwukolorowe słowa w JEDNYM akapicie, nagłówku albo punkcie listy
     *   migoczą w polu jednego spojrzenia — a to jest koszt czytania,
     *   nie charakter marki.
     *
     * Zmierzone po wdrożeniu B2 (stan z 11 września 2026): najgęstsze ekrany
     * `/` i `/o-kuking` mają 6 wystąpień na stronę (2 z nich w stopce:
     * hasło i licznik), `/odkryj` 4, `/pomoc` i `/tagi` po 3. W żadnym
     * akapicie, nagłówku ani punkcie listy nie ma dwóch. Reguła opisuje więc
     * stan faktyczny, a nie życzenie — i dlatego wolno jej pilnować testem.
     *
     * Liczymy bloki TEKSTU, nie cały dokument: `<p>`, `<li>`, `<h1>`–`<h3>`
     * i `<blockquote>`. Zagnieżdżenia nie ma czego liczyć podwójnie, bo wzór
     * jest niezachłanny i bierze najbliższy domykający znacznik tego samego
     * rodzaju.
     */
    public function test_nazwa_nie_powtarza_sie_w_jednym_bloku_tekstu(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $widz = $this->user('widz');

        $ekrany = [
            'strona powitalna' => fn () => $this->get('/'),
            'rejestracja' => fn () => $this->get(route('register')),
            'Świeżo z kuKING' => fn () => $this->get(route('discover')),
            'pomoc' => fn () => $this->get(route('help')),
            'tematy' => fn () => $this->get(route('tags.index')),
            'strona główna' => fn () => $this->actingAs($widz)->get(route('home')),
            'szukaj' => fn () => $this->actingAs($widz)->get(route('search')),
            'o kuKING' => fn () => $this->get(route('about')),
        ];

        foreach ($ekrany as $nazwa => $zadanie) {
            $html = (string) $zadanie()->assertOk()->getContent();

            // KONTROLA POMIARU: ekran, na którym nazwy nie ma ani raz w samej
            // TREŚCI, nie dowodzi niczego — a łatwo taki zrobić, psując
            // selektor albo wypisując nazwę zwykłym tekstem.
            //
            // Liczymy BEZ STOPKI i to jest tu wszystko. Stopka ma nazwę na
            // każdej stronie (hasło + licznik), więc kontrola licząca cały
            // dokument nigdy nie spadnie do zera i przepuści ekran, z którego
            // nazwa zniknęła co do jednego wystąpienia. Sprawdzone: przy
            // liczeniu całego dokumentu ta kontrola przechodziła na stronie
            // `/o-kuking` z wszystkimi czterema wystąpieniami zamienionymi
            // z powrotem na napis „Kuking".
            $this->assertGreaterThan(
                0,
                substr_count($this->bezStopki($html), 'class="kuking-word"'),
                "Ekran „{$nazwa}” nie pokazuje nazwy ani razu poza stopką. Jeśli tak ma być, "
                .'wyjmij go z listy w tym teście — inaczej pętla niżej sprawdza nic.',
            );

            preg_match_all('~<(p|li|h1|h2|h3|blockquote)\b[^>]*>(.*?)</\1>~su', $html, $bloki, PREG_SET_ORDER);

            foreach ($bloki as $blok) {
                $ile = substr_count($blok[2], 'class="kuking-word"');

                if ($ile < 2) {
                    continue;
                }

                $tresc = trim((string) preg_replace('~\s+~', ' ', strip_tags($blok[2])));

                $this->fail(
                    "Ekran „{$nazwa}”: nazwa stoi {$ile} razy w jednym bloku <{$blok[1]}>:\n  "
                    .mb_substr($tresc, 0, 160)."\n"
                    .'docs/brand/GLOS_MARKI.md §4: w jednym akapicie, nagłówku albo punkcie '
                    .'listy nazwa pojawia się raz. Sufitu NA EKRAN nie ma — rozdziel te dwa '
                    .'wystąpienia na dwa zdania albo w drugim napisz „tu”, „u nas”, „serwis”.',
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // 2b. Napis przycisku na stronie powitalnej (decyzja właściciela, B1)
    // ---------------------------------------------------------------

    /**
     * Napis brzmi „Zostań kuKINGiem — bez opłat i bez reklam" i jest JEDNYM
     * elementem.
     *
     * Dwie rzeczy naraz, bo obie już raz się zepsuły:
     *
     *   1. BRZMIENIE. „to darmowe" brzmiało sprzedażowo, „za darmo, na
     *      zawsze" obiecywałoby przyszłość bez gwarancji. Wybrane brzmienie
     *      jest decyzją właściciela i ma pokrycie: „bez reklam" to
     *      zobowiązanie, nie chwyt (`docs/brand/GLOS_MARKI.md` §6).
     *   2. JEDEN ELEMENT (D-131, issue #353). `.btn` jest `inline-flex`, więc
     *      każdy kawałek tekstu między elementami inline jest osobnym
     *      elementem flex, a `overflow-wrap: anywhere` łamie każdy z nich
     *      w ŚRODKU WYRAZU. Napis rozpadał się na pięć kawałków na telefonie.
     *      Mechanizmu pilnuje `PrzyciskiNieRozbijajaNapisuTest` (liczy węzły
     *      tekstowe w każdym `.btn`); tutaj sprawdzamy, że napis z tej
     *      decyzji NADAL siedzi w `btn-napis`, bo to on jest najdłuższy
     *      w serwisie i on pierwszy się rozsypie.
     */
    public function test_napis_przycisku_powitalnego_brzmi_jak_w_decyzji_i_jest_jednym_elementem(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match(
                '~<a class="btn btn-primary btn-duzy"[^>]*>\s*<span class="btn-napis">(.*?)</span>\s*</a>~su',
                $html,
                $trafienie,
            ),
            'Na stronie powitalnej nie ma przycisku podstawowego z napisem owiniętym '
            .'w `<span class="btn-napis">`. Bez tego opakowania napis łamie się w środku '
            .'wyrazu na telefonie — D-131.',
        );

        $napis = trim((string) preg_replace('~\s+~', ' ', strip_tags($trafienie[1])));

        // Wersja dla czytnika ekranu stoi obok wizualnej, więc w samym
        // tekście nazwa jest dwa razy pod rząd — to jest poprawne i celowe.
        $this->assertSame('Zostań kuKINGiem kukingiem — bez opłat i bez reklam', $napis);

        $this->assertStringContainsString(
            '<span class="btn-napis">Zostań <span class="kuking-word">',
            $html,
            'Nazwa w napisie przycisku przestała być komponentem — dwukolorowy zapis '
            .'zniknął, a razem z nim wersja dla czytnika ekranu.',
        );
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
    // 5. Instrukcja wskazuje element nazwą, nie kolorem
    // ---------------------------------------------------------------

    /**
     * „Kliknij zielony przycisk" i „popraw to, co jest zaznaczone na czerwono"
     * to ta sama usterka: jedyną drogą do elementu jest jego kolor.
     *
     * To nie jest kwestia gustu, tylko WCAG 2.2 AA — kryterium 1.4.1 („Użycie
     * koloru"), do którego `AGENTS.md` §5 zobowiązuje się wprost. Dla tej grupy
     * wiekowej jest to na dodatek dotkliwsze niż średnio: zaćma żółci obraz,
     * a wada rozróżniania barw dotyczy około ośmiu procent mężczyzn.
     *
     * W wiadomościach e-mail dochodzi druga przyczyna, całkiem techniczna:
     * kolor tła przycisku niesie `bgcolor` i styl w linii, a klient pocztowy
     * wolno mu nie posłuchać — Outlook w trybie ciemnym przemalowuje tła sam
     * z siebie. Zdanie „kliknij zielony przycisk" opisuje wtedy coś, czego
     * na ekranie nie ma.
     *
     * NAPRAWA JEST ZAWSZE TA SAMA I ZAWSZE ISTNIEJE: element ma nazwę.
     * Przycisk ma etykietę („Zaloguj mnie w Kuking"), a błędy formularza mają
     * podsumowanie na górze, którego §5 wymaga niezależnie od tego testu
     * (`x-error-summary`). Kolor wolno DODAĆ, nie wolno na nim POPRZESTAĆ.
     */
    public function test_instrukcja_nie_wskazuje_elementu_kolorem(): void
    {
        $kolory = 'zielon|czerwon|niebiesk|żółt|pomarańczow|fioletow|różow|brązow|szar|biał|czarn';
        $elementy = 'przycisk|guzik|pole|ramk|napis|link|odnośnik|pasek|kropk|znacznik|obwódk|strzałk|ikon';

        $wzory = [
            // „zielony przycisk", „czerwoną cienką ramkę"
            '/(?:'.$kolory.')\w*\s+(?:\p{L}+\s+){0,2}(?:'.$elementy.')/iu',
            // „przycisk zielony", „pole podświetlone na żółto" łapie wzór niżej
            '/(?:'.$elementy.')\w*\s+(?:\p{L}+\s+){0,2}(?:'.$kolory.')\w*\b/iu',
            // „zaznaczone na czerwono", „podświetlone na żółto"
            '/\bna\s+(?:zielono|czerwono|żółto|niebiesko|pomarańczowo|szaro|biało|czarno)\b/iu',
        ];

        $winowajcy = [];

        foreach ($this->plikiBlade(resource_path('views')) as $plik) {
            $tresc = $this->bezStylow($this->bezKomentarzyBlade((string) file_get_contents($plik)));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                foreach ($wzory as $wzor) {
                    if (preg_match($wzor, $linia) === 1) {
                        $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → '.trim($linia);
                        break;
                    }
                }
            }
        }

        foreach ($this->plikiPhp(app_path()) as $plik) {
            foreach ($this->napisyZPliku($plik) as $numerLinii => $napis) {
                foreach ($wzory as $wzor) {
                    if (preg_match($wzor, $napis) === 1) {
                        $winowajcy[] = $this->skrot($plik).':'.$numerLinii.' → '.trim($napis);
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Instrukcja wskazuje element kolorem:\n".implode("\n", $winowajcy)."\n"
            .'WCAG 2.2 AA, kryterium 1.4.1 — kolor nie może być jedyną drogą do '
            .'elementu. Nazwij przycisk jego etykietą, a przy błędach formularza '
            .'odeślij do podsumowania na górze (`x-error-summary`).',
        );
    }

    /**
     * KONTROLA DODATNIA dla testu wyżej: gdyby `bezStylow()` wycinało za dużo
     * (na przykład cały dokument), test wyżej byłby zawsze zielony i nikt by
     * tego nie zauważył. Ten test sprawdza, że wzory naprawdę łapią zdania,
     * o które chodzi — i że NIE łapią przykładu hasła ani arkusza stylów.
     */
    public function test_wzory_na_kolor_lapia_to_co_maja_lapac(): void
    {
        $kolory = 'zielon|czerwon|niebiesk|żółt|pomarańczow|fioletow|różow|brązow|szar|biał|czarn';
        $elementy = 'przycisk|guzik|pole|ramk|napis|link|odnośnik|pasek|kropk|znacznik|obwódk|strzałk|ikon';

        $wzory = [
            '/(?:'.$kolory.')\w*\s+(?:\p{L}+\s+){0,2}(?:'.$elementy.')/iu',
            '/(?:'.$elementy.')\w*\s+(?:\p{L}+\s+){0,2}(?:'.$kolory.')\w*\b/iu',
            '/\bna\s+(?:zielono|czerwono|żółto|niebiesko|pomarańczowo|szaro|biało|czarno)\b/iu',
        ];

        $lapie = fn (string $zdanie): bool => array_reduce(
            $wzory,
            fn (bool $do, string $wzor): bool => $do || preg_match($wzor, $zdanie) === 1,
            false,
        );

        // Cztery zdania, które naprawdę stały w repozytorium do tej zmiany.
        $this->assertTrue($lapie('Otwórz ją i kliknij zielony przycisk.'), 'Wzór nie łapie „zielony przycisk".');
        $this->assertTrue($lapie('Jeśli to Ty — kliknij zielony przycisk poniżej.'), 'Wzór nie łapie wariantu z „poniżej".');
        $this->assertTrue($lapie('popraw tylko to, co jest zaznaczone na czerwono.'), 'Wzór nie łapie „zaznaczone na czerwono".');
        $this->assertTrue($lapie('Szukaj pola podświetlonego na żółto'), 'Wzór nie łapie „na żółto".');

        // A te muszą przechodzić — inaczej test wyżej zacznie kłamać w drugą stronę.
        $this->assertFalse($lapie('trzy słowa razem, na przykład: zielonapietruszkarano.'), 'Przykład hasła to nie instrukcja po kolorze.');
        $this->assertFalse($lapie('Czytelne na wydruku czarno-białym.'), 'Opis wydruku to nie instrukcja po kolorze.');
        $this->assertFalse($lapie('Kliknij przycisk „Zaloguj mnie w Kuking”.'), 'Nazwa przycisku to poprawna droga.');
    }

    // ---------------------------------------------------------------
    // 6. Cytat na stronie powitalnej mówi to, co mówi kod
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
    // 7. Czerwień, którą piszemy „KING", jest czytelna
    // ---------------------------------------------------------------

    /**
     * Tła, na których stoi dwukolorowa nazwa — wypisane WPROST, po roli.
     *
     * To nie jest lista wszystkich powierzchni w systemie, tylko tych, na
     * których nazwa naprawdę leży dzisiaj: strona (`surface`), karta i stopka
     * (`surface-raised`), ramka pomocnicza i pole (`surface-sunken`), ciepły
     * pas na stronie powitalnej (`surface-brand-wash`) oraz podkład marki
     * (`brand-tint`, np. bieżąca pozycja nawigacji). Dokładasz nazwę na nowe
     * tło — dopisz je tutaj, a nie licz, że „pewnie wychodzi".
     */
    private const TLA_POD_NAZWA = [
        '--color-surface',
        '--color-surface-raised',
        '--color-surface-sunken',
        '--color-surface-brand-wash',
        '--color-brand-tint',
    ];

    /**
     * „KING" jest pisane kolorem marki, więc jest TEKSTEM, a nie dekoracją —
     * i obowiązuje go WCAG 2.2 AA, kryterium 1.4.3: 4,5:1 wobec tła.
     *
     * DLACZEGO NA TOKENACH, A NIE W PRZEGLĄDARCE
     * Tak samo jak `MinimalnyRozmiarTekstuTest` liczy rozmiary z arkusza:
     * `scripts/dostepnosc.mjs` chodzi osobno i wolno, a ten test ma oblać
     * w tej samej sekundzie, w której ktoś rozjaśni `--color-brand`
     * „żeby było bardziej pomarańczowe".
     *
     * Zmierzone 11 września 2026 (motyw jasny → ciemny):
     *   surface            5,31 → 7,80
     *   surface-raised     5,72 → 6,92
     *   surface-sunken     4,83 → 8,49
     *   surface-brand-wash 4,83 → 7,03
     *   brand-tint         4,64 → 6,55
     * Najciaśniej jest w motywie JASNYM na `brand-tint` (4,64) — zapas do
     * progu to 0,14. Rozjaśnienie `--color-brand` choćby o jeden krok
     * zabiera ten zapas, i właśnie dlatego ten test istnieje.
     *
     * CZEGO TEN TEST NIE OBEJMUJE: tła W KOLORZE MARKI (przycisk
     * podstawowy). Tam czerwień na czerwieni daje 1,00:1 i nie ma odcienia,
     * który by to naprawił — dlatego „KING" bierze wtedy kolor otoczenia
     * (`.btn .kuking-word strong` oraz `kuking-word--bez-koloru`), a nośnikiem
     * zostają wersaliki. Osobna asercja niżej pilnuje, że ta reguła nie
     * wyparuje przy sprzątaniu CSS-a.
     */
    public function test_czerwien_marki_ma_kontrast_na_kazdym_tle_w_obu_motywach(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/tokens.css')));

        $motywy = [
            'jasny' => $this->blokTokenow($css, '@theme {'),
            'ciemny' => $this->blokTokenow($css, ':root[data-theme="dark"],'),
        ];

        foreach ($motywy as $motyw => $blok) {
            $czerwien = $this->token($blok, '--color-brand', $motyw);

            foreach (self::TLA_POD_NAZWA as $tlo) {
                $wartosc = $this->token($blok, $tlo, $motyw);
                $kontrast = $this->kontrast($czerwien, $wartosc);

                $this->assertGreaterThanOrEqual(
                    4.5,
                    $kontrast,
                    sprintf(
                        'Motyw %s: „KING" w kolorze %s na tle %s (%s) ma kontrast %.2f:1, '
                        .'a WCAG 2.2 AA wymaga 4,5:1 dla tekstu. To jest połowa nazwy serwisu '
                        .'pisana kolorem, nie ozdoba — przyciemnij --color-brand albo rozjaśnij tło, '
                        .'nie obniżaj progu.',
                        $motyw, $czerwien, $tlo, $wartosc, $kontrast,
                    ),
                );
            }
        }

        // Tło W KOLORZE MARKI — jedyne, na którym czerwieni nie da się użyć.
        $app = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '~\.btn \.kuking-word strong,\s*\.kuking-word--bez-koloru strong \{\s*color: inherit;~',
            $app,
            'Zniknęła reguła, która na tle w kolorze marki zdejmuje z „KING" kolor marki. '
            .'Bez niej przycisk podstawowy ma czerwień na czerwieni, czyli kontrast 1,00:1.',
        );
    }

    /**
     * KONTROLA POMIARU dla testu wyżej — w obie strony.
     *
     * Licznik kontrastu można zepsuć tak, że nadal zwraca liczby: pomylona
     * kolejność kanałów, brak korekty gamma, dzielenie odwrotne. Każda z tych
     * pomyłek daje wynik „jakiś", a test wyżej robi się wtedy zielony
     * niezależnie od palety. Dlatego liczymy tu trzy pary o znanym wyniku.
     */
    public function test_licznik_kontrastu_daje_znane_wyniki(): void
    {
        $this->assertSame(21.0, round($this->kontrast('#000000', '#FFFFFF'), 2), 'Czerń na bieli to 21:1.');
        $this->assertSame(1.0, round($this->kontrast('#B3401F', '#B3401F'), 2), 'Kolor sam na sobie to 1:1.');
        $this->assertSame(
            round($this->kontrast('#FFFFFF', '#B3401F'), 4),
            round($this->kontrast('#B3401F', '#FFFFFF'), 4),
            'Kontrast jest symetryczny — kolejność argumentów nie ma prawa go zmieniać.',
        );

        // Para, która MA oblewać: jasna czerwień motywu ciemnego wstawiona
        // na jasne tło. Gdyby próg dało się przejść czymkolwiek, ten
        // assert by o tym powiedział.
        $this->assertLessThan(4.5, $this->kontrast('#F2986A', '#FAF6F0'));
    }

    /**
     * Wyjście awaryjne z §2 punkt 5 DZIAŁA — bo inaczej byłoby udawaniem.
     *
     * `<x-kuking-word bez-koloru />` zdejmuje kolor, a NIE treść: zapis
     * z wersalikami i wersja dla czytnika ekranu zostają identyczne.
     * Bez tego testu parametr byłby obietnicą w komentarzu: literówka
     * w nazwie propa nie wywołuje w Blade żadnego błędu, tylko po cichu
     * nic nie robi.
     */
    public function test_komponent_nazwy_umie_zdjac_kolor_na_tle_marki(): void
    {
        $zwykly = (string) Blade::render('<x-kuking-word forma="iem" />');
        $bezKoloru = (string) Blade::render('<x-kuking-word forma="iem" bez-koloru />');

        $this->assertStringContainsString('class="kuking-word"', $zwykly);
        $this->assertStringNotContainsString('bez-koloru', $zwykly);

        $this->assertStringContainsString('class="kuking-word kuking-word--bez-koloru"', $bezKoloru);

        foreach (['zwykły' => $zwykly, 'bez koloru' => $bezKoloru] as $wariant => $html) {
            $this->assertStringContainsString('ku<strong>KING</strong>iem', $html, "Wariant {$wariant} zgubił zapis.");
            $this->assertStringContainsString('<span class="visually-hidden">kukingiem</span>', $html, "Wariant {$wariant} zgubił wersję dla czytnika ekranu.");
        }
    }

    // ---------------------------------------------------------------
    // 8. Nazwa nie trafia tam, gdzie koloru nie ma
    // ---------------------------------------------------------------

    /**
     * `alt`, `title`, `aria-label`, `<title>`, `meta`, temat listu, pliki
     * eksportu — tam kolor nie istnieje, a znacznik by przeszkadzał.
     *
     * Dwie różne usterki naraz, obie ciche:
     *
     *   1. KOMPONENT W ATRYBUCIE. `<x-kuking-word/>` wewnątrz `title=""`
     *      albo `alt=""` wypisze tam surowy HTML — czytnik ekranu przeczyta
     *      znaczniki, a podpowiedź przeglądarki pokaże je dosłownie.
     *   2. LITERAŁ `kuKING` W MIEJSCU BEZ FORMATOWANIA. W temacie listu,
     *      w `<title>` i w paczce eksportu wersaliki w środku wyrazu nie
     *      niosą już żadnej gry — niosą wrażenie literówki. Tam piszemy
     *      „Kuking" (`COPY_STYLE.md` §2, koniec sekcji).
     */
    public function test_nazwa_nie_wchodzi_tam_gdzie_koloru_nie_ma(): void
    {
        $winowajcy = [];

        // 1. Komponent albo klasa w atrybucie tekstowym.
        $atrybuty = 'alt|title|aria-label|aria-description|placeholder|content|description';

        foreach ($this->plikiBlade(resource_path('views')) as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                if (preg_match('~(?:'.$atrybuty.')\s*=\s*"[^"]*(?:x-kuking-word|kuking-word)~i', $linia) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → komponent w atrybucie';
                }
            }
        }

        // 2. Literał z wersalikami w miejscach bez formatowania: <title>,
        //    paczka eksportu, szablony listów.
        $bezFormatowania = array_merge(
            $this->plikiBlade(resource_path('views/exports')),
            $this->plikiBlade(resource_path('views/mail')),
        );

        foreach ($bezFormatowania as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                foreach (self::ZAPISY_GRY_SLOWEM as $zapis) {
                    if (str_contains($linia, $zapis)) {
                        $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → '.$zapis;
                    }
                }
            }
        }

        foreach ($this->plikiBlade(resource_path('views')) as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                if (preg_match('~<title[^>]*>[^<]*kuKING~', $linia) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → tytuł strony';
                }
            }
        }

        // 3. Tematy listów i ich nadawcy — składane w PHP poza `app/`,
        //    które sprawdza już inny test w tym pliku.
        foreach (['config/mail.php', 'config/kuking.php'] as $plik) {
            $this->assertStringNotContainsString(
                'kuKING',
                (string) file_get_contents(base_path($plik)),
                $plik.' zawiera zapis „kuKING". Temat i nadawca listu to czysty tekst — '
                .'kolor tam nie istnieje, a wersaliki w środku wyrazu czytają się jak literówka.',
            );
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Nazwa „kuKING” trafiła tam, gdzie koloru nie ma:\n".implode("\n", $winowajcy)."\n"
            .'docs/brand/GLOS_MARKI.md §3: w `alt`, `title`, `aria-label`, tytule strony, '
            .'temacie listu i plikach eksportu piszemy zwyczajnie „Kuking".',
        );
    }

    /**
     * KONTROLA POMIARU dla testu wyżej: wzór na atrybut ma łapać to, co ma
     * łapać, i przepuszczać poprawne użycie w treści.
     */
    public function test_wzor_na_atrybut_lapie_to_co_ma_lapac(): void
    {
        $atrybuty = 'alt|title|aria-label|aria-description|placeholder|content|description';
        $lapie = fn (string $linia): bool => preg_match(
            '~(?:'.$atrybuty.')\s*=\s*"[^"]*(?:x-kuking-word|kuking-word)~i',
            $linia,
        ) === 1;

        $this->assertTrue($lapie('<x-layout title="O <x-kuking-word />">'));
        $this->assertTrue($lapie('<img alt="Logo <span class=\'kuking-word\'>" src="x">'));
        $this->assertTrue($lapie('<x-empty-state title="<x-kuking-word /> dopiero się zaczyna">'));

        // Poprawne: komponent w TREŚCI, a w atrybucie zwykły „Kuking".
        $this->assertFalse($lapie('<h1>O <x-kuking-word /></h1>'));
        $this->assertFalse($lapie('<x-layout title="O Kuking" description="Czym jest Kuking.">'));
        $this->assertFalse($lapie('<p>Świeżo z <x-kuking-word /></p>'));
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    /**
     * Ciało bloku tokenów — od podanego otwarcia do pierwszej klamry
     * stojącej na początku linii.
     *
     * Deklaracje w tych blokach są wcięte, a klamra zamykająca nie jest, więc
     * to wystarcza i nie wymaga liczenia zagnieżdżeń. Komentarze muszą być
     * wycięte PRZED wywołaniem — w tym arkuszu jest ich więcej niż kodu
     * i niektóre zawierają klamry.
     */
    private function blokTokenow(string $css, string $otwarcie): string
    {
        $start = strpos($css, $otwarcie);

        $this->assertNotFalse(
            $start,
            "Nie znalazłem w tokens.css bloku otwieranego przez „{$otwarcie}”. ".
            'Jeśli arkusz przebudowano, popraw kotwicę w tym teście — nie usuwaj go, '.
            'bo wtedy kontrast czerwieni przestaje być pilnowany.',
        );

        $start += strlen($otwarcie);
        $koniec = strpos($css, "\n}", $start);

        return $koniec === false ? substr($css, $start) : substr($css, $start, $koniec - $start);
    }

    /** Wartość jednego tokenu koloru z ciała bloku, w zapisie `#RRGGBB`. */
    private function token(string $blok, string $nazwa, string $motyw): string
    {
        $this->assertSame(
            1,
            preg_match('/'.preg_quote($nazwa, '/').':\s*(#[0-9A-Fa-f]{6})\s*;/', $blok, $trafienie),
            "W motywie {$motyw} nie znalazłem tokenu {$nazwa} w zapisie #RRGGBB.",
        );

        return strtoupper($trafienie[1]);
    }

    /** Kontrast dwóch kolorów według WCAG 2.x (relative luminance). */
    private function kontrast(string $a, string $b): float
    {
        $jasnosc = static function (string $hex): float {
            $kanaly = array_map(
                static function (string $kanal): float {
                    $wartosc = hexdec($kanal) / 255;

                    return $wartosc <= 0.03928
                        ? $wartosc / 12.92
                        : ((($wartosc + 0.055) / 1.055) ** 2.4);
                },
                str_split(ltrim($hex, '#'), 2),
            );

            return 0.2126 * $kanaly[0] + 0.7152 * $kanaly[1] + 0.0722 * $kanaly[2];
        };

        $pierwszy = $jasnosc($a);
        $drugi = $jasnosc($b);

        return ($pierwszy > $drugi ? $pierwszy + 0.05 : $drugi + 0.05)
            / ($pierwszy > $drugi ? $drugi + 0.05 : $pierwszy + 0.05);
    }

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

    /**
     * Komentarz Blade znika, ale JEGO ZŁAMANIA LINII ZOSTAJĄ.
     *
     * Bez tego numer linii w komunikacie o błędzie wskazywał inne miejsce niż
     * usterka — a komentarze w tym repozytorium bywają dłuższe niż kod, więc
     * rozjazd sięgał kilkudziesięciu linii. Człowiek dostawał adres, pod
     * którym nic nie ma, i musiał szukać sam.
     */
    private function bezKomentarzyBlade(string $tresc): string
    {
        return $this->wytnijZachowujacLinie('/\{\{--.*?--\}\}/s', $tresc);
    }

    /** Wycina dopasowania, zostawiając w ich miejsce tyle złamań linii, ile zjadło. */
    private function wytnijZachowujacLinie(string $wzor, string $tresc): string
    {
        return (string) preg_replace_callback(
            $wzor,
            fn (array $trafienie): string => str_repeat("\n", substr_count($trafienie[0], "\n")),
            $tresc,
        );
    }

    /**
     * Arkusze stylów i style w linii wycinamy, bo nazwa koloru w CSS nie jest
     * zdaniem do przeczytania. Zwykłych znaczników NIE wycinamy: `x-field`
     * niesie tekst widoczny dla człowieka w atrybutach `label` i `help`,
     * więc wycięcie znaczników zrobiłoby w tym teście dziurę dokładnie tam,
     * gdzie stoi podpowiedź pod polem.
     */
    private function bezStylow(string $tresc): string
    {
        $tresc = $this->wytnijZachowujacLinie('/<style\b[^>]*>.*?<\/style>/is', $tresc);
        $tresc = $this->wytnijZachowujacLinie('/\sstyle\s*=\s*"[^"]*"/is', $tresc);
        $tresc = $this->wytnijZachowujacLinie("/\sstyle\s*=\s*'[^']*'/is", $tresc);

        return $this->wytnijZachowujacLinie('/<!--.*?-->/s', $tresc);
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
