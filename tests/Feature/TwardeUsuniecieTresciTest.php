<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneUsunieteTresci;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Usuń wpis” naprawdę usuwa — po terminie z polityki (audyt B5, znalezisko 1).
 *
 * Do 25.09.2026 miękko usunięty wpis leżał w bazie na zawsze, a jego zdjęcie
 * (oryginał i warianty) w R2. Test mierzy obie strony granicy:
 *  - po 30 dniach nie ma wiersza, plików ani odpowiedzi pod adresem zdjęcia;
 *  - przed terminem, przy treści ze sprawą moderacyjną i przy przepisie,
 *    który ugotował ktoś inny, nic cudzego nie ginie.
 */
class TwardeUsuniecieTresciTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.media.disk' => 'public',
            'kuking.media.public_disk' => 'public',
            'kuking.usuniete_tresci.retention_days' => 30,
        ]);
        Storage::fake('public');
    }

    /** Zdjęcie z oryginałem i wszystkimi wariantami naprawdę na dysku. */
    private function zdjecie(User $wlasciciel): Media
    {
        $zdjecie = Media::factory()->create(['owner_id' => $wlasciciel->getKey()]);

        foreach ($this->pliki($zdjecie) as $klucz) {
            Storage::disk('public')->put($klucz, 'x');
        }

        return $zdjecie;
    }

    /** @return list<string> */
    private function pliki(Media $zdjecie): array
    {
        return [$zdjecie->object_key, ...array_column($zdjecie->metadata['variants'], 'key')];
    }

    private function wpisZeZdjeciem(User $autor, Media $zdjecie): Post
    {
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        DB::table('post_media')->insert(['post_id' => $wpis->getKey(), 'media_id' => $zdjecie->getKey(), 'position' => 0]);

        return $wpis;
    }

    private function usunDniTemu(Post|Recipe|Comment $tresc, int $dni): void
    {
        $this->travelTo(now()->subDays($dni), fn () => $tresc->delete());
    }

    private function sprzataj(): array
    {
        return app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);
    }

    public function test_wpis_usuniety_przez_autora_znika_po_terminie_razem_ze_zdjeciem(): void
    {
        $autor = User::factory()->create();
        $zdjecie = $this->zdjecie($autor);
        $wpis = $this->wpisZeZdjeciem($autor, $zdjecie);
        $pliki = $this->pliki($zdjecie);

        // Przez serwis, tak jak klika człowiek — nie gołe `delete()`.
        $this->actingAs($autor)->delete(route('posts.destroy', $wpis))->assertRedirect();
        $this->assertSoftDeleted($wpis);

        // Kontrola dodatnia: przed terminem wszystko jeszcze leży.
        $this->travel(29)->days();
        $this->sprzataj();
        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);
        Storage::disk('public')->assertExists($pliki);

        $this->travel(2)->days();
        $wynik = $this->sprzataj();

        $this->assertSame(1, $wynik['wpisy']);
        $this->assertSame(1, $wynik['zdjecia']);
        $this->assertDatabaseMissing('posts', ['id' => $wpis->getKey()]);
        $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertMissing($pliki);

        $this->actingAs($autor->fresh())->get('/zdjecia/'.$zdjecie->getKey().'/large')->assertNotFound();
    }

    public function test_wpis_zdjety_przez_moderacje_zostaje_do_retencji_sprawy(): void
    {
        $autor = User::factory()->create();
        $moderator = User::factory()->create();
        $zdjecie = $this->zdjecie($autor);
        $wpis = $this->wpisZeZdjeciem($autor, $zdjecie);
        $this->usunDniTemu($wpis, 60);

        DB::table('moderation_actions')->insert([
            'id' => (string) Str::uuid(),
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'action' => 'remove',
            'reason_code' => 'spam',
            'created_at' => now()->subDays(60),
        ]);

        $this->assertSame(0, $this->sprzataj()['wpisy']);
        $this->assertSoftDeleted($wpis);
        Storage::disk('public')->assertExists($this->pliki($zdjecie));

        // Kontrola dodatnia: gdy retencja spraw zabierze decyzję, wpis wraca
        // do kolejki i znika.
        DB::table('moderation_actions')->delete();
        $this->assertSame(1, $this->sprzataj()['wpisy']);
        $this->assertDatabaseMissing('posts', ['id' => $wpis->getKey()]);
    }

    public function test_zgloszenie_komentarza_pod_wpisem_wstrzymuje_usuniecie_wpisu(): void
    {
        $autor = User::factory()->create();
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $komentarz = Comment::factory()->create(['post_id' => $wpis->getKey()]);
        $this->usunDniTemu($wpis, 60);

        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            'target_type' => 'comment',
            'target_id' => $komentarz->getKey(),
            'reason' => 'spam',
            'status' => 'open',
            'source' => 'community',
            'reporter_id' => User::factory()->create()->getKey(),
            'numer_sprawy' => 'KU-TEST-2345',
        ]);

        $this->sprzataj();

        $this->assertSoftDeleted($wpis);
        $this->assertDatabaseHas('comments', ['id' => $komentarz->getKey()]);
    }

    public function test_przepis_bez_cudzych_wykonan_znika_calkiem(): void
    {
        $przepis = Recipe::factory()->create();
        $zdjecie = $this->zdjecie($przepis->author);
        $przepis->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();

        $this->actingAs($przepis->author)->delete(route('recipes.destroy', $przepis->slug))->assertRedirect();
        $this->travel(31)->days();

        $wynik = $this->sprzataj();

        $this->assertSame(1, $wynik['przepisy']);
        $this->assertDatabaseMissing('recipes', ['id' => $przepis->getKey()]);
        Storage::disk('public')->assertMissing($this->pliki($zdjecie));
    }

    public function test_przepis_ugotowany_przez_kogos_innego_zostaje_nagrobkiem_bez_tresci(): void
    {
        $przepis = Recipe::factory()->family()->create(['title' => 'Pierogi babci Heli z Knyszyna']);
        $zdjecie = $this->zdjecie($przepis->author);
        $przepis->forceFill(['hero_media_id' => $zdjecie->getKey(), 'czas_laczny_zrodla_minut' => 95])->save();
        DB::table('recipe_steps')->insert(['id' => (string) Str::uuid(), 'recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Zagnieść ciasto jak Hela.']);
        $wykonanie = CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'note' => 'Wyszły świetnie.']);

        $this->usunDniTemu($przepis, 31);
        $wynik = $this->sprzataj();

        $this->assertSame(1, $wynik['nagrobki']);
        $this->assertSame(0, $wynik['przepisy']);

        // Cudze „Ugotowałem” zostaje nietknięte.
        $this->assertDatabaseHas('cooked_events', ['id' => $wykonanie->getKey(), 'note' => 'Wyszły świetnie.']);

        // Z treści przepisu nie zostaje ani słowo.
        $nagrobek = Recipe::withTrashed()->findOrFail($przepis->getKey());
        $this->assertTrue($nagrobek->trashed());
        $this->assertSame(PrzedawnioneUsunieteTresci::TYTUL_NAGROBKA, $nagrobek->title);
        $this->assertSame(PrzedawnioneUsunieteTresci::slugNagrobka($przepis->getKey()), $nagrobek->slug);
        $this->assertNull($nagrobek->summary);
        $this->assertNull($nagrobek->source_person);
        $this->assertNull($nagrobek->source_note);
        $this->assertNull($nagrobek->hero_media_id);
        $this->assertNull($nagrobek->czas_laczny_zrodla_minut);
        $this->assertDatabaseMissing('recipe_steps', ['recipe_id' => $przepis->getKey()]);
        $this->assertStringNotContainsString('knyszyn', json_encode(DB::table('recipes')->where('id', $przepis->getKey())->first()) ?: '');
        Storage::disk('public')->assertMissing($this->pliki($zdjecie));

        // Drugi przebieg nie liczy nagrobka jeszcze raz.
        $this->assertSame(0, $this->sprzataj()['nagrobki']);

        // Gdy znika ostatnie cudze wykonanie, nagrobek też odchodzi.
        $wykonanie->delete();
        $this->assertSame(1, $this->sprzataj()['przepisy']);
        $this->assertDatabaseMissing('recipes', ['id' => $przepis->getKey()]);
    }

    public function test_przepis_ze_zgloszona_wersja_nie_traci_wersji_w_nagrobku(): void
    {
        $zgloszony = Recipe::factory()->create();
        $wolny = Recipe::factory()->create();
        $wersje = [];
        foreach (['zgloszony' => $zgloszony, 'wolny' => $wolny] as $klucz => $przepis) {
            $wersje[$klucz] = RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $przepis->author_id,
                'version_number' => 1,
                'change_note' => 'Wersja 1',
                'snapshot' => ['title' => $przepis->title, 'summary' => 'Opis', 'ingredients' => [], 'steps' => []],
            ]);
            CookedEvent::factory()->create(['recipe_id' => $przepis->getKey()]);
            $this->usunDniTemu($przepis, 31);
        }

        Report::create([
            'reporter_id' => User::factory()->create()->getKey(),
            'target_type' => 'recipe_version',
            'target_id' => $wersje['zgloszony']->getKey(),
            'reason' => 'personal_data',
            'status' => Report::STATUS_OPEN,
        ]);

        $wynik = $this->sprzataj();

        // Kontrola dodatnia: przepis bez sprawy przeszedł w nagrobek razem
        // z kasacją wersji; przepis ze zgłoszoną wersją czeka nietknięty.
        $this->assertSame(1, $wynik['nagrobki']);
        $this->assertSame(
            [
                'wersja wolnego' => 0,
                'wersja zgloszonego' => 1,
                'tytul zgloszonego' => $zgloszony->title,
            ],
            [
                'wersja wolnego' => RecipeVersion::where('id', $wersje['wolny']->getKey())->count(),
                'wersja zgloszonego' => RecipeVersion::where('id', $wersje['zgloszony']->getKey())->count(),
                'tytul zgloszonego' => Recipe::withTrashed()->findOrFail($zgloszony->getKey())->title,
            ],
        );
    }

    public function test_przepis_ze_zgloszona_wskazowka_od_gotujacych_czeka_nietkniety(): void
    {
        $zgloszony = Recipe::factory()->create();
        $wolny = Recipe::factory()->create();
        $wskazowki = [];
        foreach (['zgloszony' => $zgloszony, 'wolny' => $wolny] as $klucz => $przepis) {
            $wykonanie = CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'note' => 'Uwaga kucharza.']);
            $wskazowki[$klucz] = RecipeHint::factory()->dlaWykonania($wykonanie, RecipeHint::STATUS_ACCEPTED)->create();
            $this->usunDniTemu($przepis, 31);
        }

        Report::create([
            'reporter_id' => User::factory()->create()->getKey(),
            'target_type' => 'recipe_hint',
            'target_id' => $wskazowki['zgloszony']->getKey(),
            'reason' => 'personal_data',
            'status' => Report::STATUS_OPEN,
        ]);

        $wynik = $this->sprzataj();

        // Kontrola dodatnia: przepis bez sprawy przeszedł w nagrobek (tytuł
        // zastąpiony); przepis ze zgłoszoną wskazówką czeka nietknięty, bo
        // zgłoszenie jest dowodem w sprawie moderacyjnej (#2352).
        $this->assertSame(1, $wynik['nagrobki']);
        $this->assertSame(
            ['wolny zachował tytuł' => false, 'zgłoszony zachował tytuł' => true, 'wskazówka zgłoszonego' => 1],
            [
                'wolny zachował tytuł' => Recipe::withTrashed()->findOrFail($wolny->getKey())->title === $wolny->title,
                'zgłoszony zachował tytuł' => Recipe::withTrashed()->findOrFail($zgloszony->getKey())->title === $zgloszony->title,
                'wskazówka zgłoszonego' => RecipeHint::where('id', $wskazowki['zgloszony']->getKey())->count(),
            ],
        );
    }

    public function test_usuniety_komentarz_znika_po_terminie_a_rodzic_czeka_na_odpowiedz(): void
    {
        $rodzic = Comment::factory()->create();
        $odpowiedz = Comment::factory()->create(['post_id' => $rodzic->post_id, 'parent_id' => $rodzic->getKey()]);
        $this->usunDniTemu($odpowiedz, 31);
        $this->usunDniTemu($rodzic, 31);

        $this->assertSame(1, $this->sprzataj()['komentarze']);
        $this->assertDatabaseMissing('comments', ['id' => $odpowiedz->getKey()]);
        $this->assertDatabaseHas('comments', ['id' => $rodzic->getKey()]);

        $this->assertSame(1, $this->sprzataj()['komentarze']);
        $this->assertDatabaseMissing('comments', ['id' => $rodzic->getKey()]);
    }

    /**
     * #2250: pełna pierwsza strona (BUDZET_PRZEBIEGU) najstarszych wpisów
     * chronionych sprawą moderacyjną nie zjada budżetu i nie głodzi wpisu
     * niechronionego, który stoi w kolejce za nimi. Chronione mają ten sam
     * `deleted_at` — kursor musi rozstrzygać remisy po `id`.
     */
    public function test_pelna_strona_chronionych_nie_glodzi_niechronionego_za_nimi(): void
    {
        $autor = User::factory()->create();
        $moderator = User::factory()->create();
        $chronione = Post::factory()->count(PrzedawnioneUsunieteTresci::BUDZET_PRZEBIEGU)->create(['author_id' => $autor->getKey()]);
        $usuniete = now()->subDays(90);

        DB::table('posts')->whereIn('id', $chronione->modelKeys())->update(['deleted_at' => $usuniete]);
        DB::table('moderation_actions')->insert($chronione->map(fn (Post $wpis): array => [
            'id' => (string) Str::uuid(),
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'action' => 'remove',
            'reason_code' => 'spam',
            'created_at' => $usuniete,
        ])->all());

        $wolny = Post::factory()->create(['author_id' => $autor->getKey()]);
        $this->usunDniTemu($wolny, 45);

        $wynik = $this->sprzataj();

        $this->assertSame(1, $wynik['wpisy'], 'Niechroniony wpis za pełną stroną chronionych nie został usunięty.');
        $this->assertDatabaseMissing('posts', ['id' => $wolny->getKey()]);
        $this->assertSame(
            PrzedawnioneUsunieteTresci::BUDZET_PRZEBIEGU,
            Post::onlyTrashed()->whereIn('id', $chronione->modelKeys())->count(),
            'Wpis chroniony sprawą moderacyjną zniknął.',
        );
    }

    /** #2250, druga strona granicy: budżet nadal ogranicza jeden przebieg. */
    public function test_budzet_liczy_rozpatrzone_i_nadal_ogranicza_przebieg(): void
    {
        $rodzicWpis = Post::factory()->create();
        $komentarze = Comment::factory()->count(PrzedawnioneUsunieteTresci::BUDZET_PRZEBIEGU + 3)->create(['post_id' => $rodzicWpis->getKey()]);
        DB::table('comments')->whereIn('id', $komentarze->modelKeys())->update(['deleted_at' => now()->subDays(40)]);

        $this->assertSame(PrzedawnioneUsunieteTresci::BUDZET_PRZEBIEGU, $this->sprzataj()['komentarze']);
        $this->assertSame(3, $this->sprzataj()['komentarze']);
        $this->assertSame(0, Comment::onlyTrashed()->whereIn('id', $komentarze->modelKeys())->count());
    }

    public function test_na_sucho_niczego_nie_kasuje(): void
    {
        $wpis = Post::factory()->create();
        $this->usunDniTemu($wpis, 31);

        $this->artisan('kuking:sprzataj-usuniete-tresci', ['--na-sucho' => true])->assertSuccessful();
        $this->assertSoftDeleted($wpis);

        $this->artisan('kuking:sprzataj-usuniete-tresci')->assertSuccessful();
        $this->assertDatabaseMissing('posts', ['id' => $wpis->getKey()]);
    }

    /**
     * Liczba w polityce jest liczbą, którą wykonuje kod (D-024: żadnego
     * „zaokrąglenia dla ładności zdania”).
     */
    public function test_polityka_podaje_ten_sam_termin_co_konfiguracja(): void
    {
        $polityka = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
        $wiersz = collect(explode("\n", $polityka))->first(fn (string $l) => str_starts_with($l, '| Publikowanie treści |'));

        $this->assertNotNull($wiersz);
        $dni = (int) config('kuking.usuniete_tresci.retention_days');
        $this->assertStringContainsString("najpóźniej **{$dni} dni** po usunięciu", $wiersz);
    }

    public function test_zadanie_jest_w_harmonogramie(): void
    {
        $nazwy = array_map(fn ($e) => $e->description, app(Schedule::class)->events());

        $this->assertContains('kuking:sprzataj-usuniete-tresci', $nazwy);
    }
}
