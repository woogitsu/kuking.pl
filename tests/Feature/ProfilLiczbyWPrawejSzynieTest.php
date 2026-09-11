<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Liczby o osobie: w karcie na wąskim ekranie, w prawej szynie na szerokim (D-091).
 *
 * ZGŁOSZENIE WŁAŚCICIELA, DOSŁOWNIE
 * „jestem na profilu użytkownika, patrz prawa kolumna jest marnowana, można
 * tam dać info o użytkowniku (ile wpisów, przepisów, obs, obserwuj itp itd,
 * a nie na środku przez co wpisy są dużo niżej".
 *
 * CO TU JEST SPRAWDZANE I DLACZEGO WŁAŚNIE TO
 *
 *  1. DWA EGZEMPLARZE, KAŻDY DOKŁADNIE RAZ. Ta sama piątka liczb stoi od
 *     tej zmiany w dokumencie dwa razy (karta + szyna), bo szyna jest
 *     rodzeństwem `<main>` i CSS nie wsunie jej zawartości do środka karty,
 *     a skrypt przenoszący węzeł łamałby „bez JavaScriptu". Trzeci egzemplarz
 *     albo dwie listy w jednej sekcji znaczyłyby, że przenosiny stanęły
 *     w połowie — i właśnie tego nie widać gołym okiem, bo przy 1512 px
 *     obie kolumny są widoczne naraz.
 *
 *  2. ARKUSZ, NIE TYLKO HTML. O tym, który egzemplarz widać, decyduje PARA
 *     reguł w `ekran-profilu.css`. Test HTML-owy przejdzie także wtedy, gdy
 *     ktoś skasuje jedną z nich i liczby zaczną się dublować na ekranie —
 *     dlatego reguły są sprawdzane wprost, razem z wykluczeniem układu
 *     gościa (`.app-body-solo`).
 *
 *  3. GOŚĆ NIE DOSTAJE DRUGIEGO EGZEMPLARZA — a od 11 września 2026 (D-122)
 *     z innego powodu, niż tu stało. Było: „gość ma jedną kolumnę na KAŻDEJ
 *     szerokości, więc szyna leci u niego pod treścią nawet przy 1512 px".
 *     Dziś na profilu ma od 80rem dwie kolumny i szyna stoi obok treści.
 *     Powód zostaje w mocy, tylko inny: liczby widzi w KARCIE, której mu nie
 *     chowamy (reguła wyklucza układ gościa), więc blok w szynie byłby
 *     DRUGIM, WIDOCZNYM egzemplarzem tych samych liczb na jednym ekranie.
 *
 *  4. WARTOŚCI, W TYM ZERA. Zero pokazujemy, nie chowamy — i oba egzemplarze
 *     mają pokazywać TĘ SAMĄ liczbę, bo biorą ją z jednej tablicy `stats`.
 *
 *  5. ODNOŚNIKI OBSERWUJĄCYCH/OBSERWOWANYCH prowadzą tam, gdzie prowadziły,
 *     i w szynie też są odnośnikami z polem klikalnym 48 px.
 *
 *  6. LICZBA ZAPYTAŃ. Profil liczy pięć rzeczy; pokazanie ich w dwóch
 *     miejscach nie ma prawa policzyć ich drugi raz.
 *
 * WYCINAMY SEKCJĘ, NIE SZUKAMY W CAŁYM HTML-u. Karta, szyna, stopka
 * i nawigacja zawierają te same słowa i te same liczby — asercja na całym
 * dokumencie łapie „wpisów" z zupełnie innego miejsca. Wzorzec za DOMXPath
 * z testów landingu (`docs/PULAPKI_TESTOW.md`).
 */
class ProfilLiczbyWPrawejSzynieTest extends TestCase
{
    use RefreshDatabase;

    /** Klasa listy w karcie profilu (egzemplarz wąskiego ekranu). */
    private const KLASA_KARTY = 'profil-liczby-karta';

    /** Klasa opakowania bloku w prawej szynie (egzemplarz szerokiego ekranu). */
    private const KLASA_SZYNY = 'profil-liczby-szyna';

    public function test_liczby_stoja_i_w_karcie_i_w_szynie_i_kazda_sekcja_ma_je_dokladnie_raz(): void
    {
        $gospodarz = $this->osobaZTrescia('dwa_egzemplarze');
        $ogladajacy = $this->user('ogladajacy_dwa');

        $html = $this->actingAs($ogladajacy)
            ->get(route('profile.show', 'dwa_egzemplarze'))
            ->assertOk()
            ->getContent();

        // Asercja kontrolna: to naprawdę profil tej osoby, a nie strona błędu.
        $this->assertStringContainsString($gospodarz->profile->display_name, (string) $html);

        $karta = $this->wycinekKarty((string) $html);
        $szyna = $this->wycinekSzyny((string) $html);

        // Podpisy odpowiadają treści z `osobaZTrescia()`: 2 wpisy, 1 przepis,
        // 0 razy Ugotowałem, 0 obserwujących, 0 obserwowanych.
        foreach (['wpisy', 'przepis', 'razy „Ugotowałem”', 'obserwujących', 'obserwowanych'] as $podpis) {
            $this->assertSame(
                1,
                substr_count($karta, '<span class="stat-label">'.$podpis.'</span>'),
                "Podpis „{$podpis}” ma stać w karcie profilu dokładnie raz.",
            );

            $this->assertSame(
                1,
                substr_count($szyna, '<span class="stat-label">'.$podpis.'</span>'),
                "Podpis „{$podpis}” ma stać w bloku prawej szyny dokładnie raz.",
            );
        }

        // TRZECIEGO EGZEMPLARZA NIE MA. Gdyby ktoś dołożył liczby jeszcze
        // gdzieś (np. zostawił starą listę w widoku obok nowego składnika),
        // sekcje wyżej dalej byłyby poprawne, a układ już nie.
        $this->assertSame(
            2,
            substr_count((string) $html, 'class="profil-liczniki '),
            'W dokumencie mają być DOKŁADNIE dwie listy liczb: jedna w karcie, '
            .'jedna w prawej szynie. Trzecia znaczy, że przenosiny stanęły w połowie.',
        );

        // Blok szyny leży NAPRAWDĘ w szynie, a nie gdziekolwiek indziej —
        // inaczej reguła `@media` z `ekran-profilu.css` nie miałaby czego
        // chować, bo `.app-rail` w ogóle nie byłoby jego przodkiem.
        $this->assertTrue(
            $this->blokSzynyLezyWSzynie((string) $html),
            'Blok z liczbami ma leżeć wewnątrz `<aside class="app-rail">`.',
        );
    }

    public function test_arkusz_pokazuje_dokladnie_jeden_egzemplarz_liczb(): void
    {
        $css = (string) file_get_contents(resource_path('css/ekran-profilu.css'));

        // Asercja kontrolna: czytamy naprawdę arkusz profilu.
        $this->assertStringContainsString('.profil-glowka-tresc', $css);

        // 1. Domyślnie (telefon, tablet) widać KARTĘ, a egzemplarz szyny jest
        //    schowany. Bez tej reguły liczby na telefonie stałyby dwa razy:
        //    raz w karcie i raz pod całym archiwum wpisów.
        $this->assertMatchesRegularExpression(
            '/\.'.self::KLASA_SZYNY.'\s*\{\s*display:\s*none;\s*\}/',
            $css,
            'Egzemplarz w szynie ma być domyślnie schowany — poniżej 80rem '
            .'`.app-rail` nie znika, tylko ląduje pod treścią.',
        );

        // 2. Od 80rem jest odwrotnie — i obie reguły stoją w JEDNYM zapytaniu
        //    o szerokość. Rozdzielone rozjeżdżają się przy pierwszej poprawce.
        $wZapytaniu = $this->trescZapytania80rem($css);

        $this->assertMatchesRegularExpression(
            '/\.app-body:not\(\.app-body-solo\):not\(\.app-body-powitalny\)\s+\.'
            .self::KLASA_SZYNY.'\s*\{\s*display:\s*block;\s*\}/',
            $wZapytaniu,
            'Od 80rem blok liczb w szynie ma być widoczny.',
        );

        $this->assertMatchesRegularExpression(
            '/\.app-body:not\(\.app-body-solo\):not\(\.app-body-powitalny\)\s+\.'
            .self::KLASA_KARTY.'\s*\{\s*display:\s*none;\s*\}/',
            $wZapytaniu,
            'Od 80rem egzemplarz w karcie ma zniknąć — inaczej te same pięć '
            .'liczb stoi na ekranie dwa razy, a karta nie skraca się ani o wiersz.',
        );

        // 3. ŻADNA INNA REGUŁA nie pokazuje z powrotem egzemplarza schowanego.
        //    To jest ta zmiana, która przechodzi wszystkie testy HTML-owe
        //    i psuje wyłącznie ekran.
        $this->assertSame(
            1,
            preg_match_all('/\.'.self::KLASA_KARTY.'\s*\{/', $css),
            'Egzemplarz w karcie ma w arkuszu dokładnie jedną regułę — tę, '
            .'która go chowa od 80rem.',
        );

        // Wykluczenie układu gościa (`:not(.app-body-solo)`) jest częścią
        // mechanizmu, nie ozdobą — i jest dowiedzione przez punkt 2 razem
        // z tą asercją: skoro reguła jest dokładnie jedna i ma dokładnie tę
        // postać, to zdjęcie `:not()` oblewa punkt 2.
        $this->assertSame(
            2,
            preg_match_all('/\.'.self::KLASA_SZYNY.'\s*\{/', $css),
            'Egzemplarz w szynie ma w arkuszu dokładnie dwie reguły: domyślne '
            .'ukrycie i pokazanie od 80rem.',
        );
    }

    public function test_gosc_widzi_liczby_w_karcie_i_nie_dostaje_drugiego_egzemplarza(): void
    {
        $this->osobaZTrescia('gosc_patrzy');

        $html = (string) $this->get(route('profile.show', 'gosc_patrzy'))->assertOk()->getContent();

        // Asercja kontrolna.
        $this->assertStringContainsString('gosc_patrzy', $html);

        $karta = $this->wycinekKarty($html);
        $this->assertStringContainsString('<span class="stat-label">wpisy</span>', $karta);

        // Gość widzi liczby w karcie (reguła chowająca kartę wyklucza jego
        // układ), więc blok w szynie byłby drugim, WIDOCZNYM egzemplarzem
        // tych samych liczb — od D-122 jego szyna stoi obok treści.
        $this->assertStringNotContainsString(
            self::KLASA_SZYNY,
            $html,
            'Gość nie dostaje bloku liczb w szynie: te same liczby zostają mu '
            .'w karcie profilu, więc blok byłby ich drugim egzemplarzem.',
        );

        $this->assertSame(
            1,
            substr_count($html, 'class="profil-liczniki '),
            'Gość ma zobaczyć dokładnie jedną listę liczb — tę w karcie.',
        );
    }

    public function test_wartosci_sa_prawdziwe_takze_gdy_wynosza_zero_i_zgadzaja_sie_w_obu_miejscach(): void
    {
        // 3 wpisy, 0 przepisów, 2 razy Ugotowałem, 1 obserwujący, 0 obserwowanych.
        // Zero stoi na dwóch z pięciu pozycji celowo: zera pokazujemy.
        $gospodarz = $this->user('liczby_prawdziwe', ['display_name' => 'Liczby Prawdziwe']);

        for ($i = 0; $i < 3; $i++) {
            Post::factory()->create([
                'author_id' => $gospodarz->getKey(),
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => 'published',
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $cudzyPrzepis = Recipe::factory()->create([
            'author_id' => $this->user('autor_przepisu')->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Rosół sąsiada',
            'slug' => 'rosol-sasiada-'.Str::lower(Str::random(6)),
        ]);

        CookedEvent::factory()->count(2)->create([
            'recipe_id' => $cudzyPrzepis->getKey(),
            'user_id' => $gospodarz->getKey(),
        ]);

        $ogladajacy = $this->user('obserwator_liczb');
        app(FollowUser::class)->handle($ogladajacy, $gospodarz);

        $html = (string) $this->actingAs($ogladajacy)
            ->get(route('profile.show', 'liczby_prawdziwe'))
            ->assertOk()
            ->getContent();

        // Asercja kontrolna.
        $this->assertStringContainsString('Liczby Prawdziwe', $html);

        $oczekiwane = [
            '<span class="stat-value">3</span> <span class="stat-label">wpisy</span>',
            '<span class="stat-value">0</span> <span class="stat-label">przepisów</span>',
            '<span class="stat-value">2</span> <span class="stat-label">razy „Ugotowałem”</span>',
            '<span class="stat-value">1</span> <span class="stat-label">obserwujący</span>',
            '<span class="stat-value">0</span> <span class="stat-label">obserwowanych</span>',
        ];

        $karta = $this->wycinekKarty($html);
        $szyna = $this->wycinekSzyny($html);

        foreach ($oczekiwane as $wiersz) {
            $this->assertStringContainsString($wiersz, $karta, "Karta profilu ma pokazać: {$wiersz}");
            $this->assertStringContainsString($wiersz, $szyna, "Szyna profilu ma pokazać: {$wiersz}");
        }
    }

    public function test_odnosniki_obserwujacych_i_obserwowanych_prowadza_tam_gdzie_prowadzily(): void
    {
        $this->osobaZTrescia('odnosniki_szyny');

        $html = (string) $this->actingAs($this->user('klikajacy'))
            ->get(route('profile.show', 'odnosniki_szyny'))
            ->assertOk()
            ->getContent();

        $szyna = $this->wycinekSzyny($html);
        $karta = $this->wycinekKarty($html);

        // Asercja kontrolna: wycinki nie są puste.
        $this->assertStringContainsString('stat-value', $szyna);
        $this->assertStringContainsString('stat-value', $karta);

        foreach (['social.followers', 'social.following'] as $trasa) {
            $adres = route($trasa, 'odnosniki_szyny');

            foreach (['szyna' => $szyna, 'karta' => $karta] as $gdzie => $wycinek) {
                // Odnośnik, a nie sam tekst — i z klasą, która daje mu 48 px
                // pola klikalnego (`.profil-licznik-pole`, UX_50_PLUS.md).
                $this->assertMatchesRegularExpression(
                    '~<a class="profil-licznik-pole link-jak-tekst" href="'.preg_quote($adres, '~').'">~',
                    $wycinek,
                    "W wycinku „{$gdzie}” liczba ma być odnośnikiem do {$adres} "
                    .'z pełnym polem klikalnym.',
                );
            }
        }
    }

    public function test_wlasny_profil_i_cudzy_maja_wlasny_tytul_bloku_oraz_wlasny_rzad_przyciskow(): void
    {
        $gospodarz = $this->osobaZTrescia('dwa_warianty');

        $swoj = (string) $this->actingAs($gospodarz)
            ->get(route('profile.show', 'dwa_warianty'))
            ->assertOk()
            ->getContent();

        $szynaWlasna = $this->wycinekSzyny($swoj);
        $this->assertStringContainsString('Twoje liczby', $szynaWlasna);
        $this->assertStringNotContainsString('Ta osoba w liczbach', $szynaWlasna);
        // Rząd przycisków własnego profilu zostaje nietknięty.
        $this->assertStringContainsString('Zmień swój profil', $swoj);

        $cudzy = (string) $this->actingAs($this->user('obcy_wariant'))
            ->get(route('profile.show', 'dwa_warianty'))
            ->assertOk()
            ->getContent();

        $szynaCudza = $this->wycinekSzyny($cudzy);
        $this->assertStringContainsString('Ta osoba w liczbach', $szynaCudza);
        $this->assertStringNotContainsString('Twoje liczby', $szynaCudza);
        // Rząd przycisków cudzego profilu też zostaje nietknięty.
        $this->assertStringContainsString('Obserwuj', $cudzy);
        $this->assertStringContainsString('Zgłoś', $cudzy);
        $this->assertStringContainsString('Zablokuj', $cudzy);
        $this->assertStringNotContainsString('Zmień swój profil', $cudzy);
    }

    public function test_liczby_w_dwoch_miejscach_nie_doklada_ani_jednego_zapytania(): void
    {
        $gospodarz = $this->osobaZTrescia('bez_wachlarza');
        $ogladajacy = $this->user('licznik_zapytan');

        $agregaty = $this->agregatyNaStronie(
            fn () => $this->actingAs($ogladajacy)
                ->get(route('profile.show', 'bez_wachlarza'))
                ->assertOk(),
        );

        fwrite(STDERR, sprintf(
            "\n[liczby profilu] zapytan z count(*): %d\n",
            $agregaty,
        ));

        // PIĘĆ LICZB — PIĘĆ AGREGATÓW, NIE DZIESIĘĆ.
        //
        // Zmierzone na cudzym profilu oglądanym przez zalogowanego, siedem
        // agregatów i wszystkie nazwane: pięć z tablicy `stats` (wpisy,
        // przepisy, Ugotowałem, obserwujący, obserwowani), jeden z paginacji
        // archiwum wpisów i jeden z licznika powiadomień w belce. Liczba jest
        // przypięta na sztywno, bo dokładnie tak wygląda regresja, przed którą
        // ten test stoi: blok w szynie liczący sobie własne `->count()`
        // zamiast wziąć gotową tablicę `stats` podniósłby ją o pięć i nie
        // zmienił ani jednego piksela na ekranie.
        $this->assertSame(
            7,
            $agregaty,
            'Liczby o osobie mają być policzone RAZ i pokazane dwa razy. '
            .'Wzrost tej liczby znaczy, że drugi egzemplarz liczy sobie sam.',
        );

        // Kontrola dodatnia dla samego pomiaru: gdyby licznik nie widział
        // żadnego zapytania, asercja wyżej też by nie zadziałała.
        $this->assertGreaterThan(0, $agregaty);
    }

    /**
     * Profil z odrobiną treści — na tyle, żeby żaden licznik nie był zerem
     * z braku danych, a nie z braku poprawnego zapytania.
     */
    private function osobaZTrescia(string $nazwa): User
    {
        $osoba = $this->user($nazwa, ['display_name' => 'Osoba '.$nazwa]);

        Post::factory()->count(2)->create([
            'author_id' => $osoba->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => 'published',
            'published_at' => now(),
        ]);

        Recipe::factory()->create([
            'author_id' => $osoba->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Przepis '.$nazwa,
            'slug' => Str::slug('przepis-'.$nazwa).'-'.Str::lower(Str::random(6)),
        ]);

        return $osoba;
    }

    /** Lista liczb z KARTY profilu (egzemplarz wąskiego ekranu). */
    private function wycinekKarty(string $html): string
    {
        return $this->wycinekPoKlasie($html, 'ul', self::KLASA_KARTY);
    }

    /** Blok liczb z PRAWEJ SZYNY (egzemplarz szerokiego ekranu). */
    private function wycinekSzyny(string $html): string
    {
        return $this->wycinekPoKlasie($html, 'div', self::KLASA_SZYNY);
    }

    /**
     * Wycięcie JEDNEGO elementu po klasie — bo karta, szyna, stopka
     * i nawigacja zawierają te same słowa i te same liczby.
     */
    private function wycinekPoKlasie(string $html, string $znacznik, string $klasa): string
    {
        $wezel = $this->wezelPoKlasie($html, $znacznik, $klasa);

        $this->assertInstanceOf(
            DOMElement::class,
            $wezel,
            "Nie znalazłem elementu <{$znacznik}> z klasą „{$klasa}” — bez niego ".
            'każda asercja niżej byłaby asercją na pustym napisie.',
        );

        return (string) $wezel->ownerDocument?->saveHTML($wezel);
    }

    private function wezelPoKlasie(string $html, string $znacznik, string $klasa): ?DOMElement
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dokument);
        $wynik = $xpath->query(
            sprintf(
                '//%s[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
                $znacznik,
                $klasa,
            ),
        );

        $wezel = $wynik === false ? null : $wynik->item(0);

        return $wezel instanceof DOMElement ? $wezel : null;
    }

    private function blokSzynyLezyWSzynie(string $html): bool
    {
        $wezel = $this->wezelPoKlasie($html, 'div', self::KLASA_SZYNY);

        for ($rodzic = $wezel?->parentNode; $rodzic !== null; $rodzic = $rodzic->parentNode) {
            if ($rodzic instanceof DOMElement
                && $rodzic->tagName === 'aside'
                && str_contains($rodzic->getAttribute('class'), 'app-rail')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Treść zapytania `@media (min-width: 80rem)` z arkusza profilu.
     *
     * Wycinamy je, zamiast szukać reguł w całym pliku: reguła stojąca POZA
     * tym zapytaniem chowałaby liczby w karcie także na telefonie.
     */
    private function trescZapytania80rem(string $css): string
    {
        $poczatek = mb_strpos($css, '@media (min-width: 80rem)');

        $this->assertNotFalse(
            $poczatek,
            'W arkuszu profilu nie ma zapytania `@media (min-width: 80rem)` — '
            .'to jest próg, na którym w ogóle powstaje trzecia kolumna.',
        );

        // Zapytanie kończy się na pierwszym zamknięciu na jego poziomie
        // zagnieżdżenia; liczymy klamry zamiast zgadywać po wcięciu.
        $glebokosc = 0;
        $dlugosc = mb_strlen($css);

        for ($i = (int) $poczatek; $i < $dlugosc; $i++) {
            $znak = mb_substr($css, $i, 1);

            if ($znak === '{') {
                $glebokosc++;
            }

            if ($znak === '}') {
                $glebokosc--;

                if ($glebokosc === 0) {
                    return mb_substr($css, (int) $poczatek, $i - (int) $poczatek + 1);
                }
            }
        }

        return mb_substr($css, (int) $poczatek);
    }

    /** Liczba zapytań agregujących (`count(*)`) wykonanych przy jednym wejściu. */
    private function agregatyNaStronie(callable $akcja): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $akcja();

        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // `select count(*) as "aggregate"` NA POCZĄTKU zapytania, a nie
        // „count(*) gdziekolwiek": to drugie łapie też zwykły SELECT wpisów,
        // który ma policzone komentarze w podzapytaniu — czyli zapytanie,
        // które z licznikami profilu nie ma nic wspólnego.
        return count(array_filter(
            $zapytania,
            fn (array $zapytanie): bool => str_starts_with(
                mb_strtolower(ltrim((string) $zapytanie['query'])),
                'select count(*) as "aggregate"',
            ),
        ));
    }
}
