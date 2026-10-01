<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\MojRok\MojRok;
use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * „Mój rok w kuchni” (#2353) — prywatne archiwum jednej osoby.
 *
 * Asercje czytają SAMĄ treść `<main>` (pułapka 1 z `docs/PULAPKI_TESTOW.md`):
 * tytuł przepisu stoi też w szynie „ostatnio odłożone”, a liczby w liczniku
 * powiadomień.
 */
class MojRokTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Środek roku, żeby „bieżący rok” był przewidywalny.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Warsaw'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function wykonanie(User $kto, Recipe $przepis, string $kiedy = '2026-03-10 12:00:00'): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kto->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => Carbon::parse($kiedy, 'Europe/Warsaw')->utc(),
        ]);
    }

    private function wpis(User $autor, string $kiedy = '2026-02-01 12:00:00', array $nadpisz = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'published_at' => Carbon::parse($kiedy, 'Europe/Warsaw')->utc(),
            ...$nadpisz,
        ]);
    }

    /** Sama zawartość `<main>`. */
    private function tresc(User $kto, string $adres = '/moj-rok'): string
    {
        $html = (string) $this->actingAs($kto)->get($adres)->assertOk()->getContent();

        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $main = (new DOMXPath($dokument))->query('//main')->item(0);

        $this->assertNotNull($main, 'Strona nie ma elementu <main>.');

        return (string) $dokument->saveHTML($main);
    }

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $this->get('/moj-rok')->assertRedirect(route('login'));
        $this->get('/moj-rok/2025')->assertRedirect(route('login'));
    }

    public function test_wlasciciel_widzi_liczby_i_najczesciej_gotowane(): void
    {
        $ja = $this->user();
        $inny = $this->user();
        $zupa = Recipe::factory()->create(['author_id' => $inny->getKey(), 'title' => 'Zupa ogórkowa']);
        $bigos = Recipe::factory()->create(['author_id' => $inny->getKey(), 'title' => 'Bigos myśliwski']);
        $jedno = Recipe::factory()->create(['author_id' => $inny->getKey(), 'title' => 'Tylko raz placek']);

        $this->wpis($ja);
        $this->wpis($ja, '2026-04-01 12:00:00');
        $this->wykonanie($ja, $zupa, '2026-01-10 12:00:00');
        $this->wykonanie($ja, $zupa, '2026-02-10 12:00:00');
        $this->wykonanie($ja, $zupa, '2026-03-10 12:00:00');
        $this->wykonanie($ja, $bigos, '2026-03-11 12:00:00');
        $this->wykonanie($ja, $bigos, '2026-03-12 12:00:00');
        $this->wykonanie($ja, $jedno, '2026-03-13 12:00:00');

        $tresc = $this->tresc($ja);

        $this->assertStringContainsString('Opublikowane dania: <strong>2</strong>', $tresc);
        $this->assertStringContainsString('Zaznaczone „Ugotowałem”: <strong>6</strong> razy', $tresc);
        $this->assertStringContainsString('Zupa ogórkowa', $tresc);
        $this->assertStringContainsString('3 razy', $tresc);
        $this->assertStringContainsString('Bigos myśliwski', $tresc);
        // Przepis ugotowany raz nie jest „gotowany najczęściej”.
        $this->assertStringNotContainsString('Tylko raz placek', $tresc);
        // Imię ani nazwa cudzego konta nie pojawia się nigdzie.
        $this->assertStringNotContainsString($inny->profile->display_name, $tresc);
    }

    public function test_cudze_dane_nie_wchodza_do_mojego_podsumowania_a_adres_nie_przyjmuje_konta(): void
    {
        $ja = $this->user();
        $inna = $this->user(null, ['display_name' => 'Zofia Obca']);
        $przepis = Recipe::factory()->create(['title' => 'Przepis wspólny']);

        $this->wpis($inna);
        $this->wpis($inna, '2026-02-02 12:00:00');
        $this->wykonanie($inna, $przepis);
        $this->wykonanie($inna, $przepis, '2026-03-11 12:00:00');
        $this->wykonanie($ja, $przepis, '2026-03-12 12:00:00');

        $tresc = $this->tresc($ja);

        $this->assertStringNotContainsString('Opublikowane dania', $tresc);
        $this->assertStringContainsString('Zaznaczone „Ugotowałem”: <strong>1</strong> raz', $tresc);
        $this->assertStringNotContainsString('Zofia Obca', $tresc);

        // Adres nie ma miejsca na identyfikator konta: dowolny dopisek jest
        // ignorowany, a trasa z identyfikatorem w miejscu roku nie istnieje.
        $this->actingAs($ja)->get('/moj-rok?user='.$inna->getKey().'&user_id='.$inna->getKey())->assertOk();
        $this->actingAs($ja)->get('/moj-rok/'.$inna->getKey())->assertNotFound();

        $this->assertStringNotContainsString(
            'Opublikowane dania',
            $this->tresc($ja, '/moj-rok?user='.$inna->getKey()),
        );
    }

    public function test_inna_osoba_widzi_wylacznie_swoje_podsumowanie(): void
    {
        $ja = $this->user();
        $ona = $this->user();

        $this->wpis($ja);
        $this->wpis($ja, '2026-02-02 12:00:00');
        $this->wpis($ja, '2026-02-03 12:00:00');

        $this->assertStringContainsString('Opublikowane dania: <strong>3</strong>', $this->tresc($ja));

        $cudze = $this->tresc($ona);
        $this->assertStringNotContainsString('Opublikowane dania', $cudze);
        $this->assertStringContainsString('W tym roku nic tu jeszcze nie ma', $cudze);
    }

    public function test_polityka_odmawia_kontom_zamknietym_a_wpuszcza_aktywne_i_zawieszone(): void
    {
        $zbanowany = $this->user();
        $zbanowany->ban();
        $doUsuniecia = $this->user();
        $doUsuniecia->markForDeletion();
        $aktywny = $this->user();
        $zawieszony = $this->user();
        $zawieszony->suspend();

        $werdykty = [];
        foreach (['zbanowany' => $zbanowany, 'do usunięcia' => $doUsuniecia, 'aktywny' => $aktywny, 'zawieszony' => $zawieszony] as $opis => $konto) {
            $werdykty[$opis] = Gate::forUser($konto->fresh())->allows('viewMyYear', User::class);
        }

        $this->assertSame([
            'zbanowany' => false,
            'do usunięcia' => false,
            'aktywny' => true,
            'zawieszony' => true,
        ], $werdykty);

        // Zamknięte konto nie dostaje ekranu także po HTTP: middleware wylogowuje
        // i odsyła do logowania (302), nie wpuszcza na stronę.
        $this->actingAs($zbanowany->fresh())->get('/moj-rok')->assertRedirect(route('login'));
    }

    public function test_konto_zawieszone_dalej_widzi_swoj_dorobek(): void
    {
        $ja = $this->user();
        $this->wpis($ja);
        $ja->suspend();

        $this->assertStringContainsString('Opublikowane dania: <strong>1</strong>', $this->tresc($ja->fresh()));
    }

    public function test_usuniety_prywatny_ukryty_i_zablokowany_przepis_znika_od_razu(): void
    {
        $ja = $this->user();
        $autorka = $this->user();
        $widoczny = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Zostaje na liście']);
        $usuniety = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Usunięty przepis']);
        $prywatny = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Prywatny cudzy']);
        $zaBlokada = Recipe::factory()->create(['author_id' => $this->user()->getKey(), 'title' => 'Za blokadą']);
        $moderacja = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Ukryty moderacją']);

        foreach ([$widoczny, $usuniety, $prywatny, $zaBlokada, $moderacja] as $przepis) {
            $this->wykonanie($ja, $przepis);
            $this->wykonanie($ja, $przepis, '2026-03-11 12:00:00');
        }

        $przed = $this->tresc($ja);
        $this->assertStringContainsString('Zaznaczone „Ugotowałem”: <strong>10</strong> razy', $przed);

        $usuniety->delete();
        $prywatny->forceFill(['visibility' => 'private'])->save();
        $moderacja->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        app(BlockUser::class)->handle($zaBlokada->author, $ja);

        $po = $this->tresc($ja);

        $poKrokach = [
            'liczba wykonań' => str_contains($po, 'Zaznaczone „Ugotowałem”: <strong>2</strong> razy'),
            'widoczny' => str_contains($po, 'Zostaje na liście'),
            'usunięty' => str_contains($po, 'Usunięty przepis'),
            'prywatny' => str_contains($po, 'Prywatny cudzy'),
            'za blokadą' => str_contains($po, 'Za blokadą'),
            'moderacja' => str_contains($po, 'Ukryty moderacją'),
        ];

        $this->assertSame([
            'liczba wykonań' => true,
            'widoczny' => true,
            'usunięty' => false,
            'prywatny' => false,
            'za blokadą' => false,
            'moderacja' => false,
        ], $poKrokach);
    }

    public function test_usuniety_wpis_szkic_i_schowany_jako_wspomnienie_nie_licza_sie(): void
    {
        $ja = $this->user();

        $this->wpis($ja);
        $usuniety = $this->wpis($ja, '2026-02-02 12:00:00');
        $this->wpis($ja, '2026-02-03 12:00:00', ['status' => Post::STATUS_DRAFT, 'published_at' => null]);
        $schowany = $this->wpis($ja, '2026-02-04 12:00:00');
        $schowany->forceFill(['hide_as_memory' => true])->save();
        $this->wpis($ja, '2026-02-05 12:00:00', ['visibility' => Post::VISIBILITY_PRIVATE]);

        // Prywatny wpis własny liczy się (to archiwum właściciela): łącznie 3.
        $this->assertStringContainsString('Opublikowane dania: <strong>3</strong>', $this->tresc($ja));

        $usuniety->delete();

        $this->assertStringContainsString('Opublikowane dania: <strong>2</strong>', $this->tresc($ja));
    }

    public function test_wykonanie_schowane_jako_wspomnienie_nie_liczy_sie(): void
    {
        $ja = $this->user();
        $przepis = Recipe::factory()->create();

        $this->wykonanie($ja, $przepis);
        $schowane = $this->wykonanie($ja, $przepis, '2026-03-11 12:00:00');
        $schowane->forceFill(['hide_as_memory' => true])->save();

        $tresc = $this->tresc($ja);

        $this->assertStringContainsString('Zaznaczone „Ugotowałem”: <strong>1</strong> raz', $tresc);
        // Jedno widoczne wykonanie to nie „najczęściej”.
        $this->assertStringNotContainsString('Gotowane najczęściej', $tresc);
    }

    public function test_pusty_rok_nie_pokazuje_zer_ani_oceny(): void
    {
        $ja = $this->user();

        $tresc = $this->tresc($ja);

        $this->assertStringContainsString('W tym roku nic tu jeszcze nie ma', $tresc);
        $this->assertStringNotContainsString('Opublikowane dania', $tresc);
        $this->assertStringNotContainsString('Zaznaczone', $tresc);
        $this->assertDoesNotMatchRegularExpression('/<strong>\s*0\s*<\/strong>/', $tresc);
        $this->assertDoesNotMatchRegularExpression('/\b0\s+(dań|razy|raz)\b/u', $tresc);
    }

    public function test_pojedyncza_liczba_pomija_wiersz_z_zerem(): void
    {
        $ja = $this->user();
        $this->wpis($ja);

        $tresc = $this->tresc($ja);

        $this->assertStringContainsString('Opublikowane dania: <strong>1</strong>', $tresc);
        $this->assertStringNotContainsString('Zaznaczone', $tresc);
    }

    public function test_poprzedni_rok_ma_wlasny_adres_i_granice_czasu_w_strefie_czlowieka(): void
    {
        $ja = $this->user();

        // 31.12.2025 23:30 czasu polskiego (22:30 UTC) to jeszcze 2025 także w UTC;
        // granicę w strefie człowieka sprawdza poniższy wpis i test wykonań.
        $this->wpis($ja, '2025-12-31 23:30:00');
        // 1.01.2026 00:30 czasu polskiego to 2026, choć w UTC bywa jeszcze 2025.
        $this->wpis($ja, '2026-01-01 00:30:00');

        $biezacy = $this->tresc($ja);
        $zeszly = $this->tresc($ja, '/moj-rok/2025');

        $this->assertStringContainsString('Mój rok w kuchni — 2026', $biezacy);
        $this->assertStringContainsString('Opublikowane dania: <strong>1</strong>', $biezacy);
        $this->assertStringContainsString('Mój rok w kuchni — 2025', $zeszly);
        $this->assertStringContainsString('Opublikowane dania: <strong>1</strong>', $zeszly);
        $this->assertStringContainsString('href="'.route('moj-rok.rok', 2025).'"', $biezacy);
    }

    public function test_rok_spoza_zakresu_to_404(): void
    {
        $ja = $this->user();

        $this->actingAs($ja)->get('/moj-rok/2027')->assertNotFound();
        $this->actingAs($ja)->get('/moj-rok/'.(MojRok::biezacyRok() - MojRok::MAKS_LAT_WSTECZ - 1))->assertNotFound();
        $this->actingAs($ja)->get('/moj-rok/abc')->assertNotFound();
    }

    public function test_bardzo_dluga_liczba_w_adresie_to_404_a_nie_blad_serwera(): void
    {
        $ja = $this->user();

        foreach (['99999999999999999999', '12345', '202'] as $rok) {
            $this->actingAs($ja)->get('/moj-rok/'.$rok)->assertNotFound();
        }
    }

    public function test_licznik_dan_nie_liczy_pytan_gdy_pytania_sa_wlaczone(): void
    {
        config(['kuking.questions.enabled' => true]);
        $ja = $this->user();

        $this->wpis($ja);
        $this->wpis($ja, '2026-02-02 12:00:00', ['kind' => Post::KIND_QUESTION, 'title' => 'Jak długo gotować jajka?']);
        $this->wpis($ja, '2026-02-03 12:00:00', ['kind' => Post::KIND_QUESTION, 'title' => 'Czym zastąpić śmietanę?']);

        $this->assertStringContainsString('Opublikowane dania: <strong>1</strong>', $this->tresc($ja));

        // Rok z samymi pytaniami nie jest rokiem z daniami: nie ma go na liście lat.
        $inna = $this->user();
        $this->wpis($inna, '2025-05-05 12:00:00', ['kind' => Post::KIND_QUESTION, 'title' => 'Pytanie sprzed roku']);

        $this->assertSame([2026], app(MojRok::class)->lata($inna));
    }

    public function test_granica_roku_w_strefie_czlowieka_dla_wykonan_i_listy_lat(): void
    {
        $ja = $this->user();
        $przepis = Recipe::factory()->create(['title' => 'Przepis graniczny']);

        // 31.12.2024 23:30 czasu polskiego (22:30 UTC) to 2024, 1.01.2025 00:30
        // czasu polskiego (31.12.2024 23:30 UTC) to już 2025 — choć w UTC jest
        // jeszcze 2024. Dwa wykonania, żeby przepis wszedł do „najczęściej”.
        $this->wykonanie($ja, $przepis, '2024-12-31 23:30:00');
        $this->wykonanie($ja, $przepis, '2025-01-01 00:30:00');
        $this->wykonanie($ja, $przepis, '2025-01-01 00:45:00');

        $podsumowania = [];
        foreach ([2024, 2025] as $rok) {
            $podsumowania[$rok] = app(MojRok::class)->podsumowanie($ja, $rok)['wykonania'];
        }

        $this->assertSame([2024 => 1, 2025 => 2], $podsumowania);
        $this->assertSame([2026, 2025, 2024], app(MojRok::class)->lata($ja));

        $tresc = $this->tresc($ja, '/moj-rok/2025');
        $this->assertStringContainsString('Zaznaczone „Ugotowałem”: <strong>2</strong> razy', $tresc);
        $this->assertStringContainsString('Przepis graniczny', $tresc);
    }

    public function test_wylaczone_wspomnienia_gaszą_ekran_ale_niczego_nie_kasuja(): void
    {
        $ja = $this->user(null, ['memories_enabled' => false]);
        $this->wpis($ja);

        $tresc = $this->tresc($ja);

        $this->assertStringContainsString('To podsumowanie jest wyłączone', $tresc);
        $this->assertStringNotContainsString('Opublikowane dania', $tresc);
        $this->assertSame(1, Post::query()->where('author_id', $ja->getKey())->count());

        // Odnośnik z „Moje” też znika.
        $this->actingAs($ja)->get(route('collections.index'))->assertDontSee('data-link-moj-rok', false);

        $ja->forceFill(['memories_enabled' => true])->save();
        $this->actingAs($ja->fresh())->get(route('collections.index'))->assertSee('data-link-moj-rok', false);
    }

    public function test_ekran_jest_noindex_i_nie_ma_udostepniania(): void
    {
        $ja = $this->user();
        $this->wpis($ja);

        $html = (string) $this->actingAs($ja)->get('/moj-rok')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex/i', $html);
        $this->assertStringNotContainsString('navigator.share', $html);
    }

    public function test_adres_nie_trafia_do_mapy_strony(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('moj-rok');
    }
}
