<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tablica „kuKINGi na dziś".
 *
 * Najważniejsze w tych testach nie jest to, co tablica pokazuje, tylko czego
 * NIE pokazuje: rankingu, osób zablokowanych, kilku wpisów tej samej osoby.
 */
class DailyBoardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wybór gospodarza stoi PIERWSZY, a resztę miejsc dobiera automat
     * (decyzja właściciela, 11.09.2026).
     *
     * Do 11 września zaznaczenie choćby jednej pozycji wyłączało automat
     * całkowicie — tablica pokazywała dokładnie tyle, ile zaznaczono, i ani
     * rzeczy więcej. Wybór gospodarza ma WYRÓŻNIAĆ kilka rzeczy, a nie
     * zamykać tablicę na resztę serwisu.
     */
    public function test_wybor_redakcyjny_stoi_pierwszy_a_reszte_dobiera_automat(): void
    {
        $gospodarz = $this->moderator();
        $wybrany = $this->user('wybrany');
        $inny = $this->user('inny');

        // `inny` publikuje później, więc automat postawiłby go pierwszego.
        Post::factory()->create(['author_id' => $wybrany->getKey(), 'published_at' => now()->subDay()]);
        $wpis = Post::factory()->create(['author_id' => $inny->getKey(), 'published_at' => now()]);

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_USER,
            'subject_id' => $wybrany->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
            'note' => 'Pierwszy raz pokazała swój chleb',
        ]);

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertTrue($tablica['curated']);

        // KOLEJNOŚĆ JEST CZĘŚCIĄ DECYZJI. Gdyby automat wchodził przed
        // wyborem gospodarza, wyróżnienie przestałoby być wyróżnieniem —
        // a `inny` opublikował później i bez tej reguły stałby pierwszy.
        $this->assertSame($wybrany->getKey(), $tablica['people']->first()->getKey(),
            'Wybór gospodarza nie stoi na pierwszym miejscu.');
        $this->assertSame('Pierwszy raz pokazała swój chleb', $tablica['notes'][$wybrany->getKey()]);

        // Osoba wybrana NIE dubluje się w doborze automatu.
        $this->assertSame(
            $tablica['people']->modelKeys(),
            array_values(array_unique($tablica['people']->modelKeys())),
            'Ta sama osoba stoi na tablicy dwa razy.',
        );

        // A puste miejsca po daniach zapełnia automat — tego właśnie brakowało.
        $this->assertTrue($tablica['posts']->contains('id', $wpis->getKey()),
            'Automat nie dobrał ani jednego dania, choć wybór gospodarza ich nie zawierał.');
    }

    /**
     * Dobór automatu nie dokłada DRUGIEGO dania osoby, którą gospodarz już
     * wyróżnił. Reguła „najwyżej jedno danie od osoby" obowiązuje w całej
     * tablicy, a nie osobno w części redakcyjnej i osobno w dobranej —
     * bez tego jedna aktywna osoba zasłania cały serwis
     * (docs/product/COLD_START.md).
     */
    public function test_automat_nie_doklada_drugiego_dania_wyroznionej_osoby(): void
    {
        $gospodarz = $this->moderator();
        $ewa = $this->user('ewa');

        $wybraneDanie = Post::factory()->create([
            'author_id' => $ewa->getKey(),
            'published_at' => now()->subDay(),
        ]);
        $drugieDanieEwy = Post::factory()->create([
            'author_id' => $ewa->getKey(),
            'published_at' => now(),
        ]);

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $wybraneDanie->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertTrue($tablica['posts']->contains('id', $wybraneDanie->getKey()));
        $this->assertFalse(
            $tablica['posts']->contains('id', $drugieDanieEwy->getKey()),
            'Automat dołożył drugie danie osoby, którą gospodarz właśnie wyróżnił.',
        );
    }

    public function test_bez_wyboru_redakcyjnego_tablica_dobiera_sama(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey()]);

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertFalse($tablica['curated']);
        $this->assertCount(1, $tablica['posts']);
    }

    public function test_maksymalnie_jeden_wpis_od_tej_samej_osoby(): void
    {
        $aktywna = $this->user('aktywna');

        // Jedna osoba publikuje pięć razy. Bez ograniczenia zasłoniłaby
        // cały serwis, a nowy użytkownik pomyślałby, że „tu jest tylko ta pani".
        for ($i = 0; $i < 5; $i++) {
            Post::factory()->create([
                'author_id' => $aktywna->getKey(),
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(1, $tablica['posts']);
    }

    public function test_nie_pokazuje_osob_zablokowanych_w_zadna_strone(): void
    {
        $basia = $this->user('basia');
        $spam = $this->user('spam');
        Post::factory()->create(['author_id' => $spam->getKey(), 'body' => 'Zarabiaj z domu']);

        app(BlockUser::class)->handle($basia, $spam);

        $tablica = app(DailyBoard::class)->forViewer($basia->fresh());

        $this->assertCount(0, $tablica['posts']);
        $this->assertCount(0, $tablica['people']);
    }

    public function test_wybor_redakcyjny_pomija_pozycje_niedostepne_dla_widza(): void
    {
        $gospodarz = $this->moderator();
        $basia = $this->user('basia');
        $spam = $this->user('spam');

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_USER,
            'subject_id' => $spam->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        app(BlockUser::class)->handle($basia, $spam);

        $tablica = app(DailyBoard::class)->forViewer($basia->fresh());

        // Pozycja wypada w całości — tablica nie pokazuje pustej karty
        // i nie zdradza, że coś tu było.
        $this->assertCount(0, $tablica['people']);
    }

    public function test_nie_proponuje_osob_juz_obserwowanych(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        Post::factory()->create(['author_id' => $marek->getKey()]);

        $basia->following()->attach($marek->getKey(), ['created_at' => now()]);

        $tablica = app(DailyBoard::class)->forViewer($basia->fresh());

        $this->assertCount(0, $tablica['people'], 'Nie proponujemy kogoś, kogo już się obserwuje.');
    }

    public function test_nie_proponuje_samego_siebie(): void
    {
        $basia = $this->user('basia');
        Post::factory()->create(['author_id' => $basia->getKey()]);

        $tablica = app(DailyBoard::class)->forViewer($basia);

        $this->assertCount(0, $tablica['people']);
    }

    public function test_wybor_z_wczoraj_nie_pokazuje_sie_dzis(): void
    {
        $gospodarz = $this->moderator();
        $ktos = $this->user('ktos');

        DailyPick::create([
            'shown_on' => Czas::lokalnie(now())->subDay()->toDateString(),
            'subject_type' => DailyPick::TYPE_USER,
            'subject_id' => $ktos->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertFalse($tablica['curated'], 'Tablica jest na dziś, nie na zawsze.');
    }

    public function test_pusty_stan_zamiast_bledu(): void
    {
        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(0, $tablica['people']);
        $this->assertCount(0, $tablica['posts']);

        $this->get('/')->assertOk()->assertSee('Dziś jeszcze nikogo nie wybraliśmy');
    }

    /**
     * Tablica stoi na trzech ekranach. Test pyta o SAMĄ TABLICĘ
     * (`id="kuking-na-dzis"`), a nie o jej nagłówek, bo od issue #38 nagłówek
     * nie jest wszędzie ten sam — patrz test niżej.
     */
    public function test_tablica_jest_na_stronie_glownej_odkryj_i_landingu(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $this->get('/')->assertOk()->assertSee('id="kuking-na-dzis"', false);
        $this->get(route('discover'))->assertOk()->assertSee('id="kuking-na-dzis"', false);
        $this->actingAs($this->user('widz'))->get(route('home'))
            ->assertOk()->assertSee('id="kuking-na-dzis"', false);
    }

    /**
     * DAWKOWANIE GRY SŁOWEM „kuKING" (issue #38, `docs/brand/COPY_STYLE.md` §2).
     *
     * Najwyżej raz na ekran. Na stronie powitalnej to jedno miejsce zajmuje
     * przycisk „Zostań kuKINGiem", który §8 przypisuje tam wprost — więc
     * tablica ma tam nagłówek zapasowy „Co się dziś gotuje" (§5, D-013).
     * Na `/odkryj` i `/home` D-207 wprowadza nagłówek „Co dobrego u innych?”.
     */
    public function test_tablica_ustepuje_z_nazwy_tam_gdzie_gra_slowem_jest_juz_zajeta(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $this->get('/')->assertOk()
            ->assertSee('Co się dziś gotuje')
            ->assertSee('Zostań <span class="kuking-word">', false);

        $this->get(route('discover'))->assertOk()
            ->assertSee('Co dobrego u innych?')
            ->assertDontSee('Co się dziś gotuje');
    }

    public function test_nigdzie_nie_pokazujemy_miary_popularnosci(): void
    {
        $ktos = $this->user('ktos');
        $wpis = Post::factory()->create(['author_id' => $ktos->getKey()]);
        Comment::factory()->count(3)->create(['post_id' => $wpis->getKey()]);
        $this->user('obserwujacy')->following()->attach($ktos->getKey(), ['created_at' => now()]);

        $html = $this->get(route('discover'))->assertOk()->getContent();

        // Wycinamy sekcję tablicy i sprawdzamy, że nie ma w niej liczb
        // sugerujących ranking.
        // Granicę wycinka wyznacza KLASA stopki, nie jej treść: samo zdanie
        // stopki jest tekstem i już raz się zmieniło (11.09.2026, wyjęcie
        // obietnicy „Jutro będzie tu ktoś inny"), a wtedy `strpos()` zwraca
        // `false`, wycinek robi się pusty i test przechodzi, nie mierząc nic.
        $start = strpos($html, 'kuking-board');
        $koniec = strpos($html, 'kuking-board-footer');

        $this->assertIsInt($start, 'Nie znalazłem tablicy dnia w HTML-u.');
        $this->assertIsInt($koniec, 'Nie znalazłem stopki tablicy — wycinek byłby pusty.');

        $sekcja = substr($html, $start, max(0, $koniec - $start));
        $this->assertNotSame('', $sekcja, 'Wycinek tablicy jest pusty — nie ma czego sprawdzać.');

        $this->assertStringNotContainsString('obserwując', $sekcja);
        $this->assertStringNotContainsString('wpisów', $sekcja);
        $this->assertStringNotContainsString('Komentarze (', $sekcja);
    }

    public function test_gospodarz_moze_ustawic_i_wyczyscic_tablice(): void
    {
        $gospodarz = $this->moderator();
        $ktos = $this->user('ktos');
        $wpis = Post::factory()->create(['author_id' => $ktos->getKey()]);

        $this->actingAs($gospodarz)->get(route('admin.daily-board'))->assertOk();

        $this->actingAs($gospodarz)->put(route('admin.daily-board'), [
            'osoby' => [$ktos->getKey()],
            'wpisy' => [$wpis->getKey()],
            'notatki' => [$ktos->getKey() => 'Pierwszy raz pokazała swój chleb'],
        ])->assertRedirect();

        $this->assertSame(2, DailyPick::count());
        $this->assertSame('Pierwszy raz pokazała swój chleb', DailyPick::where('subject_id', $ktos->getKey())->first()->note);

        $this->actingAs($gospodarz)->delete(route('admin.daily-board'))->assertRedirect();
        $this->assertSame(0, DailyPick::count());
    }

    public function test_ponowny_zapis_zastepuje_wczorajszy_wybor_a_nie_dokłada(): void
    {
        $gospodarz = $this->moderator();
        $pierwszy = $this->user('pierwszy');
        $drugi = $this->user('drugi');

        $this->actingAs($gospodarz)->put(route('admin.daily-board'), ['osoby' => [$pierwszy->getKey()]]);
        $this->actingAs($gospodarz)->put(route('admin.daily-board'), ['osoby' => [$drugi->getKey()]]);

        $this->assertSame(1, DailyPick::count());
        $this->assertSame($drugi->getKey(), DailyPick::first()->subject_id);
    }

    public function test_tablica_przyjmuje_najwyzej_szesc_pozycji(): void
    {
        $gospodarz = $this->moderator();
        $osoby = collect(range(1, 7))->map(fn (int $i) => $this->user('osoba'.$i)->getKey())->all();

        $this->actingAs($gospodarz)
            ->put(route('admin.daily-board'), ['osoby' => $osoby])
            ->assertSessionHasErrors('osoby');

        $this->assertSame(0, DailyPick::count());
    }

    public function test_zwykly_uzytkownik_nie_ma_dostepu_do_ekranu_gospodarza(): void
    {
        $this->actingAs($this->user('basia'))->get(route('admin.daily-board'))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Status konta autora (audyt A5) — tablica żyje na tej samej stronie
    // /odkryj co reszta feedu, więc dziedziczy ten sam wymóg.
    // -----------------------------------------------------------------

    public function test_automat_pomija_wpis_osoby_zawieszonej_lub_zbanowanej(): void
    {
        $zawieszona = $this->user('zawieszona');
        $zbanowana = $this->user('zbanowana');
        Post::factory()->create(['author_id' => $zawieszona->getKey()]);
        Post::factory()->create(['author_id' => $zbanowana->getKey()]);

        $zawieszona->suspend();
        $zbanowana->ban();

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(0, $tablica['posts']);
    }

    public function test_wybor_redakcyjny_pomija_wpis_osoby_ukaranej_po_wybraniu(): void
    {
        $gospodarz = $this->moderator();
        $ukarany = $this->user('ukarany');
        $wpis = Post::factory()->create(['author_id' => $ukarany->getKey()]);

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $wpis->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        // Redakcja wybrała wpis, ZANIM autora ukarano — pozycja i tak
        // musi wypaść, gdy dziś stronę odwiedza ktoś zupełnie inny.
        $ukarany->ban();

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(0, $tablica['posts']);
    }

    // -----------------------------------------------------------------
    // SUFIT TABLICY: SZEŚĆ, NIE CZTERY (11.09.2026)
    //
    // Tablica układa się w dwie kolumny, więc cztery pozycje zostawiają
    // połowę ostatniego rzędu pustą. Podniesienie sufitu to jedna liczba
    // w `DailyBoard`, ale trzy rzeczy do sprawdzenia — i każda z nich ma
    // tu własny test, bo każda psuje się inaczej:
    //
    //  1. czy przy sześciu naprawdę widać sześć,
    //  2. czy przy mniej niż sześciu nie ma pustych miejsc,
    //  3. czy reguła „najwyżej jedna pozycja od osoby" trzyma się tak samo,
    //  4. czy dwie pozycje więcej nie kosztują ani jednego zapytania więcej.
    // -----------------------------------------------------------------

    public function test_tablica_pokazuje_szesc_pozycji_gdy_jest_z_czego_wybierac(): void
    {
        // Ośmiu autorów, każdy z jednym wpisem — więcej niż sufit, żeby test
        // mierzył SUFIT, a nie zawartość bazy.
        foreach (range(1, 8) as $i) {
            $autor = $this->user('autor'.$i);
            Post::factory()->create([
                'author_id' => $autor->getKey(),
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(6, $tablica['people'],
            'Tablica pokazuje inną liczbę osób niż sześć, choć kont z wpisami jest osiem.');
        $this->assertCount(6, $tablica['posts'],
            'Tablica pokazuje inną liczbę dań niż sześć, choć wpisów od różnych osób jest osiem.');
    }

    /**
     * MNIEJ NIŻ SZEŚĆ TO NIE STAN AWARYJNY, TYLKO PIERWSZE TYGODNIE SERWISU
     * (`docs/product/COLD_START.md`). Tablica ma wtedy pokazać tyle, ile jest,
     * i ani jednego pustego miejsca.
     *
     * Test patrzy i na kolekcję, i na HTML. Sama kolekcja nie wystarcza:
     * pusty slot jest defektem WIDOKU i powstałby dopiero wtedy, gdyby ktoś
     * rysował sześć kafelków z góry, zamiast iterować po tym, co przyszło.
     */
    public function test_mniej_niz_szesc_kont_nie_zostawia_pustych_miejsc(): void
    {
        foreach (range(1, 3) as $i) {
            $autor = $this->user('skromny'.$i);
            Post::factory()->create([
                'author_id' => $autor->getKey(),
                'published_at' => now()->subMinutes($i),
                'body' => 'Rosol numer '.$i,
            ]);
        }

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(3, $tablica['people']);
        $this->assertCount(3, $tablica['posts']);

        // `/odkryj`, nie `/` — strona powitalna obcina tablicę do trzech
        // pozycji (`FeedController::GUEST_BOARD_PEOPLE`), więc na niej ten
        // pomiar dawałby trójkę niezależnie od sufitu i niczego nie pilnował.
        $html = $this->get(route('discover'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'class="kuking-board-person"'),
            'Liczba kafelków osób w HTML-u nie zgadza się z liczbą osób na tablicy — '
            .'widok rysuje puste miejsca do sufitu.');
        $this->assertSame(3, substr_count($html, 'class="kuking-board-post"'),
            'Liczba kafelków dań w HTML-u nie zgadza się z liczbą dań na tablicy — '
            .'widok rysuje puste miejsca do sufitu.');
    }

    /**
     * Reguła „najwyżej jedna pozycja od osoby" NIE jest skutkiem sufitu ani
     * zapasu nad nim — trzyma ją `DISTINCT ON (posts.author_id)`. Ten test
     * jest tu po to, żeby podniesienie sufitu nie przywróciło po cichu
     * starego rozwiązania „pobierz z zapasem i odsiej", przy którym jeden
     * bardzo aktywny autor zjadał całą tablicę.
     */
    public function test_regula_jedna_pozycja_od_osoby_trzyma_sie_takze_przy_szesciu(): void
    {
        // Jedna osoba z dziesięcioma NAJNOWSZYMI wpisami: gdyby dobór szedł
        // „weź najnowsze i odsiej autorów", zostałby z nich jeden wpis,
        // a tablica pokazałaby cztery karty zamiast sześciu.
        $aktywna = $this->user('aktywna_bardzo');
        for ($i = 0; $i < 10; $i++) {
            Post::factory()->create([
                'author_id' => $aktywna->getKey(),
                'published_at' => now()->subMinutes($i),
            ]);
        }

        foreach (range(1, 5) as $i) {
            $autor = $this->user('spokojny'.$i);
            Post::factory()->create([
                'author_id' => $autor->getKey(),
                'published_at' => now()->subHours($i),
            ]);
        }

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertCount(6, $tablica['posts'],
            'Sześć dań od sześciu autorów było dostępnych, a tablica pokazała mniej.');

        $autorzy = $tablica['posts']->pluck('author_id')->all();

        $this->assertSame(
            $autorzy,
            array_values(array_unique($autorzy)),
            'Ta sama osoba ma na tablicy więcej niż jedno danie.',
        );
        $this->assertSame(
            1,
            count(array_keys($autorzy, $aktywna->getKey(), true)),
            'Osoba z dziesięcioma najnowszymi wpisami zajęła więcej niż jedno miejsce.',
        );
    }

    /**
     * DWIE POZYCJE WIĘCEJ MAJĄ KOSZTOWAĆ ZERO ZAPYTAŃ WIĘCEJ (AGENTS.md §3 —
     * pomiar, nie założenie).
     *
     * Wzorzec pomiaru jak w `LicznikiKolejekBezZapytanTest`: liczy się
     * ZAPYTANIA, nie milisekundy, bo to liczba zapytań jest tym, co rośnie
     * wachlarzem przy N+1 i co widać dopiero na produkcji. `forViewer()`
     * stoi na trzech ekranach, w tym na publicznym landingu — czyli chodzi
     * także dla każdego robota indeksującego.
     *
     * DWA POMIARY, BO JEDEN NIE WYSTARCZA:
     *
     *  1. `peopleToFollow()` przy limicie 4 i przy 6 — ta metoda jest
     *     publiczna, więc limit da się podać wprost i porównać jabłka
     *     z jabłkami na tych samych danych.
     *  2. całe `forViewer()` przy czterech i przy ośmiu autorach w bazie —
     *     to obejmuje `automaticPosts()` (prywatne) i łapie wachlarz
     *     doklejony przez relację albo `withCount` na liście pozycji,
     *     czyli dokładnie ten kształt usterki, którego pierwszy pomiar
     *     nie widzi.
     */
    public function test_podniesienie_sufitu_nie_doklada_ani_jednego_zapytania(): void
    {
        foreach (range(1, 4) as $i) {
            $autor = $this->user('pomiar'.$i);
            Post::factory()->create([
                'author_id' => $autor->getKey(),
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $board = app(DailyBoard::class);

        // Pomiar 2 najpierw w gałęzi „mało danych": przy czterech kontach
        // tablica ma cztery pozycje, cokolwiek mówi sufit.
        $czteryPozycje = $this->policzZapytania(fn () => $board->forViewer(null));
        $this->assertCount(4, $board->forViewer(null)['posts'], 'Dane pomiaru nie dają czterech pozycji.');

        foreach (range(5, 8) as $i) {
            $autor = $this->user('pomiar'.$i);
            Post::factory()->create([
                'author_id' => $autor->getKey(),
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $szescPozycji = $this->policzZapytania(fn () => $board->forViewer(null));
        $this->assertCount(6, $board->forViewer(null)['posts'], 'Dane pomiaru nie dają sześciu pozycji.');

        $this->assertSame(
            $czteryPozycje,
            $szescPozycji,
            'forViewer() pyta bazę inną liczbę razy przy sześciu pozycjach niż przy '
            .'czterech ('.$czteryPozycje.' → '.$szescPozycji.'). To jest wachlarz '
            .'zapytań rosnący z liczbą kart, czyli N+1 — nie zmiana sufitu.',
        );

        // Pomiar 1 DOPIERO TERAZ, przy ośmiu kontach w bazie. Przy czterech
        // `peopleToFollow(null, 6)` zwracałoby te same cztery wiersze co
        // `(null, 4)` i porównanie byłoby zielone z braku danych, nie z braku
        // usterki (`docs/PULAPKI_TESTOW.md`, pułapka 2).
        $przyCzterech = $this->policzZapytania(fn () => $board->peopleToFollow(null, 4));
        $przySzesciu = $this->policzZapytania(fn () => $board->peopleToFollow(null, 6));

        $this->assertCount(4, $board->peopleToFollow(null, 4), 'Limit 4 nie zwrócił czterech osób — pomiar nie porównuje dwóch różnych rozmiarów.');
        $this->assertCount(6, $board->peopleToFollow(null, 6), 'Limit 6 nie zwrócił sześciu osób — pomiar nie porównuje dwóch różnych rozmiarów.');

        $this->assertSame(
            $przyCzterech,
            $przySzesciu,
            'peopleToFollow() pyta bazę inną liczbę razy przy limicie 6 niż przy 4 '
            .'(4 → '.$przyCzterech.', 6 → '.$przySzesciu.'). Limit ma być narzucany '
            .'w SQL, a relacje dociągane przez with() — czyli stała liczba zapytań.',
        );
    }

    /**
     * Liczba zapytań wykonanych podczas `$akcja`.
     *
     * `enableQueryLog()` + `flushQueryLog()`, a nie `DB::listen()`: listener
     * zostaje podpięty do końca testu i przy drugim pomiarze w tej samej
     * metodzie liczyłby podwójnie.
     */
    private function policzZapytania(callable $akcja): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $akcja();

        $ile = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $ile;
    }

    public function test_tablica_dziala_takze_po_22_utc_czyli_gdy_polska_data_jest_juz_inna(): void
    {
        // TEN TEST PILNUJE DANYCH TESTOWYCH, NIE KODU PRODUKCYJNEGO.
        //
        // `DailyPick::forDate()` czyta „dziś" przez `Czas::dzisiajData()`
        // (data POLSKA). Dane w tym pliku były wcześniej budowane przez
        // `now()->toDateString()` (data UTC). Te dwie wartości są równe przez
        // 22 godziny na dobę i różne między 22:00 a 24:00 UTC — czyli testy
        // przechodziły cały dzień i padłyby wieczorem, bez żadnej zmiany
        // w kodzie. Dokładnie ten sam mechanizm wywrócił już raz
        // `WspomnieniaTest` o 22:00 UTC (`docs/HANDOVER.md`).
        //
        // Zamrożony zegar zamiast czekania na wieczór: 7 września 22:30 UTC
        // to 8 września 00:30 w Polsce.
        $this->travelTo(Carbon::parse('2026-09-07 22:30:00', 'UTC'));

        $gospodarz = $this->moderator();
        $ktos = $this->user('ktos_wieczorem');
        $wpis = Post::factory()->create(['author_id' => $ktos->getKey()]);

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $wpis->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        $tablica = app(DailyBoard::class)->forViewer(null);

        $this->assertTrue(
            $tablica['curated'],
            'Tablica nie znalazła wyboru redakcyjnego zapisanego tego samego '
            .'polskiego dnia. Dane testu i kod czytający je liczą „dziś" '
            .'w różnych strefach.',
        );
        $this->assertCount(1, $tablica['posts']);
    }
}
