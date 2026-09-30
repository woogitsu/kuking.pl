<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\TrescDigestu;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Users\Actions\RequestEmailChange;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\PotwierdzenieNowegoAdresu;
use App\Notifications\ProbaWejsciaKontemFacebooka;
use App\Notifications\UstawienieHaslaZamiastLinku;
use App\Notifications\UstawienieNowegoHasla;
use App\Notifications\ZaproszenieDoZalozeniaKonta;
use App\Notifications\ZgloszonaZmianaAdresu;
use App\Support\Forma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification as Powiadomienia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/**
 * Teksty w formie wybranej przez osobę (D-332, #1753).
 *
 * TRZY RZECZY, KAŻDA MIERZONA OSOBNO
 *  1. `Forma::dla()` zwraca wariant według `profiles.form_of_address`,
 *     a przy braku wyboru, braku profilu i nieznanej wartości — neutralny.
 *  2. KAŻDE wywołanie helpera w widokach i w `app/` ma trzy warianty,
 *     a wariant neutralny jest bez rodzaju. Tylko ten test pilnuje
 *     neutralnego wariantu: `TekstyNiePrzypisujaPlciTest` wycina argumenty
 *     helpera ze skanu w całości, więc „neutralny” wariant z rodzajem
 *     przeszedłby tamtędy bez słowa. Hasło „ugotowałeś” NIE jest tu wyjątkiem:
 *     wariant neutralny czyta zalogowana osoba bez wybranej formy, a D-332
 *     obiecuje jej teksty bez rodzaju. „Ugotowałem” zostaje wyjątkiem, bo to
 *     nazwa funkcji (D-332 pkt 4).
 *  3. Przełączone ekrany naprawdę pokazują formę: bez wyboru — bez rodzaju
 *     (wyrenderowany tekst przechodzi skan `WzorceRodzaju`), przy wyborze —
 *     właściwy wariant.
 *
 * CZEGO NIE SPRAWDZA: wyglądu przy 320 px i skali tekstu 150%. Najdłuższe
 * nowe słowa („ugotowałaś”, „Ugotowałam”) mają tyle liter co dotychczasowe
 * („ugotowałeś”, „Ugotowałem”), ale pomiaru w przeglądarce tu nie ma.
 */
final class FormaTekstyTest extends TestCase
{
    use RefreshDatabase;

    /** Poniżej tej liczby wywołań skan czyta nie ten katalog (PULAPKI_TESTOW.md §2). */
    private const MIN_WYWOLAN = 8;

    // -----------------------------------------------------------------
    // 1. Helper
    // -----------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function formy(): array
    {
        return [
            'żeńska' => [Profile::FORM_FEMININE, 'ugotowałaś'],
            'męska' => [Profile::FORM_MASCULINE, 'ugotowałeś'],
            'neutralna (NULL)' => [null, 'gotujesz'],
        ];
    }

    #[DataProvider('formy')]
    public function test_helper_wybiera_wariant_z_profilu(?string $forma, string $oczekiwany): void
    {
        $basia = $this->userZForma('basia', $forma);

        $this->assertSame($oczekiwany, Forma::dla($basia, 'ugotowałaś', 'ugotowałeś', 'gotujesz'));
        $this->assertSame($oczekiwany, Forma::dla($basia->profile, 'ugotowałaś', 'ugotowałeś', 'gotujesz'));
    }

    public function test_bez_osoby_bez_profilu_i_przy_obcej_wartosci_jest_neutralny(): void
    {
        $this->assertSame('gotujesz', Forma::dla(null, 'ugotowałaś', 'ugotowałeś', 'gotujesz'));
        $this->assertSame('gotujesz', Forma::dla((new User)->setRelation('profile', null), 'ugotowałaś', 'ugotowałeś', 'gotujesz'));
        $this->assertSame('gotujesz', Forma::dla(new Profile(['form_of_address' => 'kobieta']), 'ugotowałaś', 'ugotowałeś', 'gotujesz'));
    }

    // -----------------------------------------------------------------
    // 2. Każde wywołanie w repozytorium
    // -----------------------------------------------------------------

