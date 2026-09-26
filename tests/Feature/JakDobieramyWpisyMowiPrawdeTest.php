<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Feed\JakDobieramyWpisy;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\DailyPick;
use App\Models\Hide;
use App\Models\Post;
use App\Models\PostReaction;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Strona „Jak dobieramy wpisy" mówi to, co robi kod (#1811, D-305).
 *
 * WZÓR: `TabelaStackuMowiPrawdeTest`. Tam każdy wiersz tabeli stacku musi
 * powiedzieć, gdzie rzecz jest, i test to sprawdza. Tu każde ZDANIE strony
 * (`App\Domain\Feed\JakDobieramyWpisy`) ma w rejestrze `DOWODY` co najmniej
 * jeden dowód. Dozwolone są trzy kształty i nic poza nimi:
 *
 *     test: Klasa::metoda   → metoda testu istnieje (i chodzi w tym samym CI)
 *     kod: ścieżka :: fragment → plik istnieje i zawiera fragment dosłownie
 *     config: klucz          → klucz istnieje, a jego liczba stoi w zdaniu
 *
 * Kształt czwarty oblewa. Zdanie bez dowodu oblewa. Dowód bez zdania oblewa.
 * Liczba w zdaniu bez dowodu `config:` z tą liczbą oblewa. Akapit w bloku
 * opisu, który nie pochodzi z rejestru (dopisany wprost w widoku), oblewa.
 *
 * DLACZEGO TO WYSTARCZA, A CZEGO NIE ZROBI
 * Fragment kodu dowodzi, że mechanizm istnieje, a test zachowania — że działa.
 * Nie dowodzi, że zdanie dobrze go OPISUJE: to czyta człowiek przy przeglądzie,
 * z tym plikiem obok. Test pilnuje za to rzeczy, które psują się po cichu:
 * ktoś zmienia rotację, usuwa test albo przepisuje zapytanie — i zdanie o tym,
 * czego już nie ma, zapala się na czerwono. Najważniejsze obietnice („reakcje
 * nie zmieniają kolejności", „wybór gospodarza jest podpisany", „nie
 * zapisujemy, co kto ogląda") mają tu własne testy zachowania.
 */
class JakDobieramyWpisyMowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klucz zdania → dowody. Ścieżki względem katalogu repozytorium.
     *
     * @var array<string, list<string>>
     */
    private const DOWODY = [
        'start.zrodla' => [
            'test: Tests\Feature\StartOsobyITagiRazemTest::test_osoby_i_tagi_razem_po_czasie_bez_duplikatow',
            'kod: app/Domain/Feed/FollowingFeed.php :: [...$followedIds, $viewer->getKey()]',
            "kod: app/Domain/Feed/FollowingFeed.php :: ->orderByDesc('published_at')",
        ],
        'start.podpis' => [
            'test: Tests\Feature\StartOsobyITagiRazemTest::test_podpis_tylko_przy_wpisach_z_samego_tagu',
            'kod: resources/views/components/post-card.blade.php :: Z tagu:',
        ],
        'start.serie' => [
            'test: Tests\Feature\ZwijanieSeriiWObserwowanychTest::test_seria_jednej_osoby_zwija_sie_bez_zmiany_kolejnosci_i_bez_utraty',
            'kod: app/Domain/Feed/SerieWpisow.php :: WIDOCZNE_Z_SERII = 2',
            'kod: resources/views/pages/home.blade.php :: — Pokaż',
        ],
        'start.pusty' => [
            'test: Tests\Feature\StartOsobyITagiRazemTest::test_same_niewidoczne_wpisy_z_tagu_to_pusty_start',
            "kod: app/Http/Controllers/FeedController.php :: isEmptyFor(\$user) ? 'odkrywanie' : 'obserwowani'",
        ],
        'odkrywanie.rotacja' => [
            'test: Tests\Feature\OdkrywanieRotacjaAutorowTest::test_dziesiec_osob_po_piec_wpisow_to_piecdziesiat_kart_w_rundach',
            'kod: app/Domain/Feed/DiscoverFeed.php :: PARTITION BY posts.author_id ORDER BY posts.published_at DESC',
        ],
        'odkrywanie.rownosc' => [
            'test: Tests\Feature\OdkrywanieRotacjaAutorowTest::test_seria_jednej_osoby_nie_zaslania_innych_ale_nie_ginie',
            'test: Tests\Feature\JakDobieramyWpisyMowiPrawdeTest::test_reakcje_ugotowalem_i_komentarze_nie_zmieniaja_kolejnosci',
            'test: Tests\Feature\FeedNieSortujePoMierzeReakcjiTest::test_zaden_feed_nie_sortuje_po_mierze_cudzych_reakcji',
        ],
        'odkrywanie.ukryci' => [
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_ukryta_osoba_znika_z_podsuwanych_miejsc_ale_nie_z_tych_gdzie_widz_przyszedl_sam',
            'test: Tests\Feature\OdkrywanieRotacjaAutorowTest::test_blokada_i_zawieszenie_zdejmuja_autora_ale_nie_innych',
            'kod: app/Domain/Feed/DiscoverFeed.php :: ->bezUkrytychOsob($viewer)',
        ],
        'tablica.wybor' => [
            'test: Tests\Feature\JakDobieramyWpisyMowiPrawdeTest::test_wybor_gospodarza_ma_napis_a_uzupelnienie_automatu_nie',
            'kod: resources/views/components/kuking-board/posts.blade.php :: Wybór gospodarza',
            'kod: resources/views/components/kuking-board/people.blade.php :: Wybór gospodarza',
        ],
        'tablica.reszta' => [
            'test: Tests\Feature\DailyBoardTest::test_wybor_redakcyjny_stoi_pierwszy_a_reszte_dobiera_automat',
            'test: Tests\Feature\DailyBoardTest::test_maksymalnie_jeden_wpis_od_tej_samej_osoby',
            'test: Tests\Feature\DailyBoardTest::test_nie_proponuje_osob_juz_obserwowanych',
        ],
        'tablica.tagi' => [
            'kod: resources/views/pages/tags/index.blade.php :: <h2>Polecane tagi</h2>',
            'kod: resources/views/pages/tags/index.blade.php :: Wybór gospodarza',
        ],
        'szukaj.przepisy' => [
            'test: Tests\Feature\TrafnoscWyszukiwarkiTest::test_prawdziwe_trafienie_stoi_przed_podobnym_slowem',
            'kod: app/Domain/Search/SearchQuery.php :: word_similarity(?, recipes.title_search) DESC',
            "kod: app/Domain/Search/SearchQuery.php :: ->orderByDesc('published_at')",
        ],
        'szukaj.osoby' => [
            'kod: app/Domain/Search/SearchQuery.php :: similarity(profiles.display_name_search, ?) DESC',
        ],
        'list.zgoda' => [
            'test: Tests\Feature\ZgodaNaPrzegladNieJestDomyslnaTest::test_nowe_konto_z_rejestracji_nie_ma_zgody_na_przeglad',
            "kod: app/Domain/Digest/OdbiorcyDigestu.php :: ->where('wants_weekly_digest', true)",
        ],
        'list.czas' => [
            'test: Tests\Feature\ZwijanieSeriiWObserwowanychTest::test_tygodniowy_list_ma_najwyzej_jeden_wpis_na_autora',
            'kod: app/Domain/Digest/ZbierzTresciDigestu.php :: partition by follows.follower_id, posts.author_id order by posts.published_at desc',
        ],
        'ukrycia.co' => [
            'config: kuking.ukrycia.dni',
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_po_terminie_wraca_a_zostaw_ukryte_trzyma_bez_terminu',
        ],
        'ukrycia.gdzie' => [
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_ukryty_wpis_znika_ze_strumieni_i_zwija_sie_tam_gdzie_widz_przyszedl_sam',
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_ukryta_osoba_znika_ze_startu_takze_przez_obserwowany_tag',
        ],
        'ukrycia.obserwowani' => [
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_obserwowanej_osoby_sie_nie_ukrywa_menu_proponuje_przestan_obserwowac',
        ],
        'ukrycia.cofniecie' => [
            "kod: routes/web.php :: ->name('settings.hidden.restore')",
            'kod: resources/views/pages/settings/ukryte.blade.php :: Przywróć</button>',
        ],
        'nie.popularnosc' => [
            'test: Tests\Feature\FeedNieSortujePoMierzeReakcjiTest::test_zaden_feed_nie_sortuje_po_mierze_cudzych_reakcji',
            'test: Tests\Feature\FeedNieSortujePoMierzeReakcjiTest::test_kryterium_odroznia_czas_od_popularnosci',
        ],
        'nie.reakcje' => [
            'test: Tests\Feature\JakDobieramyWpisyMowiPrawdeTest::test_reakcje_ugotowalem_i_komentarze_nie_zmieniaja_kolejnosci',
            'test: Tests\Feature\FeedNieSortujePoMierzeReakcjiTest::test_zaden_feed_nie_sortuje_po_mierze_cudzych_reakcji',
        ],
        'nie.zachowanie' => [
            'test: Tests\Feature\JakDobieramyWpisyMowiPrawdeTest::test_serwis_nie_ma_gdzie_zapisac_co_kto_oglada',
        ],
        'nie.ukrycia' => [
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_bez_agregacji_moderacja_i_analityka_nie_czytaja_ukryc',
            'test: Tests\Feature\UkryjWpisIOsobeTest::test_eksport_ma_liste_ukryc_a_ukryta_osoba_nie_widzi_kto_ja_ukryl',
            'kod: app/Domain/Ukrycia/Ukrycia.php :: BEZ AGREGACJI',
        ],
    ];

    public function test_kazde_zdanie_ma_dowod_a_kazdy_dowod_zdanie(): void
    {
        $zdania = array_keys(JakDobieramyWpisy::zdania());
        $dowody = array_keys(self::DOWODY);

        $this->assertSame([], array_values(array_diff($zdania, $dowody)),
            'Zdanie na stronie „Jak dobieramy wpisy” bez dowodu w DOWODY. Dopisz, gdzie w kodzie to jest.');
        $this->assertSame([], array_values(array_diff($dowody, $zdania)),
            'Dowód bez zdania — zdanie zniknęło ze strony albo zmieniło klucz.');
    }

    /** @return array<string, array{0: string}> */
    public static function kluczeZdan(): array
    {
        return array_combine(array_keys(self::DOWODY), array_map(fn (string $k) => [$k], array_keys(self::DOWODY)));
    }

    #[DataProvider('kluczeZdan')]
    public function test_dowody_zdania_sa_prawdziwe(string $klucz): void
    {
        $this->assertNotEmpty(self::DOWODY[$klucz], "Zdanie {$klucz} ma pustą listę dowodów.");
        $zdanie = JakDobieramyWpisy::zdania()[$klucz] ?? '';
        $liczbyZKonfiguracji = [];

        foreach (self::DOWODY[$klucz] as $dowod) {
            if (preg_match('/^test: ([\w\\\\]+)::(test_\w+)$/u', $dowod, $m) === 1) {
                $this->assertTrue(class_exists($m[1]), "{$klucz}: nie ma klasy testu {$m[1]}.");
                $this->assertTrue(method_exists($m[1], $m[2]), "{$klucz}: nie ma testu {$m[1]}::{$m[2]} — usunięty albo przemianowany.");

                continue;
            }

            if (preg_match('/^kod: (\S+) :: (.+)$/u', $dowod, $m) === 1) {
                $sciezka = base_path($m[1]);
                $this->assertFileExists($sciezka, "{$klucz}: nie ma pliku {$m[1]}.");
                $this->assertStringContainsString($m[2], (string) file_get_contents($sciezka),
                    "{$klucz}: w {$m[1]} nie ma już „{$m[2]}”. Zdanie opisuje coś, czego kod nie robi — popraw jedno albo drugie.");

                continue;
            }

            if (preg_match('/^config: ([\w.]+)$/u', $dowod, $m) === 1) {
                $wartosc = config($m[1]);
                $this->assertIsInt($wartosc, "{$klucz}: klucza {$m[1]} nie ma albo nie jest liczbą.");
                $liczbyZKonfiguracji[] = (string) $wartosc;

                continue;
            }

            $this->fail("{$klucz}: dowód w nieznanym kształcie: {$dowod}. Dozwolone: test:, kod:, config:.");
        }

        preg_match_all('/\d+/u', $zdanie, $liczby);
        foreach ($liczby[0] as $liczba) {
            $this->assertContains($liczba, $liczbyZKonfiguracji,
                "{$klucz}: liczba {$liczba} w zdaniu nie ma dowodu „config:” z tą wartością.");
        }
    }

    /**
     * Strona rysuje WYŁĄCZNIE zdania z rejestru — każdy akapit bloku opisu
     * ma klucz, każdy klucz stoi na stronie, a tekst jest ten z klasy.
     */
    public function test_strona_pokazuje_wylacznie_zdania_z_rejestru(): void
    {
        $html = $this->get(route('feed-rules'))->assertOk()->getContent();
        $xpath = $this->xpath((string) $html);

        $blok = $xpath->query('//*[@data-jak-dobieramy]');
        $this->assertSame(1, $blok->length, 'Brak bloku data-jak-dobieramy na stronie.');

        $akapity = $xpath->query('.//p | .//li', $blok->item(0));
        $this->assertGreaterThan(0, $akapity->length);
        $zdania = JakDobieramyWpisy::zdania();
        $naStronie = [];

        foreach ($akapity as $akapit) {
            $klucz = $akapit->getAttribute('data-zdanie');
            $this->assertNotSame('', $klucz, 'Akapit bez klucza w bloku opisu: „'.trim($akapit->textContent).'”. Zdania dopisuje się w JakDobieramyWpisy, nie w widoku.');
            $this->assertArrayHasKey($klucz, $zdania);
            // Nazwa serwisu to komponent z tekstem dla oka i osobnym dla czytnika
            // (`.visually-hidden`) — porównujemy to, co widać.
            foreach (iterator_to_array($xpath->query('.//*[contains(@class, "visually-hidden")]', $akapit)) as $ukryty) {
                $ukryty->parentNode->removeChild($ukryty);
            }
            $widoczny = preg_replace(['/\s+/u', '/\s+([”.,])/u'], [' ', '$1'], trim($akapit->textContent));
            $this->assertSame(str_replace('{kuking}', 'kuKING', $zdania[$klucz]), $widoczny);
            $naStronie[] = $klucz;
        }

        $this->assertSame(array_keys($zdania), $naStronie);
    }

    /**
     * Kontrola dodatnia skanu: akapit wstawiony obok rejestru ma zapalić test
     * wyżej. Tu na sztucznym HTML-u — to dowód, że XPath widzi akapit bez klucza.
     */
    public function test_skan_widzi_akapit_spoza_rejestru(): void
    {
        $xpath = $this->xpath('<div data-jak-dobieramy><p data-zdanie="start.zrodla">a</p><p>dopisane w widoku</p></div>');
        $bezKlucza = 0;
        foreach ($xpath->query('//*[@data-jak-dobieramy]//p') as $p) {
            $bezKlucza += $p->getAttribute('data-zdanie') === '' ? 1 : 0;
        }

        $this->assertSame(1, $bezKlucza);
    }

    /**
     * Issue #1811: „Nigdzie zdania »nie mamy systemu rekomendacji«". Dobór
     * wpisów to system rekomendacji w rozumieniu DSA (art. 3 lit. s), tyle że
     * prosty i jawny — zaprzeczenie byłoby nieprawdą.
     */
    public function test_nigdzie_nie_twierdzimy_ze_nie_mamy_systemu_rekomendacji(): void
    {
        $wzorzec = '/nie\s+(?:mamy|ma|stosujemy|używamy|prowadzimy)\s+(?:żadnego\s+)?system\w*\s+rekomendac/iu';
        $pliki = [
            ...glob(resource_path('views/**/*.blade.php')) ?: [],
            ...glob(resource_path('views/*/*/*.blade.php')) ?: [],
            ...glob(resource_path('legal/*.md')) ?: [],
            base_path('app/Domain/Feed/JakDobieramyWpisy.php'),
        ];
        $this->assertGreaterThan(20, count($pliki), 'Skan nie znalazł plików — sprawdza pustkę.');

        foreach (array_unique($pliki) as $plik) {
            $this->assertDoesNotMatchRegularExpression($wzorzec, (string) file_get_contents($plik), "Zdanie o braku systemu rekomendacji w {$plik}.");
        }

        $this->assertMatchesRegularExpression($wzorzec, 'U nas nie ma systemu rekomendacji.', 'Wzorzec nie łapie zdania, które ma łapać.');
    }

    /**
     * „Ugotowałem", „Smakowicie wygląda" i komentarze nie przesuwają wpisu —
     * ani w „Świeżo z Kuking", ani na Starcie. Starszy wpis z górą reakcji
     * zostaje pod nowszym bez żadnej.
     */
    public function test_reakcje_ugotowalem_i_komentarze_nie_zmieniaja_kolejnosci(): void
    {
        $widz = $this->user('widz');
        $popularna = $this->user('popularna');
        $cicha = $this->user('cicha');
        $widz->following()->attach([$popularna->getKey(), $cicha->getKey()], ['created_at' => now()]);

        $przepis = Recipe::factory()->create(['author_id' => $popularna->getKey()]);
        $starszy = Post::factory()->create([
            'author_id' => $popularna->getKey(), 'body' => 'Starszy z reakcjami', 'published_at' => now()->subHours(3),
        ]);
        $nowszy = Post::factory()->create([
            'author_id' => $cicha->getKey(), 'body' => 'Nowszy bez reakcji', 'published_at' => now()->subHour(),
        ]);

        foreach (range(1, 5) as $i) {
            $fan = $this->user("fan{$i}");
            PostReaction::query()->insert(['post_id' => $starszy->getKey(), 'user_id' => $fan->getKey(), 'created_at' => now()]);
            CookedEvent::factory()->create(['user_id' => $fan->getKey(), 'recipe_id' => $przepis->getKey()]);
            Comment::factory()->create(['post_id' => $starszy->getKey(), 'author_id' => $fan->getKey()]);
        }

        $odkrywanie = (new DiscoverFeed)->paginate($widz, 20)->getCollection()->pluck('body')->all();
        $start = app(FollowingFeed::class)->paginate($widz, 20)->getCollection()->pluck('body')->all();

        $this->assertSame(['Nowszy bez reakcji', 'Starszy z reakcjami'], $odkrywanie);
        $this->assertSame(['Nowszy bez reakcji', 'Starszy z reakcjami'], $start);
        $this->assertSame(5, PostReaction::query()->where('post_id', $starszy->getKey())->count(), 'Fikstura nie zapisała reakcji — test nic nie mierzy.');
    }

    /**
     * „Nie uczymy się Twojego gustu z tego, co oglądasz": w bazie nie ma
     * miejsca na zapis, KTO OGLĄDAŁ KTÓRY WPIS. Sygnały produktowe nie mają
     * `post_id` (D-283), a żadna tabela nie nazywa się jak dziennik odsłon.
     * Kto chciałby to zmienić, musi dodać tabelę albo kolumnę — i ten test
     * razem ze zdaniem na stronie zapali się na czerwono.
     */
    public function test_serwis_nie_ma_gdzie_zapisac_co_kto_oglada(): void
    {
        $this->assertTrue(Schema::hasTable('product_signals'));
        $this->assertFalse(Schema::hasColumn('product_signals', 'post_id'), 'product_signals ma post_id — to jest dziennik tego, co kto ogląda.');

        $tabele = collect(DB::select('select table_name from information_schema.tables where table_schema = current_schema()'))
            ->pluck('table_name')->all();
        $this->assertContains('posts', $tabele, 'Skan tabel nic nie widzi.');

        $podejrzane = preg_grep('/view|odslon|odsłon|impression|wyswietl|wyświetl|history|historia/iu', $tabele);
        $this->assertSame([], array_values($podejrzane), 'Tabela wyglądająca na dziennik odsłon: '.implode(', ', $podejrzane));
    }

    /**
     * AGENTS.md §8: wybór gospodarza oznaczony w interfejsie jako jego wybór.
     * Pozycja z wyboru ma napis, pozycja dobrana potem przez automat — nie.
     */
    public function test_wybor_gospodarza_ma_napis_a_uzupelnienie_automatu_nie(): void
    {
        $gospodarz = $this->moderator();
        $wybrana = $this->user('wybrana', ['display_name' => 'Wybrana Osoba']);
        $inna = $this->user('inna', ['display_name' => 'Inna Osoba']);
        $wpisWybrany = Post::factory()->create(['author_id' => $wybrana->getKey(), 'published_at' => now()->subDay()]);
        Post::factory()->create(['author_id' => $inna->getKey(), 'published_at' => now()]);

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $wpisWybrany->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        $html = (string) $this->get(route('discover'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $napisy = $xpath->query('//*[contains(@class, "kuking-board")]//*[@data-wybor-gospodarza]');

        $this->assertSame(1, $napisy->length, 'Napis „Wybór gospodarza” powinien stać dokładnie przy jednej pozycji — tej wybranej.');
        $this->assertSame('Wybór gospodarza', trim($napisy->item(0)->textContent));
        $karta = $napisy->item(0)->parentNode;
        $this->assertStringContainsString('Wybrana Osoba', $karta->textContent);
        $this->assertStringContainsString('Inna Osoba', $html, 'Automat nie uzupełnił tablicy — test nie widzi drugiej pozycji.');

        DailyPick::query()->delete();
        $this->assertStringNotContainsString('data-wybor-gospodarza', (string) $this->get(route('discover'))->getContent(),
            'Bez wyboru gospodarza napis nie może się pokazać.');
    }

    public function test_pod_swiezo_z_kuking_stoi_linia_a_przy_ukryciach_liczba_i_zmien(): void
    {
        $this->get(route('discover'))->assertOk()
            ->assertSee('Skąd te wpisy i jak to zmienić')
            ->assertSee(route('feed-rules'), false)
            ->assertDontSee('data-linia-ukryc', false);

        $widz = $this->user('widz');
        $this->actingAs($widz)->get(route('discover'))->assertDontSee('data-linia-ukryc', false);

        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->getKey()]);
        $this->ukryj($widz, ['post_id' => $wpis->getKey()]);
        $this->actingAs($widz)->get(route('discover'))->assertSee('Ukrywasz 1 wpis.');

        $this->ukryj($widz, ['hidden_user_id' => $this->user('ukryta_ala')->getKey()]);
        $this->ukryj($widz, ['hidden_user_id' => $this->user('ukryta_ola')->getKey()]);
        $this->actingAs($widz)->get(route('discover'))
            ->assertSee('Ukrywasz wpisy 2 osób.')
            ->assertSee(route('settings.hidden'), false);

        // Wygasłe ukrycie się nie liczy.
        Hide::query()->whereNotNull('hidden_user_id')->update(['hidden_until' => now()->subMinute()]);
        $this->actingAs($widz)->get(route('discover'))->assertSee('Ukrywasz 1 wpis.')->assertDontSee('Ukrywasz wpisy');
    }

    public function test_zalogowany_ma_droge_do_osob_tagow_i_ukrytych_a_gosc_do_logowania(): void
    {
        $this->get(route('feed-rules'))->assertOk()
            ->assertSee(route('login'), false)
            ->assertDontSee(route('settings.hidden'), false);

        $widz = $this->user('widz');
        $this->ukryj($widz, ['hidden_user_id' => $this->user('ukryta_ala')->getKey()]);

        $this->actingAs($widz)->get(route('feed-rules'))->assertOk()
            ->assertSee(route('social.following', 'widz'), false)
            ->assertSee(route('settings.tags'), false)
            ->assertSee(route('settings.hidden'), false)
            ->assertSee('Ukrywasz wpisy 1 osoby.');
    }

    public function test_regulamin_i_start_prowadza_na_strone(): void
    {
        $this->assertStringContainsString('/jak-dobieramy-wpisy', (string) file_get_contents(resource_path('legal/regulamin.md')));
        $this->get(route('terms'))->assertOk()->assertSee('Jak dobieramy wpisy');
        $this->assertSame(url('/jak-dobieramy-wpisy'), route('feed-rules'));

        $this->actingAs($this->user('widz'))->get(route('home'))->assertOk()->assertSee(route('feed-rules'), false);
    }

    /** @param  array<string, string>  $co */
    private function ukryj(User $widz, array $co): void
    {
        Hide::query()->insert([
            'user_id' => $widz->getKey(),
            'hidden_until' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
            ...$co,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
