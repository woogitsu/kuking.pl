<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Posts\SasiedniWpisAutora;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Kolejne zdjęcie" (issue: nawigacja jak w Garnku) — odnośnik do
 * poprzedniego/następnego wpisu TEGO SAMEGO AUTORA na stronie wpisu.
 *
 * WIDOCZNOŚĆ JEST TU CAŁYM RYZYKIEM (patrz `SasiedniWpisAutora`). Ta klasa
 * testuje DROGĘ 2 z `WidocznoscTestCase` — LISTĘ, czyli osobne zapytanie,
 * które może pokazać za dużo, mimo że wejście wprost pod adres treści
 * (`PostWidocznoscTest`) jest już zabezpieczone Policy. To dwie różne drogi
 * wycieku i naprawienie jednej nie naprawia drugiej.
 */
class PostSasiedniWpisTest extends TestCase
{
    use RefreshDatabase;

    private function wpisZeZdjeciem(User $autor, array $atrybuty = []): Post
    {
        $wpis = Post::factory()->create($atrybuty + ['author_id' => $autor->getKey()]);

        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return $wpis->refresh();
    }

    // -----------------------------------------------------------------
    // Poprawne działanie — chronologicznie, po `published_at`
    // -----------------------------------------------------------------

    public function test_nawigacja_prowadzi_chronologicznie_po_dacie_publikacji(): void
    {
        $autor = $this->user('kucharka');

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDays(2)]);
        $b = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDay()]);
        $c = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        $html = $this->get(route('posts.show', $b))->assertOk()->getContent();

        // Z ŚRODKOWEGO wpisu widać OBA kierunki, każdy z miniaturą.
        $this->assertStringContainsString(route('posts.show', $a), $html);
        $this->assertStringContainsString(route('posts.show', $c), $html);
        $this->assertStringContainsString('Poprzedni wpis', $html);
        $this->assertStringContainsString('Następny wpis', $html);
        // Miniatura ma pusty `alt` (docs/UX_50_PLUS.md + wzorzec z sygnaly.blade.php) —
        // podpis niesie tekst odnośnika, obrazek jest czysto dekoracyjny.
        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_brak_sasiada_nie_pokazuje_martwego_przycisku(): void
    {
        $autor = $this->user('samotna');
        $jedyny = $this->wpisZeZdjeciem($autor);

        $html = $this->get(route('posts.show', $jedyny))->assertOk()->getContent();

        $this->assertStringNotContainsString('Poprzedni wpis', $html);
        $this->assertStringNotContainsString('Następny wpis', $html);
    }

    // -----------------------------------------------------------------
    // KONTROLA UJEMNA 1 — cudzy wpis prywatny nie wycieka, nawet jako miniatura
    // -----------------------------------------------------------------

    public function test_prywatny_wpis_sasiada_nie_wycieka_obcemu_ani_jako_miniatura(): void
    {
        $autor = $this->user('kucharka');
        $obcy = $this->user('obca');

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDays(2)]);
        $prywatny = $this->wpisZeZdjeciem($autor, [
            'published_at' => now()->subDay(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $c = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        // Z pierwszego wpisu obcy widz ma przeskoczyć PROSTO do trzeciego —
        // środkowy (prywatny) nie ma prawa się pojawić: ani jako odnośnik,
        // ani jako miniatura, ani jako informacja "coś tu jest".
        $html = $this->actingAs($obcy)->get(route('posts.show', $a))->assertOk()->getContent();

        $this->assertStringContainsString(route('posts.show', $c), $html);
        $this->assertStringNotContainsString(route('posts.show', $prywatny), $html);

        // To samo w drugą stronę: z trzeciego wpisu "poprzedni" ma wskazywać
        // na pierwszy, nie na prywatny środkowy.
        $html = $this->actingAs($obcy)->get(route('posts.show', $c))->assertOk()->getContent();
        $this->assertStringContainsString(route('posts.show', $a), $html);
        $this->assertStringNotContainsString(route('posts.show', $prywatny), $html);
    }

    // -----------------------------------------------------------------
    // KONTROLA UJEMNA 2 — wpis ukryty moderacyjnie nie wycieka
    // -----------------------------------------------------------------

    public function test_wpis_ukryty_moderacyjnie_nie_wycieka_jako_sasiad(): void
    {
        $autor = $this->user('kucharka');
        $obcy = $this->user('obca');

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDays(2)]);
        $ukryty = $this->wpisZeZdjeciem($autor, [
            'published_at' => now()->subDay(),
            'status' => Post::STATUS_HIDDEN,
        ]);
        $c = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        $html = $this->actingAs($obcy)->get(route('posts.show', $a))->assertOk()->getContent();

        $this->assertStringContainsString(route('posts.show', $c), $html);
        $this->assertStringNotContainsString(route('posts.show', $ukryty), $html);
    }

    // -----------------------------------------------------------------
    // KONTROLA UJEMNA 3 — blokada między kontami ukrywa sąsiada
    // -----------------------------------------------------------------

    /**
     * Test na poziomie zapytania (klasa domenowa wprost), nie przez HTTP:
     * blokada między widzem a autorem daje `PostPolicy::view()` = false dla
     * KAŻDEGO wpisu tego autora, więc widz zablokowany dostaje 403 już na
     * bieżącym wpisie i nigdy nie renderuje tej nawigacji — to jest już
     * pokryte przez `PostWidocznoscTest`. Ten test sprawdza to samo o jedno
     * piętro niżej: że SAMO ZAPYTANIE `SasiedniWpisAutora` (na wypadek, gdyby
     * kiedyś zaczęło go używać coś innego niż `PostController::show()`) nie
     * pokazuje sąsiada osobie zablokowanej, niezależnie od Policy nad nim.
     */
    public function test_zablokowany_nie_dostaje_sasiada_z_zapytania_domenowego(): void
    {
        $autor = $this->user('kucharka');
        $zablokowany = $this->user('zablokowana');
        app(BlockUser::class)->handle($autor, $zablokowany);

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDay()]);
        $b = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        $sasiedzi = app(SasiedniWpisAutora::class);

        $this->assertNull($sasiedzi->nastepny($a, $zablokowany));
        $this->assertNull($sasiedzi->poprzedni($b, $zablokowany));

        // Kontrola pozytywna w tym samym teście: bez blokady ten sam widz
        // DOSTAJE sąsiada — dowód, że `null` wyżej to efekt blokady, a nie
        // pomyłka w danych testu.
        $obcy = $this->user('obca');
        $this->assertTrue($sasiedzi->nastepny($a, $obcy)?->is($b));
    }

    // -----------------------------------------------------------------
    // KONTROLA UJEMNA 4 — konto autora zbanowane/kasujące się
    // -----------------------------------------------------------------

    /**
     * Test na poziomie zapytania, z tego samego powodu co blokada wyżej:
     * ban wylogowuje konto przy KAŻDYM żądaniu (`EnsureAccountIsActive"),
     * więc przez HTTP nikt — ani obcy, ani sam zbanowany autor — nie
     * dotrze do tej strony po banie (`PostWidocznoscTest` już to pokrywa).
     * Ten test dowodzi, że SAMO ZAPYTANIE ma tę granicę wpisaną wprost —
     * `whereHas('author', ...->dostepnyJakoAutor())` — a nie tylko liczy na
     * to, że nikt zbanowany nigdy tu nie trafi.
     */
    public function test_zbanowany_autor_nie_ma_sasiadow_z_zapytania_domenowego(): void
    {
        $autor = $this->user('kucharka');
        $obcy = $this->user('obca');

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDay()]);
        $b = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        $sasiedzi = app(SasiedniWpisAutora::class);

        // Kontrola pozytywna PRZED banem — dowód, że dane testu są poprawne.
        $this->assertTrue($sasiedzi->nastepny($a, $obcy)?->is($b));

        $autor->ban();
        $a->refresh();

        $this->assertNull($sasiedzi->nastepny($a, $obcy));
    }

    // -----------------------------------------------------------------
    // Szkice autora — decyzja świadoma: NIGDY nie wchodzą do tej nawigacji
    // -----------------------------------------------------------------

    /**
     * Szkic nie ma `published_at`, więc nie da się go ułożyć chronologicznie
     * między opublikowanymi zdjęciami — nawigacja ma zostać ślepa na niego,
     * nawet dla samego autora. Szkic ma swoją drogę: ekran edycji.
     */
    public function test_autor_nie_widzi_wlasnego_szkicu_jako_sasiada(): void
    {
        $autor = $this->user('kucharka');

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDay()]);
        Post::factory()->draft()->create(['author_id' => $autor->getKey()]);
        $b = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        $html = $this->actingAs($autor)->get(route('posts.show', $a))->assertOk()->getContent();

        $this->assertStringContainsString(route('posts.show', $b), $html);
        $this->assertStringContainsString('Następny wpis', $html);
    }

    /**
     * Wpis ukryty decyzją moderatora ZATRZYMUJE `published_at` (tylko status
     * się zmienia) — a mimo to nie wolno mu wejść do nawigacji NAWET własnemu
     * autorowi. `PostPolicy::view()` pozwala autorowi otworzyć swój ukryty
     * wpis WPROST pod adresem (`!isPublished() → tylko autor`), ale archiwum
     * chronologiczne to inna droga: `ProfileController::postsFor()` woła
     * `->published()` BEZWARUNKOWO, także dla właściciela profilu — ten sam
     * wpis nie stoi w zakładce „Wszystko" na własnym profilu, więc nie ma
     * też prawa stać w tej nawigacji. Jedna reguła, jedno miejsce.
     */
    public function test_autor_nie_widzi_wlasnego_ukrytego_wpisu_jako_sasiada(): void
    {
        $autor = $this->user('kucharka');

        $a = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDays(2)]);
        $ukryty = $this->wpisZeZdjeciem($autor, [
            'published_at' => now()->subDay(),
            'status' => Post::STATUS_HIDDEN,
        ]);
        $c = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);

        $html = $this->actingAs($autor)->get(route('posts.show', $a))->assertOk()->getContent();

        $this->assertStringContainsString(route('posts.show', $c), $html);
        $this->assertStringNotContainsString(route('posts.show', $ukryty), $html);
    }

    /** Wpis oglądany wprost pod adresem, który sam jest szkicem — bez nawigacji, bez zapytania o sąsiadów. */
    public function test_szkic_oglądany_przez_autora_nie_ma_nawigacji(): void
    {
        $autor = $this->user('kucharka');
        $szkic = Post::factory()->draft()->create(['author_id' => $autor->getKey()]);

        $html = $this->actingAs($autor)->get(route('posts.show', $szkic))->assertOk()->getContent();

        $this->assertStringNotContainsString('Poprzedni wpis', $html);
        $this->assertStringNotContainsString('Następny wpis', $html);
    }

    // -----------------------------------------------------------------
    // Wydajność — liczba zapytań NIE rośnie z liczbą wpisów autora
    // -----------------------------------------------------------------

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wpisow_autora(): void
    {
        $autor = $this->user('plodna');
        $wpisy = collect(range(1, 3))
            ->map(fn (int $i) => $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDays(30 - $i)]))
            ->values();

        $srodkowyMalo = $wpisy[1];

        DB::enableQueryLog();
        $this->get(route('posts.show', $srodkowyMalo))->assertOk();
        $zapytaniaMalo = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Ten sam autor, ale ze SPORO większym archiwum — środkowy wpis
        // ma teraz kilkanaście sąsiadów PRZED i PO sobie w bazie, nie po
        // jednym. Zapytanie zawężone przez indeks (`posts_author_published_idx`)
        // i `LIMIT 1` ma kosztować tyle samo niezależnie od tego, ile wpisów
        // stoi z każdej strony.
        $autor2 = $this->user('bardzoPlodna');
        $wiele = collect(range(1, 25))
            ->map(fn (int $i) => $this->wpisZeZdjeciem($autor2, ['published_at' => now()->subDays(50 - $i)]))
            ->values();
        $srodkowyDuzo = $wiele[12];

        DB::enableQueryLog();
        $this->get(route('posts.show', $srodkowyDuzo))->assertOk();
        $zapytaniaDuzo = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            $zapytaniaMalo,
            $zapytaniaDuzo,
            "Strona wpisu wykonała {$zapytaniaMalo} zapytań przy 3 wpisach autora i {$zapytaniaDuzo} przy 25 — ".
            'liczba zapytań rośnie z liczbą wpisów autora.',
        );
    }
}
