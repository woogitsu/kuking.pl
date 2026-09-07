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

    public function test_wybor_redakcyjny_ma_pierwszenstwo_przed_automatycznym(): void
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
        $this->assertCount(1, $tablica['people']);
        $this->assertSame($wybrany->getKey(), $tablica['people']->first()->getKey());
        $this->assertSame('Pierwszy raz pokazała swój chleb', $tablica['notes'][$wybrany->getKey()]);
        $this->assertCount(0, $tablica['posts'], 'Wybór redakcyjny nie dobiera wpisów samodzielnie.');
        $this->assertNotSame($wpis->getKey(), $tablica['posts']->first()?->getKey());
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

    public function test_tablica_jest_na_stronie_glownej_odkryj_i_landingu(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $this->get('/')->assertOk()->assertSee('na dziś');
        $this->get(route('discover'))->assertOk()->assertSee('na dziś');
        $this->actingAs($this->user('widz'))->get(route('home'))->assertOk()->assertSee('na dziś');
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
        $start = strpos($html, 'kuking-board');
        $koniec = strpos($html, 'Jutro będzie tu ktoś inny');
        $sekcja = substr($html, $start, max(0, $koniec - $start));

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
