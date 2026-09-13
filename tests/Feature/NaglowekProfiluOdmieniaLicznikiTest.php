<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Support\Odmiana;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Nagłówek profilu `/@nazwa`: odmiana liczników i równy układ karty.
 *
 * ZGŁOSZENIE WŁAŚCICIELA, DOSŁOWNIE
 * „To jest takie nieczytelne, niesymetryczne, trzeba poprawić — zwłaszcza te
 * »1 wpisów« (powinno być 1 wpis, potem 2 wpisy… 5 wpisów itp., wszystko się
 * odmieniać)".
 *
 * CO TU JEST SPRAWDZANE I DLACZEGO WŁAŚNIE TO
 *  1. ODMIANA KAŻDEGO z pięciu liczników dla 0, 1, 2, 5, 22 i 101. „22" jest
 *     na tej liście nieprzypadkowo: to jedyna liczba, przy której naiwna
 *     reguła „końcówka 2, 3, 4 → forma mnoga" daje prawidłowy wynik, a jej
 *     bliska sąsiadka 12 już nie. Bez pary 12/22 test przechodziłby również
 *     dla implementacji dwustanowej.
 *  2. BRAK UKOŚNIKA RODZAJOWEGO w gotowej stronie. Wcześniej stało tu
 *     „razy ugotowała/ugotował" — konstrukcja, której COPY_STYLE.md §2
 *     zakazuje i której nie da się przeczytać na głos.
 *  3. WŁASNY PROFIL POKAZUJE AKCJE, CUDZY NIE. Ta karta jest JEDNYM widokiem
 *     dla obu przypadków, więc każda zmiana jej układu musi być sprawdzona
 *     z obu stron — przesunięcie węzła w DOM nie może odsłonić akcji
 *     właściciela obcej osobie.
 *  4. UKŁAD LICZNIKÓW W ARKUSZU: siatka o równych kolumnach, `min-width: 0`
 *     na komórce i próg w `min(...)`. To są dokładnie te trzy rzeczy,
 *     których brak położył wczoraj stopkę i prawą szynę przy 320 px
 *     i czcionce przeglądarki 200% (twardy próg w `rem` rośnie ze skalą
 *     tekstu, a okno nie). Automat `scripts/dostepnosc.mjs` mierzy to
 *     w przeglądarce, ale chodzi osobno i wolno — ten test ma oblać w tej
 *     samej sekundzie, w której ktoś wpisze tu `minmax(11rem, 1fr)`.
 *  5. AWATAR MA REGUŁĘ ROZMIARU W ARKUSZU. `x-avatar` dostaje liczbę
 *     i wypisuje ją w `data-rozmiar`, a rozmiar spoza listy w `app.css`
 *     zostaje przy domyślnych 48 px — czyli powiększenie awatara „działa"
 *     w widoku i nie działa na ekranie. W repozytorium są już dwa takie
 *     ciche przypadki (`:size="64"` i `:size="32"`), więc to nie jest
 *     obawa teoretyczna.
 *  6. LICZBA ZAPYTAŃ NA WŁASNYM PROFILU nie rośnie z liczbą wpisów. Istniejący
 *     `MiniaturyBezWachlarzaZapytanTest` mierzy profil OGLĄDANY JAKO GOŚĆ;
 *     widok właściciela ma inną gałąź (`$isOwner`) i nie był mierzony.
 *
 * Każdy test ma asercję kontrolną, żeby nie mógł przejść na pusto.
 */
class NaglowekProfiluOdmieniaLicznikiTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /**
     * Oczekiwane podpisy dla każdego licznika i każdej liczby z listy.
     *
     * Tablica jest wypisana WPROST, a nie policzona przez `Odmiana` —
     * test, który liczy oczekiwanie tą samą metodą, co sprawdzany kod, nie
     * sprawdza niczego poza tym, że metoda jest deterministyczna.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function liczniki(): array
    {
        return [
            'wpisy' => ['wpisy', [
                0 => 'wpisów',
                1 => 'wpis',
                2 => 'wpisy',
                5 => 'wpisów',
                12 => 'wpisów',
                22 => 'wpisy',
                101 => 'wpisów',
            ]],
            'przepisy' => ['przepisy', [
                0 => 'przepisów',
                1 => 'przepis',
                2 => 'przepisy',
                5 => 'przepisów',
                12 => 'przepisów',
                22 => 'przepisy',
                101 => 'przepisów',
            ]],
            'ugotowania' => ['ugotowania', [
                0 => 'razy „Ugotowałem”',
                1 => 'raz „Ugotowałem”',
                2 => 'razy „Ugotowałem”',
                5 => 'razy „Ugotowałem”',
                12 => 'razy „Ugotowałem”',
                22 => 'razy „Ugotowałem”',
                101 => 'razy „Ugotowałem”',
            ]],
            'obserwujacy' => ['obserwujacy', [
                0 => 'obserwujących',
                1 => 'obserwujący',
                2 => 'obserwujących',
                5 => 'obserwujących',
                12 => 'obserwujących',
                22 => 'obserwujących',
                101 => 'obserwujących',
            ]],
            'obserwowani' => ['obserwowani', [
                0 => 'obserwowanych',
                1 => 'obserwowany',
                2 => 'obserwowanych',
                5 => 'obserwowanych',
                12 => 'obserwowanych',
                22 => 'obserwowanych',
                101 => 'obserwowanych',
            ]],
        ];
    }

    /**
     * @param  array<int, string>  $oczekiwanePodpisy  liczba => podpis bez liczby
     */
    #[DataProvider('liczniki')]
    public function test_licznik_odmienia_sie_dla_zera_jednego_dwoch_pieciu_dwunastu_dwudziestu_dwoch_i_stu_jednego(
        string $rodzaj,
        array $oczekiwanePodpisy,
    ): void {
        foreach ($oczekiwanePodpisy as $ile => $podpis) {
            $html = Blade::render(
                '<x-licznik-profilu :rodzaj="$rodzaj" :ile="$ile" />',
                ['rodzaj' => $rodzaj, 'ile' => $ile],
            );

            // Asercja kontrolna: składnik naprawdę się wyrenderował, a nie
            // oddał pustego napisu, przy którym każda asercja niżej padałaby
            // z niezrozumiałym powodem.
            $this->assertStringContainsString('class="stat-value"', $html);

            // Liczba i podpis stoją obok siebie, rozdzielone ZWYKŁĄ SPACJĄ
            // w HTML-u, a nie samym `gap` z CSS: czytnik ekranu i kopiowanie
            // tekstu widzą wyłącznie treść dokumentu, więc bez tej spacji
            // człowiek z czytnikiem usłyszałby „22wpisy".
            $this->assertStringContainsString(
                '<span class="stat-value">'.$ile.'</span> <span class="stat-label">'.$podpis.'</span>',
                $html,
                sprintf('Licznik „%s" dla %d ma dać podpis „%s".', $rodzaj, $ile, $podpis),
            );
        }
    }

    public function test_nieznany_rodzaj_licznika_konczy_sie_wyjatkiem_a_nie_pustym_podpisem(): void
    {
        // Blade owija każdy wyjątek z widoku w `ViewException`, więc sprawdzamy
        // TREŚĆ komunikatu — to ona ma powiedzieć, co jest nie tak i jakie
        // nazwy liczników są znane.
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Nieznany licznik profilu: lajki');

        Blade::render('<x-licznik-profilu rodzaj="lajki" :ile="7" />');
    }

    public function test_naglowek_odmienia_liczniki_na_zywej_stronie(): void
    {
        $autor = $this->user('odmiana_zywa', ['display_name' => 'Odmiana Testowa']);

        // 1 wpis, 2 przepisy, 1 wykonanie, 0 obserwujących, 0 obserwowanych —
        // trzy różne formy naraz, w jednej odpowiedzi HTTP.
        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => 'published',
            'published_at' => now(),
            'body' => 'Jeden wpis na profilu',
        ]);

        $przepisy = collect(['Pierwsza zupa', 'Druga zupa'])->map(fn (string $tytul) => Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
        ]));

        CookedEvent::factory()->create([
            'recipe_id' => $przepisy->first()->getKey(),
            'user_id' => $autor->getKey(),
        ]);

        $odpowiedz = $this->get(route('profile.show', 'odmiana_zywa'))->assertOk();

        // Asercja kontrolna — bez niej test przeszedłby także wtedy, gdyby
        // strona w ogóle nie pokazała tego profilu.
        $odpowiedz->assertSee('Odmiana Testowa');

        $odpowiedz->assertSee('<span class="stat-value">1</span> <span class="stat-label">wpis</span>', false);
        $odpowiedz->assertSee('<span class="stat-value">2</span> <span class="stat-label">przepisy</span>', false);
        $odpowiedz->assertSee('<span class="stat-value">1</span> <span class="stat-label">raz „Ugotowałem”</span>', false);
        $odpowiedz->assertSee('<span class="stat-value">0</span> <span class="stat-label">obserwujących</span>', false);
        $odpowiedz->assertSee('<span class="stat-value">0</span> <span class="stat-label">obserwowanych</span>', false);

        // Stare, nieodmienione formy nie mają prawa zostać nigdzie w karcie.
        $odpowiedz->assertDontSee('1 wpisów');
        $odpowiedz->assertDontSee('2 przepisów');
    }

    public function test_karta_profilu_nie_zaklada_rodzaju_ukosnikiem(): void
    {
        $autor = $this->user('bez_ukosnika', ['display_name' => 'Bez Ukośnika']);

        Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Zupa bez ukośnika',
            'slug' => 'zupa-bez-ukosnika-'.Str::lower(Str::random(6)),
        ]);

        $html = $this->get(route('profile.show', 'bez_ukosnika'))->assertOk()->getContent();

        // Asercja kontrolna.
        $this->assertStringContainsString('Bez Ukośnika', (string) $html);

        // Ten sam wzorzec, którym PR #235 przeczesuje cały serwis:
        // „ugotowała/ugotował", „napisała/napisał", „dostałeś/aś".
        $this->assertDoesNotMatchRegularExpression(
            '/\p{L}+(?:ła|łeś|ał|eś)\s*\/\s*\p{L}+/u',
            (string) $html,
            'W karcie profilu wróciła forma z ukośnikiem rodzajowym '
            .'(COPY_STYLE.md §2 — ukośnika nie da się przeczytać na głos).',
        );
    }

    public function test_wlasny_profil_pokazuje_akcje_a_cudzy_ich_nie_pokazuje(): void
    {
        $wlasciciel = $this->user('wlasny_naglowek', ['display_name' => 'Właścicielka Karty']);
        $obcy = $this->user('obcy_naglowek');

        $swoj = $this->actingAs($wlasciciel)
            ->get(route('profile.show', 'wlasny_naglowek'))
            ->assertOk();

        // Asercja kontrolna.
        $swoj->assertSee('Właścicielka Karty');

        // W GŁÓWCE PROFILU, NIE W CAŁYM DOKUMENCIE (pułapka 1): ten sam napis
        // niesie skrót w prawej szynie, więc asercja na całej odpowiedzi
        // przechodziła także po skasowaniu podpisu pod awatarem — czyli tego
        // jedynego, czego ten plik pilnuje.
        $this->assertStringContainsString(
            'Dodaj zdjęcie profilowe',
            $this->trescEkranu((string) $swoj->getContent()),
        );
        $swoj->assertSee('Zmień swój profil');
        // Awatar właściciela jest odnośnikiem do ekranu zdjęcia (D-054),
        // a podpis pod nim wygląda teraz jak akcja, nie jak podpis zdjęcia.
        $swoj->assertSee('class="btn btn-secondary profil-awatar-zmiana-akcja"', false);

        $cudzy = $this->actingAs($obcy)
            ->get(route('profile.show', 'wlasny_naglowek'))
            ->assertOk();

        // Asercja kontrolna: obcy widzi tę samą kartę, tylko bez akcji.
        $cudzy->assertSee('Właścicielka Karty');
        $cudzy->assertSee('<span class="stat-value">0</span> <span class="stat-label">wpisów</span>', false);

        $cudzy->assertDontSee('Dodaj zdjęcie profilowe');
        $cudzy->assertDontSee('Zmień zdjęcie profilowe');
        $cudzy->assertDontSee('Zmień swój profil');
        $cudzy->assertDontSee('profil-awatar-zmiana');
        // Zamiast akcji właściciela — akcja obcego.
        $cudzy->assertSee('Obserwuj');
    }

    public function test_awatar_naglowka_ma_170_px_i_regule_w_arkuszu(): void
    {
        $this->user('awatar_naglowek', ['display_name' => 'Awatar Testowy']);
        $this->get(route('profile.show', 'awatar_naglowek'))->assertOk()->assertSee('Awatar Testowy')->assertSee('data-rozmiar="170"', false);
        $css = (string) file_get_contents(resource_path('css/marka-profil.css'));
        $this->assertMatchesRegularExpression('/\.avatar\[data-rozmiar="170"\]\s*\{[^}]*width:\s*170px/', $css);
    }

    public function test_liczniki_stoja_w_siatce_o_rownych_kolumnach(): void
    {
        $css = (string) file_get_contents(resource_path('css/ekran-profilu.css'));
        $marka = (string) file_get_contents(resource_path('css/marka-profil.css'));
        $this->assertStringContainsString('repeat(auto-fit, minmax(min(100%, 9rem), 1fr))', $marka);

        // Asercja kontrolna: czytamy naprawdę arkusz profilu.
        $this->assertStringContainsString('.profil-glowka-tresc', $css);

        $this->assertMatchesRegularExpression(
            '/\.profil-liczniki\s*\{[^}]*display:\s*grid/',
            $css,
            'Liczniki mają stać w siatce. Kontener `flex-wrap` układał je '
            .'„3 + 2" o różnych szerokościach — to jest usterka z tego zgłoszenia.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/@media[^{]*\{\s*\.profil-liczniki/',
            $css,
            'Próg `@media` przy licznikach mierzy szerokość OKNA, a nie tej '
            .'karty — a karta ma obok siebie prawą szynę i jest od okna '
            .'znacznie węższa. Właśnie to złamało pierwszą wersję tej poprawki.',
        );

        $this->assertMatchesRegularExpression(
            '/\.profil-licznik\s*\{[^}]*min-width:\s*0/',
            $css,
            'Bez `min-width: 0` najdłuższy podpis dyktuje szerokość kolumny '
            .'i wypycha stronę w bok przy 320 px (ta sama usterka co w stopce).',
        );

        $this->assertMatchesRegularExpression(
            '/\.profil-licznik-pole\s*\{[^}]*flex-wrap:\s*wrap/',
            $css,
            'Podpis musi mieć prawo zejść pod liczbę przy dużym tekście.',
        );

        $this->assertMatchesRegularExpression(
            '/\.profil-licznik-pole\s*\{[^}]*min-height:\s*3rem/',
            $css,
            'Dwa liczniki są odnośnikami, więc cel kliknięcia ma 48 px '
            .'(UX_50_PLUS.md) — i w `rem`, żeby rósł razem z tekstem.',
        );

        // TWARDY PRÓG W `rem` W `minmax()` JEST ZAKAZANY W TYM PLIKU.
        // `minmax(11rem, 1fr)` w stopce dało przy czcionce 200% kolumnę
        // 352 px w oknie 360 px i poziome przewijanie na 24 ekranach naraz.
        // Jedyna dopuszczalna postać to `minmax(min(Xrem, 100%), ...)`.
        $this->assertDoesNotMatchRegularExpression(
            '/minmax\(\s*[\d.]+rem/',
            $css,
            'Twardy próg w `rem` wewnątrz `minmax()`: przy czcionce przeglądarki '
            .'200% rośnie razem z tekstem, a okno nie. Użyj `min(Xrem, 100%)`.',
        );
    }

    public function test_wlasny_profil_nie_ma_wachlarza_zapytan_na_liczniki(): void
    {
        $autor = $this->user('wachlarz_wlasny');

        $this->wpisy($autor->getKey(), 2);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($autor)->get(route('profile.show', 'wachlarz_wlasny'))->assertOk(),
        );

        Post::query()->delete();

        $this->wpisy($autor->getKey(), 30);
        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($autor)->get(route('profile.show', 'wachlarz_wlasny'))->assertOk(),
        );

        // Asercja kontrolna: druga próba naprawdę miała trzydzieści wpisów.
        $this->assertSame(30, Post::query()->count());

        fwrite(STDERR, sprintf(
            "\n[nagłówek /@ja] malo (2 wpisy): %d zapytan, duzo (30 wpisow): %d zapytan\n",
            $malo,
            $duzo,
        ));

        $this->assertSame(
            $malo,
            $duzo,
            "Własny profil: {$malo} zapytań przy 2 wpisach, {$duzo} przy 30 — liczba zapytań ".
            'rośnie z liczbą treści.',
        );
    }

    /** Odmiana jest liczona przez wspólną klasę, a nie przez drugą kopię reguły. */
    public function test_skladnik_liczy_odmiane_klasa_odmiana(): void
    {
        // Gdyby ktoś wpisał w składnik własne `$ile === 1 ? ... : ...`,
        // nastki (12, 13, 14) znów zaczęłyby brać formę mnogą.
        $this->assertSame('wpisów', Odmiana::rzeczownik(12, 'wpis', 'wpisy', 'wpisów'));

        $html = Blade::render('<x-licznik-profilu rodzaj="wpisy" :ile="12" />');

        $this->assertStringContainsString('12</span> <span class="stat-label">wpisów', $html);
    }

    private function wpisy(string|int $autorId, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            Post::factory()->create([
                'author_id' => $autorId,
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => 'published',
                'published_at' => now()->subMinutes($i),
            ]);
        }
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;

        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }
}
