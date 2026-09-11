<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dziesięć zdań, które mówiły nieprawdę o własnym produkcie (audyt copy
 * z 11.09.2026, `docs/research/audyt-copy-2026-09-11/`).
 *
 * TO NIE JEST TEST TONU. Każde zdanie pilnowane w tym pliku było usterką
 * faktyczną: obiecywało mechanizm, którego nie ma, albo przeczyło innemu
 * zdaniu na tej samej stronie. Ton i styl są osobną listą i czekają
 * na decyzję właściciela (`LISTA_DO_ODHACZENIA.md`).
 *
 * JAK TE TESTY SĄ NAPISANE I DLACZEGO TAK
 *
 * Każda asercja jest ZAWĘŻONA DO ELEMENTU, nie robiona na całym HTML-u.
 * `docs/PULAPKI_TESTOW.md` §1: strona ma nawigację, prawą szynę, stopkę
 * i pusty stan, a każde z nich zawiera te same słowa co element, który
 * sprawdzamy. `assertDontSee('Obserwuj')` na stronie z tablicą dnia
 * przechodziłby albo oblewał z powodów niemających nic wspólnego
 * z przyciskiem, o który chodzi.
 *
 * Każda asercja „czegoś nie ma" ma obok KONTROLĘ DODATNIĄ —
 * `docs/PULAPKI_TESTOW.md` §4: „tego zdania nie ma" przechodzi również
 * wtedy, gdy nie ma całej sekcji, całej strony albo gdy szukany łańcuch ma
 * literówkę. Dlatego przy każdym „nie ma" stoi drugie zdanie, które
 * na tym samym ekranie BYĆ MUSI.
 */
class TekstyMowiaPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tyle znaków wystarczy, żeby `OdzyskanyFormularz` przerwał przepisywanie
     * pól i ustawił `obciete` (jego `LIMIT_ZNAKOW` to 200 000).
     */
    private const PONAD_LIMIT_ZNAKOW = 200_001;

    // =================================================================
    // A1 — strona 419 nie mówi jednocześnie dwóch sprzecznych rzeczy
    // =================================================================

    /**
     * Formularz obcięty: strona MUSI o tym powiedzieć i NIE WOLNO jej
     * obiecywać, że nic nie przepadło.
     *
     * Do 11.09.2026 zdanie „Twój tekst jest na miejscu — nic nie przepadło"
     * stało bez żadnego warunku, a ostrzeżenie o obcięciu niżej pod
     * `$formularz->obciete`. Człowiek, któremu serwer części tekstu NIE
     * zachował, czytał obie rzeczy naraz.
     *
     * To jest ta sama reguła, którą przy limicie 429 pilnuje już
     * `LimitZapytanNieZjadaTekstuTest`: nie obiecujemy, że nic nie przepadło,
     * gdy coś przepadło.
     */
    public function test_419_z_obcietym_formularzem_nie_obiecuje_ze_nic_nie_przepadlo(): void
    {
        // Pierwsze pole MIEŚCI SIĘ w limicie, drugie go przekracza. Gdyby już
        // pierwsze go przekraczało, `OdzyskanyFormularz` przerwałby pętlę przed
        // odłożeniem czegokolwiek, `maCoOdzyskac()` dałoby `false` i strona
        // pokazałaby trzecią, zupełnie inną gałąź — bez odzyskanej treści.
        $html = $this->ekran419([
            'body' => str_repeat('a', self::PONAD_LIMIT_ZNAKOW - 1_000),
            'body_ciag_dalszy' => str_repeat('b', 2_000),
        ]);

        $akapit = $this->pierwszyAkapit($html);

        // Kontrola dodatnia: trafiliśmy w gałąź „obcięte", a nie w pustą
        // stronę ani w 419 bez odzyskanej treści.
        $this->assertStringContainsString(
            'nie wszystko udało się przenieść',
            $akapit,
            'Nie trafiłem w gałąź obciętego formularza — bez niej ten test nie mierzy niczego.',
        );

        $this->assertStringNotContainsString(
            'nic z niego nie przepadło',
            $akapit,
            'Strona 419 obiecuje, że nic nie przepadło, choć część tekstu obcięła.',
        );

        $this->assertStringNotContainsString(
            'nic nie przepadło',
            $akapit,
            'Strona 419 obiecuje, że nic nie przepadło, choć część tekstu obcięła.',
        );
    }

    /**
     * KONTROLA DODATNIA do testu wyżej (`docs/PULAPKI_TESTOW.md` §4).
     *
     * Gdyby obietnica zniknęła ze strony w ogóle, tamten test byłby zielony
     * i nie powiedziałby o tym ani słowa. Przy formularzu, który zmieścił się
     * w całości, zdanie o tekście na miejscu MA stać — bo jest prawdziwe.
     */
    public function test_419_bez_obciecia_dalej_mowi_ze_tekst_jest_na_miejscu(): void
    {
        $html = $this->ekran419([
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty i nie chcę go pisać drugi raz.',
        ]);

        $akapit = $this->pierwszyAkapit($html);

        $this->assertStringContainsString(
            'nic z niego nie przepadło',
            $akapit,
            'Przy formularzu, który zmieścił się w całości, strona 419 ma powiedzieć wprost, że tekst jest na miejscu.',
        );

        $this->assertStringNotContainsString(
            'nie wszystko udało się przenieść',
            $akapit,
            'Strona 419 straszy obcięciem przy formularzu, który wcale nie został obcięty.',
        );
    }

    /**
     * TA SAMA SPRZECZNOŚĆ, ALE NA CAŁEJ STRONIE — NIE TYLKO W PIERWSZYM
     * AKAPICIE.
     *
     * Dwa testy wyżej patrzą wyłącznie na akapit na górze, i to zawężenie
     * miało swój powód, ale też swój koszt: sprzeczność wróciła niżej, pomocą
     * przy polu pliku („Tekst jest bezpieczny, brakuje tylko pliku."),
     * drobniejszym pismem i poza zasięgiem tamtych asercji. Zawężony test
     * przechodził, a strona dalej mówiła człowiekowi obie rzeczy naraz.
     *
     * Ten test patrzy więc na CAŁE `<main>`. Szuka zapewnień o tekście, nie
     * o pliku — bo o pliku wolno i trzeba mówić, że przepadł.
     */
    public function test_419_z_obcietym_formularzem_nie_zapewnia_o_tekscie_NIGDZIE_na_stronie(): void
    {
        // PLIK JEST TU WARUNKIEM, ŻEBY TEST COKOLWIEK MIERZYŁ.
        //
        // Pomoc „Zdjęcia nie da się odzyskać" renderuje pętla po
        // `$formularz->pliki`, a ta lista powstaje z `$request->allFiles()`
        // (`OdzyskanyFormularz::zZadania()`). Bez wysłanego pliku pętla nie
        // wykonuje ani jednego obiegu i sprawdzany fragment strony NIE
        // ISTNIEJE — test przechodzi, nie oglądając niczego. Złapane kontrolą
        // ujemną: po przywróceniu starego, sprzecznego zdania test nadal był
        // zielony.
        $html = $this->ekran419([
            'body' => str_repeat('a', self::PONAD_LIMIT_ZNAKOW - 1_000),
            'body_ciag_dalszy' => str_repeat('b', 2_000),
            'zdjecia' => [UploadedFile::fake()->image('rosol.jpg')],
        ]);

        $strona = $this->xpath($html, '//main');

        // Kontrola dodatnia numer dwa: pomoc przy polu pliku naprawdę stoi na
        // stronie. Bez tej asercji zniknięcie całej pętli przeszłoby niezauważone.
        $this->assertStringContainsString(
            'Zdjęcia nie da się odzyskać',
            $strona,
            'Na stronie nie ma pomocy przy polu pliku — ten test znowu mierzy nieobecny fragment.',
        );

        // Kontrola dodatnia: naprawdę patrzymy na stronę obciętego formularza.
        $this->assertStringContainsString(
            'nie wszystko udało się przenieść',
            $strona,
            'Nie trafiłem w gałąź obciętego formularza — bez tego test nie mierzy niczego.',
        );

        foreach (['nic nie przepadło', 'nic z niego nie przepadło', 'Tekst jest bezpieczny'] as $zapewnienie) {
            $this->assertStringNotContainsString(
                $zapewnienie,
                $strona,
                'Strona 419 zapewnia o tekście („'.$zapewnienie.'"), choć część tekstu obcięła. '
                .'Sprawdzane na całym `<main>`, bo poprzednio ta sprzeczność wróciła w pomocy przy polu pliku.',
            );
        }
    }

    /**
     * KONTROLA DODATNIA: powód obcięcia stoi na stronie DOKŁADNIE RAZ.
     *
     * Rozdzielenie akapitu na dwie gałęzie postawiło zdanie „formularz był
     * wyjątkowo duży i nie wszystko udało się przenieść" na górze, a takie samo
     * stało już nad przyciskiem — i ta sama informacja mówiona dwa razy na
     * jednym ekranie jest zarzutem, który audyt copy z 11.09 podniósł osobno.
     */
    public function test_419_mowi_o_obcieciu_dokladnie_raz(): void
    {
        $html = $this->ekran419([
            'body' => str_repeat('a', self::PONAD_LIMIT_ZNAKOW - 1_000),
            'body_ciag_dalszy' => str_repeat('b', 2_000),
            'zdjecia' => [UploadedFile::fake()->image('rosol.jpg')],
        ]);

        $this->assertSame(
            1,
            substr_count($this->xpath($html, '//main'), 'nie wszystko udało się przenieść'),
            'Powód obcięcia stoi na stronie 419 więcej niż raz — ta sama informacja dwa razy na jednym ekranie.',
        );
    }

    // =================================================================
    // A3 — przycisk gościa mówi, co naprawdę zrobi
    // =================================================================

    /**
     * Gość widział na tablicy dnia przycisk „Obserwuj", który prowadził na
     * rejestrację. Etykieta obiecywała akcję, której kliknięcie nie wykonuje.
     *
     * Wycinek to LISTA OSÓB z tablicy, nie cała strona: słowo „Obserwuj"
     * pada na tej stronie także w innych miejscach (m.in. w zakładkach
     * i w tekstach pomocniczych), więc `assertDontSee` na całym HTML-u
     * byłby testem o czymś innym (`docs/PULAPKI_TESTOW.md` §1).
     */
    public function test_gosc_na_tablicy_dnia_nie_widzi_przycisku_obiecujacego_obserwowanie(): void
    {
        $this->osobaZWpisem();

        $lista = $this->listaOsobZTablicy($this->get(route('discover'))->assertOk()->getContent());

        // Kontrola dodatnia: lista osób naprawdę się wyrenderowała i jest
        // w niej przycisk. Bez tego „nie ma «Obserwuj»" przechodziłoby także
        // przy pustej tablicy.
        $this->assertStringContainsString(
            'Załóż konto, żeby obserwować',
            $lista,
            'Gość nie dostał na tablicy dnia żadnego wyjścia do rejestracji.',
        );

        $this->assertStringNotContainsString(
            '>Obserwuj<',
            $lista,
            'Gość widzi na tablicy dnia przycisk „Obserwuj", który go nie zaobserwuje, tylko odeśle do rejestracji.',
        );
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: zalogowany MA tam mieć „Obserwuj",
     * bo u niego ten przycisk robi dokładnie to, co mówi. Bez tego testu
     * poprzedni przechodziłby także po skasowaniu całego przycisku.
     */
    public function test_zalogowany_na_tablicy_dnia_widzi_przycisk_obserwuj(): void
    {
        $this->osobaZWpisem();

        $lista = $this->listaOsobZTablicy(
            $this->actingAs($this->user('widz'))->get(route('discover'))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(
            '>Obserwuj<',
            $lista,
            'Zalogowany stracił z tablicy dnia przycisk „Obserwuj", który u niego działa.',
        );
    }

    // =================================================================
    // A2 — stopka tablicy nie obiecuje harmonogramu
    // =================================================================

    /**
     * „Jutro będzie tu ktoś inny." obiecywało pewność, której produkt nie daje:
     * tablicę zmienia RĘCZNY wybór gospodarza (`/kuking-na-dzis`), a gdy
     * gospodarz nic nie wybierze, wariant zapasowy sortuje po dacie
     * publikacji — więc w wolny dzień jutro stoją tam te same osoby co dziś.
     *
     * Stopki nie wolno przy tym USUNĄĆ: komentarz w `kuking-board.blade.php`
     * wiąże ją z zakazem rankingów (`AGENTS.md` §12). Dlatego test pilnuje
     * obu stron naraz — że obietnicy nie ma ORAZ że stopka dalej mówi, że to
     * nie jest tabela wyników.
     */
    public function test_stopka_tablicy_dnia_nie_obiecuje_jutra(): void
    {
        $this->osobaZWpisem();

        $stopka = $this->stopkaTablicy($this->get(route('discover'))->assertOk()->getContent());

        $this->assertStringNotContainsString(
            'Jutro',
            $stopka,
            'Stopka tablicy dnia znów obiecuje, że jutro będzie tu ktoś inny — a nic tego nie gwarantuje.',
        );

        $this->assertStringContainsString(
            'rankingu',
            $stopka,
            'Stopka tablicy dnia przestała mówić, że to nie jest tabela wyników. '
            .'To jest jej rola (AGENTS.md §12) — wolno ją zastąpić, nie wolno jej wypatroszyć.',
        );
    }

    // =================================================================
    // A4 — podgląd nie obiecuje cudzych oczu przy przepisie prywatnym
    // =================================================================

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function widocznosciProvider(): array
    {
        return [
            'publiczny' => ['public', 'Podgląd: tak zobaczą to inni', true],
            'dla obserwujących' => ['followers', 'Podgląd: tak zobaczą to osoby, które Cię obserwują', false],
            'prywatny' => ['private', 'Podgląd: tak będziesz widzieć ten przepis', false],
        ];
    }

    /**
     * Nagłówek podglądu zależy od wybranej widoczności.
     *
     * Kreator ją ZNA: wybór stoi w kroku 1, a na podgląd wchodzi się
     * przyciskiem „Dalej", czyli przez `next()`. Zdanie „tak zobaczą to inni"
     * przy przepisie oznaczonym „Tylko ja" było po prostu nieprawdą.
     *
     * Ostatni parametr mówi, czy na tym wariancie zdanie o innych ludziach
     * MA paść — bez tego byłaby to sama asercja negatywna, przechodząca
     * także wtedy, gdyby nagłówek zniknął zupełnie.
     */
    #[DataProvider('widocznosciProvider')]
    public function test_naglowek_podgladu_mowi_prawde_o_tym_kto_to_zobaczy(
        string $widocznosc,
        string $oczekiwanyNaglowek,
        bool $wolnoMowicOInnych,
    ): void {
        $html = Livewire::actingAs($this->user('basia'))
            ->test('recipe-wizard')
            ->set('title', 'Rosół babci Zofii')
            ->set('visibility', $widocznosc)
            ->set('step', 4)
            ->html();

        $naglowek = $this->tekstElementuPoKlasie($html, 'form-section-title');

        $this->assertSame(
            $oczekiwanyNaglowek,
            $naglowek,
            "Podgląd przepisu o widoczności „{$widocznosc}” ma inny nagłówek, niż powinien.",
        );

        if (! $wolnoMowicOInnych) {
            $this->assertStringNotContainsString(
                'tak zobaczą to inni',
                $naglowek,
                "Podgląd przepisu o widoczności „{$widocznosc}” obiecuje cudze oczy, których tam nie będzie.",
            );
        }
    }

    // =================================================================
    // A5 i A7 — pochodzenie przepisu: bez „zostaje w rodzinie"
    //           i bez niemierzonego „najczęściej czytana"
    // =================================================================

    public function test_formularz_na_jednej_stronie_nie_obiecuje_ze_historia_zostaje_w_rodzinie(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('recipes.create.simple'))
            ->assertOk()
            ->getContent();

        // Kontrola dodatnia: to jest ten formularz i sekcja pochodzenia
        // naprawdę się wyrenderowała.
        $this->assertStringContainsString('Historia tego przepisu', $html);

        $this->assertStringNotContainsString(
            'To zostaje w rodzinie',
            $html,
            'Formularz przepisu obiecuje, że historia zostaje w rodzinie — a przepis publiczny widzi każdy.',
        );

        $this->assertStringNotContainsString(
            'najczęściej czytana',
            $html,
            'Formularz przepisu twierdzi, która część przepisu jest czytana najczęściej. Nikt tego nie zmierzył.',
        );
    }

    public function test_kreator_nie_obiecuje_ze_historia_zostaje_w_rodzinie(): void
    {
        $html = Livewire::actingAs($this->user('basia'))
            ->test('recipe-wizard')
            ->html();

        $this->assertStringContainsString('Historia tego przepisu', $html);

        $this->assertStringNotContainsString('To zostaje w rodzinie', $html);
        $this->assertStringNotContainsString('najczęściej czytana', $html);
    }

    // =================================================================
    // A10 — jeden model zapisu szkicu na jednym ekranie
    // =================================================================

    /**
     * ZMIERZONE, ZANIM POWSTAŁ TEN TEST: autozapis w kreatorze jest, ale nie
     * jest bezwarunkowy — `saveDraft()` bez nazwy przepisu nie zapisuje
     * niczego. Dlatego przycisk „Zapisz szkic" ZOSTAJE (to jest ta droga,
     * która działa zawsze), a znika bezwarunkowa obietnica automatu.
     */
    public function test_kreator_nie_obiecuje_automatu_zanim_przepis_ma_nazwe(): void
    {
        $html = Livewire::actingAs($this->user('basia'))
            ->test('recipe-wizard')
            ->html();

        $plakietka = $this->tekstElementuPoKlasie($html, 'autosave-badge');

        $this->assertStringNotContainsString(
            'Szkic zapisuje się sam',
            $plakietka,
            'Plakietka obiecuje automat w chwili, w której `saveDraft()` bez nazwy przepisu nie zapisuje nic.',
        );

        $this->assertStringContainsString(
            'nazwę przepisu',
            $plakietka,
            'Plakietka przestała mówić, od czego zależy zapisanie szkicu.',
        );

        // Kontrola dodatnia dla drugiej połowy rozstrzygnięcia: droga ręczna
        // ma zostać. Gdyby przycisk zniknął, ekran zostałby z samą obietnicą.
        $this->assertStringContainsString(
            '>Zapisz szkic<',
            $html,
            'Zniknął przycisk „Zapisz szkic" — a to jest ta droga zapisu, która działa bez warunków.',
        );
    }

    // =================================================================
    // A6 — adres e-mail nie służy „tylko wtedy, gdy zapomnisz hasła"
    // =================================================================

    public function test_pomoc_przy_adresie_nie_mowi_ze_jest_tylko_na_zapomniane_haslo(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $pomoc = $this->pomocPola($html, 'email');

        $this->assertNotSame('', $pomoc, 'Nie znalazłem pomocy przy polu adresu e-mail na rejestracji.');

        $this->assertStringNotContainsString(
            'tylko wtedy',
            $pomoc,
            'Rejestracja twierdzi, że adres jest potrzebny tylko na zapomniane hasło. '
            .'Adresem można się logować, idzie na niego link wpuszczający na konto (D-056) '
            .'i on potwierdza zmianę samego adresu (#195).',
        );
    }

    // =================================================================
    // A9 — przykład hasła nie jest gotowym hasłem bez separatorów
    // =================================================================

    /**
     * Trzy miejsca, nie dwa. Trzecie — komunikat walidacji
     * (`RegisterController`, `password.min`) — jest tym, które człowiek widzi
     * dokładnie wtedy, gdy hasło odrzucono.
     */
    public function test_zadna_podpowiedz_do_hasla_nie_podaje_gotowego_hasla(): void
    {
        $stary = 'zielonapietruszkarano';

        $rejestracja = $this->get(route('register'))->assertOk()->getContent();
        $pomocHasla = $this->pomocPola($rejestracja, 'password');

        $this->assertNotSame('', $pomocHasla, 'Nie znalazłem pomocy przy polu hasła na rejestracji.');
        $this->assertStringContainsString('Wymyśl własne', $pomocHasla);
        $this->assertStringNotContainsString($stary, $pomocHasla);

        // Trzecie miejsce: komunikat walidacji. Bierzemy go z sesji błędów,
        // a nie z HTML-a, żeby nie zależeć od tego, którą część formularza
        // przerysuje przekierowanie.
        //
        // TO ŻĄDANIE MUSI IŚĆ JAKO GOŚĆ i dlatego stoi PRZED `actingAs()`
        // niżej: `actingAs()` zostaje na wszystkie kolejne żądania w teście,
        // a zalogowanego middleware `guest` odbija z `/register`
        // przekierowaniem — bez walidacji i bez jednego komunikatu w sesji.
        $odpowiedz = $this->post(route('register'), [
            'display_name' => 'Basia',
            'username' => 'basia z podkarpacia',
            'email' => 'basia@example.com',
            'password' => 'krotkie',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ]);

        $odpowiedz->assertSessionHasErrors('password');

        $komunikat = (string) session('errors')->getBag('default')->first('password');

        $this->assertStringContainsString(
            'co najmniej 10 znaków',
            $komunikat,
            'Nie dostałem komunikatu o za krótkim haśle — bez niego ten test nie mierzy niczego.',
        );

        $this->assertStringNotContainsString(
            $stary,
            $komunikat,
            'Komunikat o za krótkim haśle dalej podaje gotowe hasło bez separatorów.',
        );

        // Drugie miejsce: zmiana hasła w ustawieniach. Dopiero teraz, bo od
        // `actingAs()` nie ma już powrotu do gościa w tym teście.
        $bezpieczenstwo = $this->actingAs($this->user('basia'))
            ->get(route('settings.security'))
            ->assertOk()
            ->getContent();

        $pomocNowegoHasla = $this->pomocPola($bezpieczenstwo, 'password');

        $this->assertNotSame('', $pomocNowegoHasla, 'Nie znalazłem pomocy przy polu nowego hasła.');
        $this->assertStringContainsString('Wymyśl własne', $pomocNowegoHasla);
        $this->assertStringNotContainsString($stary, $pomocNowegoHasla);
    }

    // =================================================================
    // A8 — strona powitalna nie obiecuje minuty
    // =================================================================

    public function test_zacheta_na_stronie_powitalnej_nie_obiecuje_minuty(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $zacheta = $this->elementPoKlasie($html, 'zacheta');

        $this->assertNotSame('', $zacheta, 'Nie znalazłem pasa z zachętą do rejestracji na stronie powitalnej.');

        // Kontrola dodatnia: to jest ten pas i wezwanie do działania w nim stoi.
        $this->assertStringContainsString('Załóż konto', $zacheta);

        $this->assertStringNotContainsString(
            'Zajmie minutę',
            $zacheta,
            'Strona powitalna znów obiecuje minutę. Nikt tego czasu nie mierzy.',
        );
    }

    // =================================================================
    // Narzędzia
    // =================================================================

    private function osobaZWpisem(): User
    {
        $ktos = $this->user('halinka', ['display_name' => 'Halina z Kaszub']);
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        return $ktos;
    }

    /**
     * Prawdziwy 419 przez HTTP, na trasie treści.
     *
     * `PreventRequestForgery` przepuszcza każde żądanie pod PHPUnitem
     * (`runningUnitTests()`), więc bez podmiany środowiska ten test
     * sprawdzałby własną atrapę. Ta sama sztuczka co
     * w `SekretyNieWracajaNaEkranTest` i `StronyBleduPoPolskuTest`.
     *
     * @param  array<string, mixed>  $dane
     */
    private function ekran419(array $dane): string
    {
        $poprzednie = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $odpowiedz = $this->actingAs($this->user('piszacy'))
                ->withSession(['_token' => 'token-sesji'])
                ->post(route('posts.store'), $dane + ['_token' => 'token-z-wygaslej-strony']);
        } finally {
            $this->app['env'] = $poprzednie;
        }

        $odpowiedz->assertStatus(419);

        return $odpowiedz->getContent() ?: '';
    }

    /**
     * Pierwszy akapit strony 419 — ten, który mówi, co się stało z tekstem.
     *
     * To zawężenie ma powód (akapit jest tym miejscem, które O TEKŚCIE mówi),
     * ale MIAŁO TEŻ KOSZT i trzeba go tu wpisać: dopóki testy patrzyły tylko
     * tutaj, ta sama sprzeczność wróciła niżej pomocą przy polu pliku i żaden
     * test tego nie widział. Dlatego obok stoi teraz test patrzący na całe
     * `<main>`. Zawężenie zostaje — samo już nie wystarcza.
     */
    private function pierwszyAkapit(string $html): string
    {
        return $this->xpath($html, '(//p[contains(@class, "mb-5")])[1]');
    }

    private function listaOsobZTablicy(string $html): string
    {
        return $this->xpath($html, '//ul[contains(@class, "kuking-board-people")]');
    }

    private function stopkaTablicy(string $html): string
    {
        return $this->xpath($html, '//p[contains(@class, "kuking-board-footer")]');
    }

    private function elementPoKlasie(string $html, string $klasa): string
    {
        return $this->xpath($html, $this->wyrazeniePoKlasie($klasa));
    }

    /**
     * Sam tekst elementu, bez znaczników i bez zawijania wierszy z szablonu.
     * Tam, gdzie asercja brzmi „nagłówek ma być DOKŁADNIE taki", porównywanie
     * surowego HTML-a łapałoby także klasy CSS i białe znaki z Blade'a.
     */
    private function tekstElementuPoKlasie(string $html, string $klasa): string
    {
        return $this->xpath($html, $this->wyrazeniePoKlasie($klasa), tekst: true);
    }

    private function wyrazeniePoKlasie(string $klasa): string
    {
        return '(//*[contains(concat(" ", normalize-space(@class), " "), " '.$klasa.' ")])[1]';
    }

    /** Tekst pomocy przypiętej do pola formularza (`x-field` → `#f-<nazwa>-help`). */
    private function pomocPola(string $html, string $nazwa): string
    {
        return $this->xpath($html, '//*[@id="f-'.$nazwa.'-help"]');
    }

    /**
     * Tekst pierwszego elementu pasującego do wyrażenia — razem ze znacznikami
     * w środku, bo część asercji pyta o `>Obserwuj<`, czyli o etykietę
     * konkretnego przycisku, a nie o słowo gdziekolwiek w sekcji.
     */
    private function xpath(string $html, string $wyrazenie, bool $tekst = false): string
    {
        $dokument = new \DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $wezly = (new \DOMXPath($dokument))->query($wyrazenie);

        if ($wezly === false || $wezly->length === 0) {
            return '';
        }

        $wezel = $wezly->item(0);

        if ($wezel === null) {
            return '';
        }

        return $tekst
            ? trim((string) preg_replace('/\s+/u', ' ', $wezel->textContent))
            : trim((string) $dokument->saveHTML($wezel));
    }
}