    public function test_kazde_wywolanie_ma_trzy_warianty_a_neutralny_jest_bez_rodzaju(): void
    {
        $wywolania = 0;
        $bledy = [];

        foreach ($this->zrodla() as $plik => $tresc) {
            foreach (WzorceRodzaju::wywolaniaFormy($tresc) as $wywolanie) {
                $wywolania++;
                $gdzie = $plik.':'.$wywolanie['linia'];
                $argumenty = $wywolanie['argumenty'];

                if (count($argumenty) !== 4) {
                    $bledy[] = $gdzie.' — '.count($argumenty).' argumentów zamiast 4 (osoba, żeńska, męska, neutralna)';

                    continue;
                }

                foreach ([1 => 'żeński', 2 => 'męski', 3 => 'neutralny'] as $i => $nazwa) {
                    if (trim($argumenty[$i], " \t\n'\"") === '') {
                        $bledy[] = $gdzie.' — pusty wariant '.$nazwa;
                    }
                }

                $neutralny = $argumenty[3];

                foreach (WzorceRodzaju::trafienia($neutralny) as $trafienie) {
                    $bledy[] = $gdzie.' — wariant neutralny z rodzajem: '.$trafienie;
                }

                if (preg_match('/ugotował(?:eś|aś)/iu', $neutralny) === 1) {
                    $bledy[] = $gdzie.' — wariant neutralny z hasłem „ugotowałeś”; zalogowany bez formy ma dostać tekst bez rodzaju (D-332)';
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_WYWOLAN,
            $wywolania,
            'Znaleziono tylko '.$wywolania.' wywołań Forma::dla(). Skan czyta nie ten katalog?',
        );

        $this->assertSame([], $bledy, "Wywołania helpera formy łamią D-332:\n".implode("\n", $bledy));
    }

    // -----------------------------------------------------------------
    // 3. Ekrany
    // -----------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function koniecOnboardingu(): array
    {
        return [
            'żeńska' => [Profile::FORM_FEMININE, 'Możesz od razu pokazać, co dziś ugotowałaś'],
            'męska' => [Profile::FORM_MASCULINE, 'Możesz od razu pokazać, co dziś ugotowałeś'],
            'neutralna' => [null, 'Możesz od razu pokazać, co dziś gotujesz'],
        ];
    }

    #[DataProvider('koniecOnboardingu')]
    public function test_koniec_onboardingu_mowi_w_formie_osoby(?string $forma, string $zdanie): void
    {
        $basia = $this->userZForma('basia', $forma);

        $html = $this->actingAs($basia)->get(route('onboarding.done'))->assertOk()->getContent();

        $this->assertStringContainsString($zdanie, $this->tekst($html));
        $this->bezRodzajuGdyNeutralna($forma, $html);
    }

    #[DataProvider('formy')]
    public function test_pusty_stan_odkrywania_mowi_w_formie_osoby(?string $forma, string $slowo): void
    {
        $basia = $this->userZForma('basia', $forma);

        $html = $this->actingAs($basia)->get(route('discover'))->assertOk()->getContent();

        $this->assertStringContainsString('Zacznij od zdjęcia tego, co dziś '.$slowo.'.', $this->tekst($html));
        $this->bezRodzajuGdyNeutralna($forma, $html);
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string}> */
    public static function przyciskUgotowalem(): array
    {
        return [
            'żeńska' => [Profile::FORM_FEMININE, 'Ugotowałam', 'Ugotowałem'],
            'męska' => [Profile::FORM_MASCULINE, 'Ugotowałem', 'Ugotowałam'],
            'neutralna' => [null, 'Ugotowałem', 'Ugotowałam'],
        ];
    }

    #[DataProvider('przyciskUgotowalem')]
    public function test_przycisk_na_ostatnim_kroku_gotowania_w_formie_osoby(?string $forma, string $jest, string $niema): void
    {
        $recipe = $this->przepisZJednymKrokiem($this->user('autorka'));
        $basia = $this->userZForma('basia', $forma);

        $html = $this->actingAs($basia)
            ->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~data-minutniki-koniec>'.$jest.'</a>~u', $html);
        $this->assertDoesNotMatchRegularExpression('~data-minutniki-koniec>'.$niema.'</a>~u', $html);
        $this->bezRodzajuGdyNeutralna($forma, $html);
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function ekranKomusWyszlo(): array
    {
        return [
            'żeńska' => [Profile::FORM_FEMININE, 'Halina ugotowała Twój przepis „Rosół”'],
            'męska' => [Profile::FORM_MASCULINE, 'Halina ugotował Twój przepis „Rosół”'],
            'neutralna' => [null, 'Halina — ugotowane z Twojego przepisu „Rosół”'],
        ];
    }

    /**
     * O kucharzu mówimy w JEGO formie, nie w formie autora, który czyta.
     * Autorowi celowo dajemy formę odwrotną — gdyby helper dostał nie tę
     * osobę, test to zobaczy.
     */
    #[DataProvider('ekranKomusWyszlo')]
    public function test_ekran_komus_wyszlo_mowi_o_kucharzu_w_jego_formie(?string $forma, string $naglowek): void
    {
        $autor = $this->userZForma('autorka', $forma === Profile::FORM_FEMININE ? Profile::FORM_MASCULINE : Profile::FORM_FEMININE);
        $kucharz = $this->userZForma('kucharz', $forma, 'Halina');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $html = $this->actingAs($autor)->get(route('cooked.celebrate', $event))->assertOk()->getContent();

        $this->assertStringContainsString($naglowek, $this->tekst($html));
        $this->bezRodzajuGdyNeutralna($forma, $html);
    }

    /**
     * Przycisk „Ugotowałem” na stronie przepisu i tytuł formularza wykonania
     * (D-332 pkt 4): napis zmienia się tylko u osoby z formą żeńską.
     */
    #[DataProvider('przyciskUgotowalem')]
    public function test_strona_przepisu_i_formularz_wykonania_w_formie_osoby(?string $forma, string $jest, string $niema): void
    {
        $recipe = Recipe::factory()->create(['author_id' => $this->user('autorka')->getKey(), 'title' => 'Rosół']);
        $basia = $this->userZForma('basia', $forma);

        $przepis = $this->actingAs($basia)->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~href="[^"]*/ugotowalem[^"]*">'.$jest.'</a>~u', $przepis);
        $this->assertDoesNotMatchRegularExpression('~href="[^"]*/ugotowalem[^"]*">'.$niema.'</a>~u', $przepis);

        $formularz = $this->actingAs($basia)->get(route('cooked.create', $recipe->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<h1>'.$jest.': Rosół</h1>', $formularz);
        $this->assertStringNotContainsString('<h1>'.$niema.': Rosół</h1>', $formularz);
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function powiadomienieKomusWyszlo(): array
    {
        return [
            'żeńska' => [Profile::FORM_FEMININE, 'Halina ugotowała Twój przepis'],
            'męska' => [Profile::FORM_MASCULINE, 'Halina ugotował Twój przepis'],
            'neutralna' => [null, 'Halina — ugotowane z Twojego przepisu'],
        ];
    }

    /** Lista powiadomień mówi o kucharzu w JEGO formie, nie w formie czytającego autora. */
    #[DataProvider('powiadomienieKomusWyszlo')]
    public function test_lista_powiadomien_mowi_o_kucharzu_w_jego_formie(?string $forma, string $zdanie): void
    {
        $autor = $this->userZForma('autorka', $forma === Profile::FORM_FEMININE ? Profile::FORM_MASCULINE : Profile::FORM_FEMININE);
        $kucharz = $this->userZForma('kucharz', $forma, 'Halina');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);
        app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $html = $this->actingAs($autor)->get(route('notifications.index'))->assertOk()->getContent();

        $this->assertStringContainsString($zdanie, $this->tekst($html));
        $this->bezRodzajuGdyNeutralna($forma, $html);
    }

    #[DataProvider('formy')]
    public function test_powiadomienie_powitalne_mowi_w_formie_osoby(?string $forma, string $slowo): void
    {
        $basia = $this->userZForma('basia', $forma);
        Notification::create([
            'user_id' => $basia->getKey(),
            'type' => Notification::TYPE_WELCOME,
            'data' => ['display_name' => 'Basia'],
        ]);

        $html = $this->actingAs($basia)->get(route('notifications.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Zacznij od zdjęcia tego, co dziś '.$slowo.'.', $this->tekst($html));
        $this->bezRodzajuGdyNeutralna($forma, $html);
    }

    #[DataProvider('formy')]
    public function test_stopka_podsumowania_tygodnia_w_formie_adresata(?string $forma, string $slowo): void
    {
        $basia = $this->userZForma('basia', $forma);

        $tekst = view('mail.podsumowanie-tygodnia-tekst', [
            'tresc' => TrescDigestu::pusta($basia),
            'imie' => 'Basia',
            'gospodarz' => 'Ula',
            'wypisz' => 'https://kuking.test/podsumowanie/wypisz/test',
        ])->render();

        $this->assertStringContainsString('Kuking.pl - pokaż, co dziś '.$slowo.'.', $tekst);
    }

    /**
     * Stopki listów do ISTNIEJĄCEGO konta (#1753, etap 3).
     *
     * Do tej pory miały stałe „ugotowałeś”, bo stał za nim wyjątek „hasło
     * główne”. D-332 zostawia hasło tylko tam, gdzie czytelnika nie znamy
     * (przed założeniem konta). Te listy idą do konta, które znamy, więc
     * osoba bez wybranej formy ma dostać stopkę bez rodzaju — a ta, która
     * wybrała, swoją formę. Zaproszenie do założenia konta zostaje przy haśle
     * (konta jeszcze nie ma) — pilnuje tego ostatni test niżej.
     */
    #[DataProvider('formy')]
    public function test_stopki_listow_do_konta_w_formie_adresata(?string $forma, string $slowo): void
    {
        $basia = $this->userZForma('basia', $forma);

        $listy = [
            'link-do-logowania' => new LinkDoLogowania('token-testowy'),
            'nowe-haslo' => new UstawienieNowegoHasla('token-testowy'),
            'haslo-zamiast-linku' => new UstawienieHaslaZamiastLinku('token-testowy'),
            'potwierdz-adres' => new PotwierdzenieAdresu,
            'proba-wejscia-kontem-facebooka' => new ProbaWejsciaKontemFacebooka,
        ];

        foreach ($listy as $nazwa => $powiadomienie) {
            $html = (string) $powiadomienie->toMail($basia)->render();

            $this->assertStringContainsString('pokaż, co dziś '.$slowo.'.', $this->tekst($html), 'Stopka listu '.$nazwa);
            $this->bezRodzajuGdyNeutralna($forma, $html);
        }
    }

    /**
     * Listy o zmianie adresu idą „na adres” (`Notification::route`), bez
     * obiektu `User` — forma musi przejść przez konstruktor. Sprawdzamy całą
     * drogę: akcja → powiadomienie → wyrenderowana stopka.
     */
    #[DataProvider('formy')]
    public function test_stopki_listow_o_zmianie_adresu_w_formie_adresata(?string $forma, string $slowo): void
    {
        Powiadomienia::fake();
        $basia = $this->userZForma('basia', $forma);

        app(RequestEmailChange::class)->handle($basia, 'nowy-adres-basi@example.com');

        foreach ([PotwierdzenieNowegoAdresu::class, ZgloszonaZmianaAdresu::class] as $klasa) {
            $renderowane = null;
            Powiadomienia::assertSentOnDemand($klasa, function (object $powiadomienie, array $kanaly, object $odbiorca) use (&$renderowane): bool {
                $renderowane = (string) $powiadomienie->toMail($odbiorca)->render();

                return true;
            });

            $this->assertStringContainsString('pokaż, co dziś '.$slowo.'.', $this->tekst((string) $renderowane), 'Stopka listu '.class_basename($klasa));
            $this->bezRodzajuGdyNeutralna($forma, (string) $renderowane);
        }
    }

    /**
     * Zaproszenie do założenia konta idzie do kogoś, kogo nie znamy — tam
     * zostaje hasło główne (D-332: „maile przed założeniem konta”). Gdyby
     * ktoś przepiął i tę stopkę, ten test powie, że to zmiana decyzji.
     */
    public function test_zaproszenie_przed_zalozeniem_konta_zostaje_przy_hasle(): void
    {
        $html = (string) (new ZaproszenieDoZalozeniaKonta('token-testowy'))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringContainsString('pokaż, co dziś ugotowałeś.', $this->tekst($html));
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function userZForma(string $nazwa, ?string $forma, string $imie = 'Testowa osoba'): User
    {
        $user = $this->user($nazwa, ['display_name' => $imie]);
        Profile::query()->whereKey($user->getKey())->update(['form_of_address' => $forma]);

        return $user->refresh();
    }

    /**
     * Przy formie neutralnej CAŁY widoczny tekst ekranu przechodzi skan
     * rodzaju — to jest obietnica D-332 „brak wyboru = teksty bez rodzaju”.
     */
    private function bezRodzajuGdyNeutralna(?string $forma, string $html): void
    {
        if ($forma !== null) {
            return;
        }

        $this->assertSame([], WzorceRodzaju::trafienia($this->bezAdresow($this->tekst($html))), 'Ekran przy formie neutralnej pokazuje rodzaj.');
    }

    /**
     * Adres wypisany w liście tekstem (link zapasowy „jeśli przycisk nie
     * działa”) to nie zdanie do czytelnika. Ma losowy UUID i podpis, więc
     * fragment w rodzaju „…9aba/e5…” czasem pasował do wzorca
     * `ukosnik_koncowka` — test oblewał losowo, bez żadnego rodzaju w treści.
     */
    private function bezAdresow(string $tekst): string
    {
        return (string) preg_replace('~https?://\S+~u', ' ', $tekst);
    }

    /**
     * Regresja losowej czerwieni: adres z „…aba/e5…” nie jest rodzajem,
     * a to samo „aba/e” w zdaniu dalej jest łapane (kontrola z drugiej strony).
     */
    public function test_adres_w_tekscie_listu_nie_jest_brany_za_rodzaj(): void
    {
        $html = '<p>Skopiuj adres: https://kuking.test/potwierdz/0199aba/e5f0?signature=ab</p>';

        $this->bezRodzajuGdyNeutralna(null, $html);
        $this->assertNotSame([], WzorceRodzaju::trafienia($this->bezAdresow($this->tekst('<p>Jesteś gotowa/e na obiad</p>'))));
    }

    /** Widoczny tekst: bez znaczników, skryptów i stylów, białe znaki zwinięte. */
    private function tekst(string $html): string
    {
        $bez = (string) preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html);

        return trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($bez), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function przepisZJednymKrokiem(User $autor): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'ingredient_text' => 'szklanka mąki', 'position' => 0]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Krok numer 1.']);

        return $recipe;
    }

    /**
     * Widoki i `app/` bez komentarzy — wzmianka o helperze w komentarzu
     * nie jest wywołaniem i nie może psuć liczenia argumentów.
     *
     * @return array<string, string> ścieżka => treść
     */
    private function zrodla(): array
    {
        $zrodla = [];

        foreach ([resource_path('views') => '.blade.php', app_path() => '.php'] as $katalog => $koncowka) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog));

            foreach ($iterator as $plik) {
                if (! $plik->isFile() || ! str_ends_with($plik->getFilename(), $koncowka)) {
                    continue;
                }

                $tresc = (string) file_get_contents($plik->getPathname());
                $tresc = $koncowka === '.blade.php'
                    ? (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc)
                    : $this->bezKomentarzyPhp($tresc);

                $zrodla[str_replace(base_path().'/', '', $plik->getPathname())] = $tresc;
            }
        }

        return $zrodla;
    }

    private function bezKomentarzyPhp(string $tresc): string
    {
        $wynik = '';

        foreach (token_get_all($tresc) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $wynik .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $wynik .= is_array($token) ? $token[1] : $token;
        }

        return $wynik;
    }
}
