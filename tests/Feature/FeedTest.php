<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_pokazuje_wpisy_obserwowanych_chronologicznie(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        $basia->following()->attach($marek->getKey(), ['created_at' => now()]);

        $starszy = Post::factory()->create(['author_id' => $marek->getKey(), 'published_at' => now()->subDays(2)]);
        $nowszy = Post::factory()->create(['author_id' => $marek->getKey(), 'published_at' => now()]);

        $feed = app(FollowingFeed::class)->paginate($basia->fresh());

        $this->assertSame($nowszy->getKey(), $feed->items()[0]->getKey());
        $this->assertSame($starszy->getKey(), $feed->items()[1]->getKey());
    }

    public function test_feed_zawiera_wlasne_wpisy(): void
    {
        $basia = $this->user('basia');
        $wlasny = Post::factory()->create(['author_id' => $basia->getKey()]);

        // Bez tego po pierwszej publikacji użytkownik widzi pustkę
        // i myśli, że nic się nie zapisało.
        $feed = app(FollowingFeed::class)->paginate($basia);

        $this->assertSame($wlasny->getKey(), $feed->items()[0]->getKey());
    }

    public function test_pusty_feed_pokazuje_swiezo_z_kuking_i_propozycje_ludzi(): void
    {
        // NAZWY MUSZĄ SIĘ RÓŻNIĆ, I TO JEST CAŁA POPRAWKA TEGO TESTU.
        //
        // `TestCase::user()` daje każdemu domyślnie „Testowa osoba". Test
        // asertował dokładnie ten napis jako dowód, że są propozycje osób —
        // a ten sam napis stoi na stronie i tak, bo to nazwa ZALOGOWANEGO
        // widza (nawigacja, nagłówek profilu). Zmierzone: po zmianie
        // `DailyBoard::forViewer()` na `'people' => collect()`, czyli po
        // CAŁKOWITYM usunięciu propozycji osób, ten test zostawał zielony
        // (razem z całym `DailyBoardTest` — 27/27).
        $nowy = $this->user('nowy', ['display_name' => 'Nowa Basia']);
        $ktos = $this->user('ktos', ['display_name' => 'Halina z Kaszub']);
        Post::factory()->create(['author_id' => $ktos->getKey()]);

        // Propozycje osób pokazuje tablica „kuKINGi na dziś" (DailyBoard),
        // która zastąpiła osobną sekcję „Osoby, które tu gotują".
        //
        // „Świeżo z Kuking" przestało być pozycją w nawigacji i jest teraz
        // ZAKŁADKĄ feedu („Obserwowani / Świeżo z Kuking", UI kit v2,
        // ekran 01) — czyli stoi tam, gdzie się go używa. Test pyta więc
        // o zakładkę, a nie o dawną nazwę pozycji w menu.
        //
        // Zakładka nazywała się „Odkrywaj" do issue #38. To słowo jest na
        // liście zakazanych (`docs/brand/BRAND_EXTENDED.md` §2.1 — „Explore"
        // po polsku; `COPY_STYLE.md` §5 wymienia je wśród nazw odrzuconych),
        // więc test pilnuje teraz OBU rzeczy naraz: że zakładka istnieje pod
        // nową nazwą i że stara nie wróciła. Sam `assertSee('Świeżo
        // z Kuking')` by nie wystarczył — ta nazwa pada na tym ekranie także
        // w pustej tablicy dnia, więc przechodziłby przy skasowanej zakładce.
        $odpowiedz = $this->actingAs($nowy)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Odkrywaj')
            ->assertSee('na dziś')
            ->assertSee('Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.');

        // Nazwa serwisu w etykiecie zakładki jest zapisana dwukolorowo
        // (`docs/brand/GLOS_MARKI.md` §2), więc w HTML-u nie ma napisu
        // „Kuking" — jest komponent `x-kuking-word`. Wzór pyta o zakładkę
        // razem z tym komponentem: sam „Świeżo z" przechodziłby też wtedy,
        // gdyby ktoś wyjął nazwę z etykiety.
        $this->assertMatchesRegularExpression(
            '~<a class="tab" href="'.preg_quote(route('discover'), '~').'"[^>]*>\s*Świeżo z <span class="kuking-word">~u',
            (string) $odpowiedz->getContent(),
            'Zakładka prowadząca do „Świeżo z kuKING" zniknęła ze strony głównej.',
        );

        // Propozycja osoby to KONKRETNY człowiek z odnośnikiem do profilu —
        // i musi stać W LIŚCIE OSÓB na tablicy, nie gdziekolwiek na stronie.
        //
        // Zawężenie jest tu wszystkim. `assertSee('Halina z Kaszub')` na
        // całym dokumencie przechodzi także bez propozycji osób, bo ta nazwa
        // stoi na karcie jej wpisu w zakładce „Świeżo z Kuking". Zawężenie do samej
        // sekcji `kuking-board` też nie wystarcza — tablica pokazuje obok
        // osób także DANIA, z nazwą autora przy każdym. Zmierzone: obie
        // szersze wersje zostawały zielone przy `'people' => collect()`.
        $lista = $this->listaProponowanychOsob((string) $odpowiedz->getContent());

        $this->assertStringContainsString('Halina z Kaszub', $lista);
        $this->assertStringContainsString(route('profile.show', 'ktos'), $lista);

        // I nie proponujemy widzowi jego samego.
        $this->assertStringNotContainsString('Nowa Basia', $lista);
    }

    /**
     * Sama lista „Osoby" z tablicy „kuKINGi na dziś".
     *
     * `<ul class="kuking-board-people">` renderuje się WYŁĄCZNIE wtedy, gdy
     * propozycje osób naprawdę są (`@if($people->isNotEmpty())` w
     * `resources/views/components/kuking-board.blade.php`) — więc samo jej
     * znalezienie jest już połową asercji.
     */
    private function listaProponowanychOsob(string $html): string
    {
        $start = strpos($html, '<ul class="kuking-board-people">');

        $this->assertNotFalse(
            $start,
            'Na stronie startowej nowej osoby nie ma ani jednej propozycji, kogo obserwować. '
            .'Pusty feed bez propozycji to pusty ekran u nowego użytkownika (AGENTS.md §8).',
        );

        $koniec = strpos($html, '</ul>', $start);

        $this->assertNotFalse($koniec);

        return substr($html, $start, $koniec - $start);
    }

    public function test_wpisy_zablokowanej_osoby_nie_pojawiaja_sie_w_odkrywaniu(): void
    {
        $basia = $this->user('basia');
        $spam = $this->user('spam');
        Post::factory()->create(['author_id' => $spam->getKey(), 'body' => 'Zarabiaj z domu 5000 zl']);

        app(BlockUser::class)->handle($basia, $spam);

        $this->actingAs($basia->fresh())
            ->get(route('discover'))
            ->assertOk()
            ->assertDontSee('Zarabiaj z domu');
    }

    public function test_strona_glowna_dla_gosci_pokazuje_publiczne_wpisy(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Pokaż, co dziś ugotowałeś')
            ->assertSee('Rosol na niedziele');
    }

    public function test_wpisy_prywatne_nie_wychodza_w_odkrywaniu(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->private()->create(['author_id' => $ktos->getKey(), 'body' => 'Tylko dla mnie']);

        $this->get(route('discover'))->assertOk()->assertDontSee('Tylko dla mnie');
    }

    // -----------------------------------------------------------------
    // Status konta autora (audyt A5)
    // -----------------------------------------------------------------

    public function test_wpisy_zawieszonej_osoby_nie_pojawiaja_sie_w_odkrywaniu(): void
    {
        $autor = $this->user('zawieszona');
        Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Rosol od zawieszonej']);

        $autor->suspend();

        $this->get(route('discover'))->assertOk()->assertDontSee('Rosol od zawieszonej');
    }

    public function test_wpisy_zbanowanej_osoby_nie_pojawiaja_sie_w_odkrywaniu(): void
    {
        $autor = $this->user('zbanowana');
        Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Rosol od zbanowanej']);

        $autor->ban();

        $this->get(route('discover'))->assertOk()->assertDontSee('Rosol od zbanowanej');
    }

    /**
     * Kontrola przeciwna: filtr statusu nie może przez pomyłkę wyciąć
     * wpisów osoby, która jest w pełni aktywna, tylko dlatego, że KTOŚ INNY
     * w bazie jest akurat zawieszony albo zbanowany.
     */
    public function test_odkrywanie_nadal_pokazuje_wpisy_aktywnej_osoby_gdy_ktos_inny_jest_ukarany(): void
    {
        $aktywny = $this->user('aktywna');
        Post::factory()->create(['author_id' => $aktywny->getKey(), 'body' => 'Rosol od aktywnej']);

        $this->user('zbanowanainna')->ban();

        $this->get(route('discover'))->assertOk()->assertSee('Rosol od aktywnej');
    }
}
