<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * MEDIA-03 (#286), KROK 2 i 3 — TEST REGRESYJNY liczby zapytań, i to na
 * MECHANIZMIE, nie tylko na sumie.
 *
 * CO BYŁO ZMIERZONE PRZED TĄ ZMIANĄ
 * `PomiarZapytanAutoryzacjiZdjeciaTest` (krok 1, ten plik go nie zastępuje)
 * zmierzył 10.09.2026: jedno przekierowanie zdjęcia dla zwykłego widza to
 * **15 zapytań**, a strona feedu z trzydziestoma zdjęciami — **450**.
 * Rozbicie: `docs/research/2026-09-10-pomiar-zapytan-zdjecia.md`. Dwie
 * przyczyny, obie usunięte tą zmianą:
 *
 *  1. `DostepDoZdjecia::rodzice()` wykonywało PIĘĆ stałych `SELECT`-ów
 *     (wpisy, „Ugotowałem", profil, przepisy, kroki) niezależnie od tego,
 *     które z tych tabel w ogóle wspominają to zdjęcie;
 *  2. `MediaController` wołało resolver DRUGI RAZ, jako anonim, wyłącznie
 *     po to, żeby rozstrzygnąć nagłówek `Cache-Control` — czyli budowało
 *     cały graf uprawnień dwa razy na jedno żądanie.
 *
 * DLACZEGO SAMA SUMA ZAPYTAŃ TO ZA MAŁO NA TEST REGRESYJNY
 * Suma spada też po zmianie, która NIE dotknęła resolvera (issue #286
 * ostrzega przed tym wprost), i rośnie od rzeczy niezwiązanych z zdjęciami
 * (sesja, wiązanie trasy, przyszła kolumna w `users`). Dlatego ten plik
 * liczy osobno zapytania PO TABELACH rodziców: „ile razy pytaliśmy tabelę
 * `posts`" odpowiada wprost na pytanie „czy graf rodziców budujemy raz",
 * a „ile razy pytaliśmy `recipe_steps`" — na pytanie „czy pytamy tabele,
 * które tego zdjęcia nawet nie mają".
 *
 * NAJWAŻNIEJSZA POŁOWA TEGO PLIKU JEST NA DOLE
 * Optymalizacja liczby zapytań w kodzie autoryzacyjnym jest o jedną pomyłkę
 * od dziury: wystarczy, żeby wspólne stało się nie „wiersze rodziców", ale
 * „decyzja". Dlatego niżej stoją testy pilnujące, że odpowiedzi na DWA różne
 * pytania („czy może TEN widz", „czy zobaczyłby anonim") nadal się nie
 * skleiły — w obie strony, bo rozjeżdżają się w obie: zablokowany nie widzi
 * zdjęcia, które anonim widzi, a autor widzi zdjęcie swojego prywatnego
 * przepisu, którego anonim nie widzi.
 *
 * Pełna macierz widoczności zdjęć (pięć rodzajów rodzica × pięciu widzów)
 * ma własny plik i nie jest tu powtarzana: `ZdjeciaChronioneNieWyciekajaTest`.
 */
class AutoryzacjaZdjeciaJednymPrzejsciemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PRÓG. Zmierzone po tej zmianie: DOKŁADNIE 6 zapytań na jedno
     * przekierowanie zdjęcia dla zalogowanego widza nie-właściciela —
     * wiązanie trasy, zapis ostatniej wizyty (jedno i drugie spoza
     * autoryzacji), rozpoznanie tabel rodziców, wczytanie wpisu, autor wpisu
     * dla `PostPolicy`, sprawdzenie blokady. Przed zmianą: 15.
     *
     * Próg 7, nie 6, zostawia zapas dokładnie na jedno zapytanie — tyle, żeby
     * nowa kolumna albo inna kolejność nie oblewały testu przy pierwszej
     * okazji, i za mało, żeby przemycić powrót któregokolwiek z dwóch
     * mechanizmów wyżej (drugie przejście grafu to +6, pięć stałych
     * `SELECT`-ów to +3).
     */
    private const PROG_ZAPYTAN = 7;

    // -----------------------------------------------------------------
    //  Liczba zapytań — na mechanizmie, nie tylko na sumie
    // -----------------------------------------------------------------

    public function test_graf_rodzicow_budowany_jest_raz_na_zadanie(): void
    {
        [$adres, , $widz] = $this->zdjeciePubliczengoWpisu();

        $zapytania = $this->zapytaniaZadania($adres, $widz);

        // KONTROLA DODATNIA (PULAPKI_TESTOW.md #4). Bez niej cały ten test
        // przechodziłby na odmowie: 404 też jest tanie w zapytaniach, a i tak
        // niczego nie dowodzi o autoryzacji, która się udała.
        $this->assertSame(
            1,
            $this->ile($zapytania, 'posts'),
            'Tabela `posts` miała być zapytana DOKŁADNIE raz. Zero znaczy, że '.
            'żądanie wcale nie doszło do autoryzacji (ten test nic wtedy nie '.
            'mierzy). Dwa znaczy powrót usterki z #286: `MediaController` woła '.
            'resolver drugi raz jako anonim i buduje cały graf uprawnień od nowa.',
        );

        $this->assertCount(
            1,
            array_filter($zapytania, fn (string $sql): bool => str_contains($sql, 'as tabela')),
            'Rozpoznanie tabel rodziców ma być JEDNYM zapytaniem na żądanie.',
        );

        $ile = count($zapytania);

        $this->assertLessThanOrEqual(
            self::PROG_ZAPYTAN,
            $ile,
            "Jedno przekierowanie zdjęcia kosztowało {$ile} zapytań — więcej niż próg ".
            self::PROG_ZAPYTAN.'. Przed #286 było ich 15 i to jest dokładnie ta '.
            'regresja, której ten próg pilnuje: ta trasa jest najczęściej wołaną '.
            'w serwisie (jedna strona feedu to grubo ponad sto osobnych żądań), '.
            'więc każde dodatkowe zapytanie mnoży się przez sto.',
        );
    }

    public function test_nie_pytamy_tabel_ktore_tego_zdjecia_nawet_nie_maja(): void
    {
        [$adres, , $widz] = $this->zdjeciePubliczengoWpisu();

        $zapytania = $this->zapytaniaZadania($adres, $widz);

        // Kontrola dodatnia: tabela, która TO zdjęcie ma, musi być zapytana —
        // inaczej „zero zapytań do pozostałych" byłoby prawdą także wtedy,
        // gdyby resolver nie pytał o nic i cicho odmawiał wszystkiego.
        $this->assertSame(1, $this->ile($zapytania, 'posts'));

        foreach (['cooked_events', 'profiles', 'recipes', 'recipe_steps'] as $tabela) {
            $this->assertSame(
                0,
                $this->ile($zapytania, $tabela),
                "Zdjęcie wisi wyłącznie na wpisie, a mimo to zapytaliśmy `{$tabela}`. ".
                'Przed #286 pięć `SELECT`-ów po rodzicach wykonywało się ZAWSZE, '.
                'niezależnie od tego, która tabela to zdjęcie w ogóle ma.',
            );
        }
    }

    /**
     * TEN TEST PILNUJE ZAPYTANIA ROZPOZNAJĄCEGO, a nie wczesnego przerwania —
     * i jest tu właśnie dlatego, że test wyżej tego NIE robi.
     *
     * ZMIERZONE KONTROLĄ UJEMNĄ, KTÓRA NIE OBLAŁA (PULAPKI_TESTOW.md #3):
     * sabotaż „zignoruj wynik zapytania rozpoznającego i przejdź wszystkie
     * pięć tabel" przeszedł test wyżej na ZIELONO. Powód nie jest usterką
     * testu: dla zdjęcia WPISU rodzic z pierwszej tabeli odpowiada od razu na
     * oba pytania, pętla przerywa się na nim, a generator po prostu nigdy nie
     * dochodzi do pozostałych tabel. Tamten test pilnuje więc przerwania
     * pętli, i dobrze — ale o rozpoznawaniu tabel nie mówi nic.
     *
     * AWATAR to przypadek, w którym przerwanie nie pomaga: `profiles` jest
     * TRZECIĄ tabelą na liście, więc bez rozpoznania trzeba by najpierw
     * zapytać `posts` i `cooked_events`. Zdjęć profilowych jest na stronie
     * feedu tyle samo co wpisów (każda karta ma awatar autora), więc to nie
     * jest przypadek brzegowy, tylko druga połowa ruchu na tej trasie.
     */
    public function test_awatar_nie_przeglada_tabel_stojacych_przed_profilami(): void
    {
        Storage::fake('public');

        $wlasciciel = $this->user('wlascicielka_awatar_media03');
        $obcy = $this->user('obca_awatar_media03');

        $zdjecie = $this->zdjecie($wlasciciel);
        $wlasciciel->profile->update(['avatar_media_id' => $zdjecie->getKey()]);

        $zapytania = $this->zapytaniaZadania($zdjecie->url('feed'), $obcy);

        // Kontrola dodatnia: awatar naprawdę został znaleziony przez
        // `profiles`, więc „zero zapytań do tabel przed nią" nie jest prawdą
        // z powodu odmowy albo pustego wyniku.
        $this->assertSame(
            1,
            $this->ile($zapytania, 'profiles'),
            'Awatar ma być znaleziony jednym zapytaniem do `profiles`.',
        );

        foreach (['posts', 'cooked_events', 'recipes', 'recipe_steps'] as $tabela) {
            $this->assertSame(
                0,
                $this->ile($zapytania, $tabela),
                "Awatar wisi wyłącznie na `profiles`, a mimo to zapytaliśmy `{$tabela}`. ".
                'Przed #286 pięć `SELECT`-ów po rodzicach wykonywało się ZAWSZE, '.
                'w stałej kolejności, więc awatar płacił za przejrzenie wpisów '.
                'i wykonań „Ugotowałem", w których go nie ma.',
            );
        }

        $this->assertLessThanOrEqual(self::PROG_ZAPYTAN, count($zapytania));
    }

    /**
     * Zdjęcie OSIEROCONE — wgrane i jeszcze nieprzypięte do niczego (normalny
     * stan w trakcie wypełniania formularza).
     *
     * Właściciel przechodzi skrótem, przed odpytaniem bazy o rodziców, więc
     * odpowiedź na pytanie „czy ON może" nie kosztuje ani jednego zapytania.
     * Ale drugie pytanie — „czy zobaczyłby to anonim", od którego zależy
     * `Cache-Control` — zostaje i musi coś kosztować: przed #286 kosztowało
     * PIĘĆ `SELECT`-ów po tabelach, z których żadna tego zdjęcia nie miała,
     * teraz JEDNO zapytanie rozpoznające, które odpowiada „żadna" — i na tym
     * koniec.
     */
    public function test_zdjecie_osierocone_nie_przeglada_pieciu_pustych_tabel(): void
    {
        Storage::fake('public');

        $wlasciciel = $this->user('wlascicielka_media03');
        $zdjecie = $this->zdjecie($wlasciciel);

        $zapytania = $this->zapytaniaZadania($zdjecie->url('feed'), $wlasciciel);

        foreach (['posts', 'cooked_events', 'profiles', 'recipes', 'recipe_steps'] as $tabela) {
            $this->assertSame(
                0,
                $this->ile($zapytania, $tabela),
                "Zdjęcie nie jest przypięte do niczego, a mimo to wczytywaliśmy wiersze z `{$tabela}`.",
            );
        }

        $this->assertCount(
            1,
            array_filter($zapytania, fn (string $sql): bool => str_contains($sql, 'as tabela')),
            'Rozpoznanie tabel ma się wykonać dokładnie raz — i dla zdjęcia bez '.
            'rodzica ma być JEDYNYM zapytaniem o rodziców w całym żądaniu.',
        );

        // Kontrola dodatnia: żądanie naprawdę przeszło autoryzację (właściciel
        // widzi swoje zdjęcie) i naprawdę zostało uznane za NIEpubliczne —
        // bez tej pary „zero zapytań do tabel rodziców" byłoby prawdą także
        // dla 404, które do resolvera w ogóle nie doszło.
        $odpowiedz = $this->actingAs($wlasciciel)->get($zdjecie->url('feed'));
        $odpowiedz->assertStatus(302);
        $this->assertStringContainsString(
            'no-store',
            (string) $odpowiedz->headers->get('Cache-Control'),
            'Zdjęcie, którego anonim nie zobaczy, nie może wyjść z nagłówkiem '.
            'pozwalającym komukolwiek je przechować.',
        );
    }

    // -----------------------------------------------------------------
    //  Autoryzacja NIE osłabła — dwa pytania nadal są dwoma pytaniami
    // -----------------------------------------------------------------

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * „Widz to zobaczy" i „anonim to zobaczy" to dwie różne odpowiedzi, a po
     * #286 liczone są w JEDNYM przejściu po tych samych wierszach rodziców.
     * Gdyby ktoś (albo ja) skleił je w jedną — choćby „widz widzi, więc
     * wolno dać publiczny cache" — zdjęcie treści „tylko obserwujący"
     * poleciałoby do wspólnego cache Cloudflare z `public, max-age`. To jest
     * ten sam wyciek, który cała trasa `media.show` naprawia, tylko o warstwę
     * wyżej i bez jednego czerwonego testu, jeśli tego testu nie ma.
     *
     * Para asercji, nie jedna: „followers" nie może dostać publicznego
     * cache, „public" może go dostać wyłącznie dla anonima bez sesji.
     * Sama asercja negatywna przechodziłaby także wtedy,
     * gdyby nagłówek `public` nie pojawiał się NIGDY.
     */
    public function test_zdjecie_tylko_dla_obserwujacych_nie_dostaje_publicznego_cache(): void
    {
        Storage::fake('public');

        $autor = $this->user('autorka_cache_media03');
        $obserwujacy = $this->user('obserwujaca_cache_media03');
        app(FollowUser::class)->handle($obserwujacy, $autor);

        $tylkoObserwujacy = $this->zdjecieWpisu($autor, Post::VISIBILITY_FOLLOWERS);
        $publiczne = $this->zdjecieWpisu($autor, Post::VISIBILITY_PUBLIC);

        $odpowiedzWaska = $this->actingAs($obserwujacy)->get($tylkoObserwujacy);
        $odpowiedzWaska->assertStatus(302);
        $cacheWaski = (string) $odpowiedzWaska->headers->get('Cache-Control');

        $this->assertStringNotContainsString(
            'public',
            $cacheWaski,
            'Zdjęcie wpisu „tylko obserwujący" dostało nagłówek pozwalający na '.
            'wspólny cache. Odpowiedź zależna od tego, KTO pyta, nie może iść '.
            'do cache dzielonego z kimkolwiek innym.',
        );
        $this->assertStringContainsString('no-store', $cacheWaski);

        // Kontrola dodatnia — nagłówek `public` w ogóle się pojawia, więc
        // asercja wyżej sprawdza różnicę, a nie martwy mechanizm.
        Auth::forgetGuards();
        $this->app['session']->flush();
        $odpowiedzSzeroka = $this->get($publiczne);
        $odpowiedzSzeroka->assertStatus(302);
        $this->assertStringContainsString(
            'public',
            (string) $odpowiedzSzeroka->headers->get('Cache-Control'),
            'Zdjęcie wpisu publicznego PRZESTAŁO dostawać wspólny cache — to jest '.
            'druga strona tej samej pomyłki: przy stu żądaniach na stronę feedu '.
            'brak cache jest realnym kosztem, nie ostrożnością.',
        );
    }

    public function test_nie_wlasciciel_nie_wchodzi_na_zdjecie_prywatnego_wpisu(): void
    {
        Storage::fake('public');

        $autor = $this->user('autorka_prywatna_media03');
        $obcy = $this->user('obca_prywatna_media03');

        $adres = $this->zdjecieWpisu($autor, Post::VISIBILITY_PRIVATE);

        // Odmowa to 404, nie 403 — patrz `MediaController`.
        $this->actingAs($obcy)->get($adres)->assertStatus(404);

        // Kontrola dodatnia: to samo zdjęcie, ten sam adres, autor — 302.
        // Bez tej pary „404" byłoby prawdą także dla zdjęcia, którego nie ma
        // (zła ścieżka, brak plików wariantów na udawanym dysku).
        $this->actingAs($autor)->get($adres)->assertStatus(302);
    }

    public function test_blokada_nadal_odcina_zdjecie_publicznego_wpisu(): void
    {
        Storage::fake('public');

        $autor = $this->user('autorka_blokada_media03');
        $zablokowany = $this->user('zablokowana_media03');
        $obcy = $this->user('obca_blokada_media03');

        app(BlockUser::class)->handle($autor, $zablokowany);

        $adres = $this->zdjecieWpisu($autor, Post::VISIBILITY_PUBLIC);

        $this->actingAs($zablokowany)->get($adres)->assertStatus(404);

        // Kontrola dodatnia: wpis jest PUBLICZNY, więc ktokolwiek inny go
        // widzi. Bez tego test przechodziłby też wtedy, gdyby zdjęcie było
        // niedostępne dla wszystkich.
        $this->actingAs($obcy)->get($adres)->assertStatus(302);
    }

    /**
     * ZAPYTANIE ROZPOZNAJĄCE TABELE JEST TYLKO WSKAZÓWKĄ, NIE ODPOWIEDZIĄ.
     *
     * Pivot `post_media` przeżywa miękkie skasowanie wpisu — wiersz w nim
     * zostaje. Dodane w #286 zapytanie `UNION` powie więc „zajrzyj do
     * `posts`" także dla wpisu, którego autor już usunął. O tym, czy rodzic
     * w ogóle istnieje, decyduje dopiero wczytanie modelu Eloquentem, z jego
     * globalnym scope'em `SoftDeletes` — i to jest jedyna rzecz, która tu
     * odmawia. Gdyby ktoś „przyspieszył" resolver, ufając rozpoznaniu tabel
     * (albo dokładając `withTrashed()`, żeby uniknąć pustego wyniku), zdjęcie
     * usuniętego wpisu wróciłoby pod stałym adresem.
     *
     * TEN TEST POWSTAŁ Z KONTROLI UJEMNEJ, KTÓRA NIE OBLAŁA.
     * Sabotaż `Post::query()` → `Post::withTrashed()` w resolverze przeszedł
     * cały `ZdjeciaChronioneNieWyciekajaTest` na zielono: tamten plik pokrywa
     * decyzje MODERACYJNE (`hidden`, `removed`), a nie miękkie skasowanie
     * wpisu przez autora. To była luka w pokryciu, nie zły sabotaż — więc
     * zamiast uznać kontrolę za nieudaną, brakująca asercja stanęła tutaj.
     */
    public function test_zdjecie_wpisu_skasowanego_przez_autora_nie_wraca_przez_pivot(): void
    {
        Storage::fake('public');

        $autor = $this->user('autorka_usuniety_media03');
        $obcy = $this->user('obca_usuniety_media03');

        $zdjecie = $this->zdjecie($autor);

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $adres = $zdjecie->url('feed');

        // Kontrola dodatnia PRZED skasowaniem: adres działa, więc 404 niżej
        // jest skutkiem skasowania wpisu, a nie złego adresu albo braku
        // plików wariantów.
        $this->actingAs($obcy)->get($adres)->assertStatus(302);

        $wpis->delete();

        // Wiersz w `post_media` nadal jest — to jest warunek, dla którego ten
        // test cokolwiek sprawdza. Bez tej asercji `cascade` na kluczu obcym
        // mógłby po cichu usunąć powiązanie i 404 niżej brałoby się z pustego
        // pivotu, nie ze scope'u `SoftDeletes`.
        $this->assertDatabaseHas('post_media', [
            'post_id' => $wpis->getKey(),
            'media_id' => $zdjecie->getKey(),
        ]);

        $this->actingAs($obcy)->get($adres)->assertStatus(404);
    }

    // -----------------------------------------------------------------
    //  Dwie listy tabel w jednej klasie nie mogą się rozjechać
    // -----------------------------------------------------------------

    /**
     * `DostepDoZdjecia::ODWOLANIA` (pilnowane osobno, że pokrywa
     * `KasujZdjecie::ODWOLANIA`) mówi, CO może wskazywać na `media`.
     * `KOLUMNY_WSKAZUJACE` mówi, GDZIE resolver naprawdę zagląda. Tabela
     * obecna tylko w pierwszej liście znaczy rodzica pomijanego przy szukaniu
     * — czyli zdjęcie niewidoczne dla wszystkich poza właścicielem, bez
     * jednego czerwonego testu.
     */
    public function test_lista_tabel_nie_rozjechala_sie_z_odwolaniami(): void
    {
        /** @var array<string, list<string>> $kolumny */
        $kolumny = (new ReflectionClass(DostepDoZdjecia::class))
            ->getConstant('KOLUMNY_WSKAZUJACE');

        $zOdwolan = [];
        foreach (DostepDoZdjecia::ODWOLANIA as [$tabela, $kolumna]) {
            $zOdwolan[$tabela][] = $kolumna;
        }

        ksort($zOdwolan);
        ksort($kolumny);

        $this->assertSame(
            $zOdwolan,
            $kolumny,
            'Lista tabel, w których resolver szuka rodziców, rozjechała się '.
            'z `DostepDoZdjecia::ODWOLANIA`. Nowe odwołanie do `media` musi '.
            'trafić w OBA miejsca, inaczej zdjęcie zostanie bez rodzica.',
        );

        // Skan nie może przechodzić na pustej liście (PULAPKI_TESTOW.md #2).
        $this->assertGreaterThanOrEqual(5, count($kolumny));
    }

    // -----------------------------------------------------------------
    //  Pomocnicze
    // -----------------------------------------------------------------

    /**
     * Zapytania SQL JEDNEGO żądania o zdjęcie.
     *
     * Żądanie „na rozgrzewkę" przed pomiarem jest tu po to, żeby nie mierzyć
     * jednorazowych kosztów pierwszego wejścia (zapis sesji, pierwszy odczyt
     * konfiguracji) — interesuje nas koszt KOLEJNEGO z setki żądań, jakie
     * przeglądarka wysyła na jednej stronie feedu.
     *
     * @return list<string>
     */
    private function zapytaniaZadania(string $adres, User $widz): array
    {
        $this->actingAs($widz);
        $this->get($adres);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($adres);

        $zapytania = array_map(
            fn (array $wpis): string => (string) $wpis['query'],
            DB::getQueryLog(),
        );

        DB::disableQueryLog();
        DB::flushQueryLog();

        return array_values($zapytania);
    }

    /**
     * Ile zapytań pytało WPROST o tę tabelę jako o źródło wiersza rodzica.
     *
     * Liczone po `from "tabela"` na początku zapytania, nie po samym
     * wystąpieniu nazwy: nazwa `profiles` pojawia się także w zapytaniu
     * rozpoznającym tabele i w podzapytaniach, a to nie jest wczytanie
     * rodzica. Bez tego rozróżnienia test liczyłby to samo słowo skądinąd
     * (PULAPKI_TESTOW.md #1).
     *
     * @param  list<string>  $zapytania
     */
    private function ile(array $zapytania, string $tabela): int
    {
        return count(array_filter(
            $zapytania,
            fn (string $sql): bool => (bool) preg_match(
                '/^select \*? ?(.*)?from "'.preg_quote($tabela, '/').'"/',
                $sql,
            ),
        ));
    }

    /**
     * @return array{0: string, 1: User, 2: User} adres zdjęcia, autor, widz
     */
    private function zdjeciePubliczengoWpisu(): array
    {
        Storage::fake('public');

        $autor = $this->user('autorka_pomiar_media03');
        $widz = $this->user('widzka_pomiar_media03');

        return [$this->zdjecieWpisu($autor, Post::VISIBILITY_PUBLIC), $autor, $widz];
    }

    private function zdjecieWpisu(User $autor, string $widocznosc): string
    {
        $zdjecie = $this->zdjecie($autor);

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => $widocznosc,
        ]);

        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return $zdjecie->url('feed');
    }

    /**
     * Gotowe zdjęcie z prawdziwymi bajtami wariantów na udawanym dysku — ten
     * sam wzorzec co `ZdjeciaChronioneNieWyciekajaTest`. Pliki muszą naprawdę
     * istnieć, bo na dysku bez podpisów kontroler sprawdza `exists()`.
     */
    private function zdjecie(User $wlasciciel): Media
    {
        $identyfikator = Str::uuid()->toString();
        $warianty = [];

        foreach (config('kuking.media.variants') as $nazwa => $krawedz) {
            $klucz = 'media/'.$identyfikator.'_'.$nazwa.'.webp';
            $warianty[$nazwa] = ['key' => $klucz, 'width' => $krawedz, 'height' => $krawedz];
            Storage::disk('public')->put($klucz, 'udawane-bajty-'.$nazwa);
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }
}
