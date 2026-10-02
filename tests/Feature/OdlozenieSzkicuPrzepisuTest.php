<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\OdlozSzkicPrzepisu;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Odłóż na później” przy własnym szkicu przepisu (#2550, V2).
 *
 * Pomiary idą przez HTTP i końcowy HTML; akcja domenowa jest wołana wprost
 * tylko tam, gdzie issue wymaga kontroli niezależnej od trasy.
 */
final class OdlozenieSzkicuPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const SKLADNIKI = [['text' => '1 kura rosołowa'], ['text' => '2 marchewki']];

    private const KROKI = [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez trzy godziny.']];

    private function szkic(User $autor, string $tytul = 'Szkic zupy'): Recipe
    {
        return Recipe::factory()->draft()->create(['author_id' => $autor->getKey(), 'title' => $tytul]);
    }

    private function odloz(User $kto, Recipe $szkic, string $stan = '', string $dokad = 'lista')
    {
        return $this->actingAs($kto)->post(route('recipes.drafts.postpone', $szkic->getKey()), ['stan' => $stan, 'dokad' => $dokad]);
    }

    private function przywroc(User $kto, Recipe $szkic, string $stan, string $dokad = 'lista')
    {
        return $this->actingAs($kto)->delete(route('recipes.drafts.resume', $szkic->getKey()), ['stan' => $stan, 'dokad' => $dokad]);
    }

    private function znacznik(Recipe $szkic): string
    {
        return OdlozSzkicPrzepisu::znacznik($szkic->refresh());
    }

    public function test_odlozenie_i_powrot_zachowuja_uuid_tresc_skladniki_kroki_i_kolejnosc(): void
    {
        $ja = $this->user('kucharka');
        $publikuj = app(PublishRecipe::class);
        $szkic = $publikuj->handle($ja, ['title' => 'Rosół babci Zofii', 'visibility' => 'public'], self::SKLADNIKI, self::KROKI, publish: false);
        $szkic->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        $przed = $szkic->fresh();
        $atrybutyPrzed = collect($przed->getAttributes())->except('odlozony_at')->all();
        $skladnikiPrzed = $przed->ingredients()->pluck('ingredient_text')->all();
        $krokiPrzed = $przed->steps()->pluck('instruction')->all();

        $this->odloz($ja, $szkic)->assertRedirect(route('recipes.drafts'));
        $odlozony = $szkic->fresh();
        $this->assertNotNull($odlozony->odlozony_at);
        $this->assertSame(Recipe::STATUS_DRAFT, $odlozony->status);
        $this->assertSame(
            $atrybutyPrzed,
            collect($odlozony->getAttributes())->except('odlozony_at')->all(),
            'Odłożenie zmieniło coś poza samym oznaczeniem (także updated_at).',
        );
        $this->assertSame($skladnikiPrzed, $odlozony->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame($krokiPrzed, $odlozony->steps()->pluck('instruction')->all());
        $this->assertSame(0, RecipeVersion::query()->where('recipe_id', $szkic->getKey())->count());

        $this->przywroc($ja, $szkic, $this->znacznik($szkic))->assertRedirect(route('recipes.drafts'));
        $wrocil = $szkic->fresh();
        $this->assertNull($wrocil->odlozony_at);
        $this->assertSame($atrybutyPrzed, collect($wrocil->getAttributes())->except('odlozony_at')->all());
        $this->assertSame($przed->getKey(), $wrocil->getKey());
    }

    public function test_odlozenie_nie_publikuje_i_nie_powiadamia(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);

        $this->odloz($ja, $szkic);

        $this->assertFalse($szkic->fresh()->isPublished());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_odlozony_szkic_znika_ze_skrotow_i_biezacych_ale_jest_na_liscie_odlozonych(): void
    {
        $ja = $this->user('kucharka');
        $biezacy = $this->szkic($ja, 'Szarlotka bieżąca');
        $odlozony = $this->szkic($ja, 'Pierogi odłożone');
        $this->odloz($ja, $odlozony);

        $this->actingAs($ja)->get(route('add'))
            ->assertSee('Szarlotka bieżąca')
            ->assertDontSee('Pierogi odłożone')
            ->assertSee('Odłożone na później');
        $this->get(route('recipes.drafts'))
            ->assertSee('Szarlotka bieżąca')
            ->assertDontSee('Pierogi odłożone')
            ->assertSee('Odłożone na później (1)');
        $this->get(route('recipes.drafts', ['odlozone' => 1]))
            ->assertSee('Pierogi odłożone')
            ->assertDontSee('Szarlotka bieżąca')
            ->assertSee('Wróć do pracy');
        $this->get(route('recipes.create', ['szkic' => $biezacy->getKey()]))->assertOk();
    }

    public function test_gdy_wszystkie_szkice_sa_odlozone_ekran_dodaj_i_lista_mowia_jak_do_nich_wrocic(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja, 'Jedyny odłożony');
        $this->odloz($ja, $szkic);

        $this->get(route('add'))
            ->assertDontSee('Dokończ: Jedyny odłożony')
            ->assertSee('Masz szkice odłożone na później')
            ->assertSee(route('recipes.drafts', ['odlozone' => 1]), false);
        $this->get(route('recipes.drafts'))
            ->assertSee('Wszystkie Twoje szkice są odłożone na później')
            ->assertDontSee('Nie masz teraz niedokończonych przepisów.');
        $this->get(route('recipes.drafts', ['odlozone' => 1]))->assertSee('Jedyny odłożony');
    }

    public function test_pusta_lista_odlozonych_mowi_jak_odlozyc(): void
    {
        $ja = $this->user('kucharka');
        $this->szkic($ja);

        $this->actingAs($ja)->get(route('recipes.drafts', ['odlozone' => 1]))
            ->assertOk()
            ->assertSee('Nie masz teraz odłożonych szkiców')
            ->assertSee('Odłóż na później');
    }

    public function test_lista_odlozonych_ma_paginacje_i_kazdy_odlozony_szkic_jest_osiagalny(): void
    {
        $ja = $this->user('kucharka');
        $odlozone = [];
        for ($i = 1; $i <= 21; $i++) {
            $odlozone[] = $szkic = $this->szkic($ja, 'Odłożony numer '.sprintf('%02d', $i));
            $szkic->forceFill(['updated_at' => now()->subMinutes($i), 'odlozony_at' => now()])->saveQuietly();
        }
        $this->szkic($ja, 'Bieżący');

        $widziane = [];
        $adres = route('recipes.drafts', ['odlozone' => 1]);
        $strony = 0;
        do {
            $strona = $this->actingAs($ja)->get($adres)->assertOk()->assertDontSee('Bieżący');
            $strony++;
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$strona->getContent());
            $xpath = new \DOMXPath($dom);
            foreach (self::elementyDom($xpath->query('//main//a[contains(@href, "szkic=")]')) as $link) {
                $widziane[] = $link->getAttribute('href');
            }
            $adres = $xpath->evaluate('string(//main//a[normalize-space(.)="Pokaż więcej"]/@href)');
        } while ($adres !== '' && $strony < 5);

        $this->assertSame(2, $strony, 'Odłożone szkice mają mieć kolejną porcję z zachowanym widokiem.');
        $this->assertCount(21, array_unique($widziane));
    }

    public function test_autozapis_nie_zdejmuje_oznaczenia_a_publikacja_tak(): void
    {
        $ja = $this->user('kucharka');
        $publikuj = app(PublishRecipe::class);
        $dane = ['title' => 'Rosół babci Zofii', 'visibility' => 'public'];
        $szkic = $publikuj->handle($ja, $dane, self::SKLADNIKI, self::KROKI, publish: false);
        $this->odloz($ja, $szkic);

        $szkic = $publikuj->handle($ja, [...$dane, 'title' => 'Rosół babci Zofii z lubczykiem'], self::SKLADNIKI, self::KROKI, publish: false, existing: $szkic->fresh());
        $this->assertNotNull($szkic->fresh()->odlozony_at, 'Zapis szkicu (autozapis) zdjął oznaczenie po cichu.');

        $this->get(route('recipes.create', ['szkic' => $szkic->getKey()]))
            ->assertOk()
            ->assertSee('Ten szkic jest odłożony na później')
            ->assertSee('Wróć do pracy');

        $publikuj->handle($ja, $dane, self::SKLADNIKI, self::KROKI, publish: true, existing: $szkic->fresh());
        $opublikowany = $szkic->fresh();
        $this->assertTrue($opublikowany->isPublished());
        $this->assertNull($opublikowany->odlozony_at, 'Opublikowany przepis nie może zostać „odłożony”.');
    }

    public function test_wroc_do_pracy_z_kreatora_wraca_do_kreatora(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);
        $this->odloz($ja, $szkic);

        $this->przywroc($ja, $szkic, $this->znacznik($szkic), 'kreator')
            ->assertRedirect(route('recipes.create', ['szkic' => $szkic->getKey()]));
        $this->assertNull($szkic->fresh()->odlozony_at);
        $this->get(route('recipes.create', ['szkic' => $szkic->getKey()]))->assertDontSee('Ten szkic jest odłożony na później');
    }

    public function test_cudzy_szkic_nie_zmienia_sie_i_nie_ujawnia_stanu(): void
    {
        $ja = $this->user('kucharka');
        $obca = $this->user('obca');
        $moj = $this->szkic($ja, 'Tajny szkic');

        $this->odloz($obca, $moj)->assertForbidden();
        $this->assertNull($moj->fresh()->odlozony_at);

        // Własność sprawdza też sama domena, niezależnie od trasy.
        $wynik = app(OdlozSzkicPrzepisu::class)->handle($obca, (string) $moj->getKey(), true, '');
        $this->assertSame(OdlozSzkicPrzepisu::BRAK, $wynik, 'Domena musi traktować cudzy szkic jak nieistniejący.');
        $this->assertNull($moj->fresh()->odlozony_at);

        $this->odloz($ja, $moj);
        $this->actingAs($obca)->get(route('recipes.drafts', ['odlozone' => 1]))->assertDontSee('Tajny szkic');
        $this->get(route('recipes.drafts'))->assertDontSee('Tajny szkic');
    }

    public function test_moderator_nie_odklada_cudzego_szkicu(): void
    {
        $moderator = $this->user('moderatorka', ['role' => User::ROLE_MODERATOR]);
        $szkic = $this->szkic($this->user('kucharka'));

        $this->odloz($moderator, $szkic)->assertForbidden();
        $this->assertNull($szkic->fresh()->odlozony_at);
    }

    public function test_gosc_nie_odklada(): void
    {
        $szkic = $this->szkic($this->user('kucharka'));

        $this->post(route('recipes.drafts.postpone', $szkic->getKey()))->assertRedirect(route('login'));
        $this->assertNull($szkic->fresh()->odlozony_at);
    }

    public function test_opublikowany_i_zamrozony_przez_moderacje_przepis_nie_dostaje_oznaczenia(): void
    {
        $ja = $this->user('kucharka');
        $opublikowany = Recipe::factory()->create(['author_id' => $ja->getKey(), 'status' => Recipe::STATUS_PUBLISHED]);
        $ukryty = Recipe::factory()->create(['author_id' => $ja->getKey(), 'status' => Recipe::STATUS_HIDDEN, 'published_at' => null]);

        $this->odloz($ja, $opublikowany)->assertForbidden();
        $this->odloz($ja, $ukryty)->assertForbidden();
        $this->assertNull($opublikowany->fresh()->odlozony_at);
        $this->assertNull($ukryty->fresh()->odlozony_at);

        // Także przy wołaniu domeny wprost (Policy nie jest jedyną bramką).
        foreach ([$opublikowany, $ukryty] as $przepis) {
            $this->assertSame(
                OdlozSzkicPrzepisu::NIE_SZKIC,
                app(OdlozSzkicPrzepisu::class)->handle($ja, (string) $przepis->getKey(), true, ''),
            );
        }
    }

    public function test_zawieszone_konto_nie_zapisuje_oznaczenia_przy_wolaniu_domeny(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);
        User::query()->whereKey($ja->getKey())->update(['status' => User::STATUS_SUSPENDED]);

        $this->expectException(AuthorizationException::class);
        try {
            app(OdlozSzkicPrzepisu::class)->handle($ja, (string) $szkic->getKey(), true, '');
        } finally {
            $this->assertNull($szkic->fresh()->odlozony_at);
        }
    }

    public function test_to_samo_zadanie_dwa_razy_jest_idempotentne(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);

        $this->odloz($ja, $szkic);
        $pierwszy = $this->znacznik($szkic);
        $this->odloz($ja, $szkic)->assertSessionHas('status');

        $this->assertSame($pierwszy, $this->znacznik($szkic), 'Drugie kliknięcie przestawiło znacznik odłożenia.');
    }

    public function test_stara_karta_nie_odwraca_nowszej_decyzji(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);

        $this->odloz($ja, $szkic);
        $staryZnacznik = $this->znacznik($szkic);
        $this->przywroc($ja, $szkic, $staryZnacznik);
        $this->assertNull($szkic->fresh()->odlozony_at);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->odloz($ja, $szkic);
        $nowy = $this->znacznik($szkic);
        $this->assertNotSame($staryZnacznik, $nowy);

        // Druga karta ze starym znacznikiem próbuje „Wróć do pracy”.
        $this->przywroc($ja, $szkic, $staryZnacznik)->assertSessionHas('status');
        $this->assertNotNull($szkic->fresh()->odlozony_at, 'Stare „Wróć do pracy” nadpisało nowszą decyzję po cichu.');
        $this->assertSame($nowy, $this->znacznik($szkic));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_zly_stan_zadania_mowi_co_zrobic_i_nic_nie_zmienia(): void
    {
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);

        $this->actingAs($ja)->post(route('recipes.drafts.postpone', $szkic->getKey()), ['dokad' => 'gdzies'])
            ->assertSessionHasErrors('dokad');
        $this->assertNull($szkic->fresh()->odlozony_at);
        $this->assertStringContainsString('Odśwież stronę', session('errors')->first('dokad'));
    }

    public function test_szkic_z_importu_i_zdjecie_zostaja_po_odlozeniu(): void
    {
        $ja = $this->user('kucharka');
        $media = Media::factory()->create(['owner_id' => $ja->getKey()]);
        $szkic = $this->szkic($ja);
        $szkic->forceFill(['hero_media_id' => $media->getKey()])->saveQuietly();

        $this->odloz($ja, $szkic);
        $this->assertSame($media->getKey(), $szkic->fresh()->hero_media_id);
        $this->assertNotNull(Media::query()->find($media->getKey()));
    }

    public function test_stan_trafia_do_paczki_danych(): void
    {
        $ja = $this->user('kucharka');
        $odlozony = $this->szkic($ja, 'Odłożony');
        $biezacy = $this->szkic($ja, 'Bieżący');
        $this->odloz($ja, $odlozony);

        $przepisy = collect(app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now())['przepisy'])->keyBy('tytul');

        $this->assertNotNull($przepisy['Odłożony']['odlozony_na_pozniej']);
        $this->assertNull($przepisy['Bieżący']['odlozony_na_pozniej']);
        $this->assertSame($biezacy->getKey(), $biezacy->fresh()->getKey());
    }

    public function test_pole_odlozony_at_nie_jest_masowo_przypisywalne(): void
    {
        $this->assertNotContains('odlozony_at', (new Recipe)->getFillable());

        $this->expectException(MassAssignmentException::class);
        new Recipe(['title' => 'X', 'odlozony_at' => now()]);
    }

    public function test_baza_nie_pozwala_odlozyc_opublikowanego_przepisu(): void
    {
        $przepis = Recipe::factory()->create(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('recipes')->where('id', $przepis->getKey())->update(['odlozony_at' => now()]);
    }

    public function test_cofniecie_migracji_odmawia_przy_odlozonych_i_przechodzi_bez_nich(): void
    {
        $migracja = require base_path('database/migrations/2026_10_03_150000_add_odlozony_at_to_recipes.php');
        $ja = $this->user('kucharka');
        $szkic = $this->szkic($ja);
        $this->odloz($ja, $szkic);

        try {
            $migracja->down();
            $this->fail('Rollback skasował oznaczenia ludzi bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SET odlozony_at = NULL', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('recipes', 'odlozony_at'));
        $this->assertNotNull($szkic->fresh()->odlozony_at);

        // Kontrola dodatnia: bez oznaczeń rollback przechodzi, a up() wraca.
        DB::table('recipes')->update(['odlozony_at' => null]);
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('recipes', 'odlozony_at'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('recipes', 'odlozony_at'));
    }
}
