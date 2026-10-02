<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Udostepnienia\OdbierzDostepDoPrzepisu;
use App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jawne udostępnienie jednego przepisu wskazanej osobie (#2650, D-333).
 *
 * Trzy konta i gość: autorka (Halina), odbiorca (Jurek), obca osoba
 * (Basia), gość. Każda odmowa ma obok kontrolę dodatnią na TYM SAMYM
 * przepisie — bez niej „403 dla Basi" przeszłoby także wtedy, gdyby strona
 * czytania odmawiała wszystkim (`docs/PULAPKI_TESTOW.md` §4).
 *
 * Kontrola ujemna (wykonana 2.10.2026, każda przywrócona):
 *  - `readShared()` bez warunku `recipient_id` — Basia dostaje zdjęcie
 *    główne (302 zamiast 404) w teście zdjęć, a test obcej osoby oblewa
 *    na stronie czytania (404 z `firstOrFail` zamiast 403 z Policy);
 *  - `BlockUser` bez `KoniecUdostepnienPrzepisow::miedzy()` — oba kierunki
 *    testu blokady: „Blokada ma skasować udostępnienie, nie tylko je zasłonić";
 *  - `readShared()` bez warunku czynnego konta autora — „autorka zawieszona";
 *  - `DostepDoZdjecia::przezUdostepnienie()` zawsze `false` — odbiorca nie
 *    dostaje zdjęcia głównego (404); bez warunku `hero_media_id` — skan
 *    kartki wychodzi do odbiorcy (302 zamiast 404).
 */
class UdostepnieniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $halina;

    private User $jurek;

    private User $basia;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->jurek = $this->user('jurek', ['display_name' => 'Jurek']);
        $this->basia = $this->user('basia', ['display_name' => 'Basia']);
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->halina->getKey(),
            'visibility' => 'private',
            'title' => 'Sernik babci Wandy',
        ]);
    }

    private function udostepnij(?Recipe $przepis = null, string $komu = 'jurek'): RecipeShare
    {
        [$udostepnienie] = app(UdostepnijPrzepis::class)->poNazwie($this->halina, $przepis ?? $this->przepis, $komu);

        return $udostepnienie;
    }

    private function strona(?Recipe $przepis = null): string
    {
        return route('recipes.shared.show', $przepis ?? $this->przepis);
    }

    // ── Droga przez formularz: dwa kroki, odbiorca i zakres przed zapisem ──

    public function test_autorka_widzi_odbiorce_przed_potwierdzeniem_i_dopiero_drugi_krok_zapisuje(): void
    {
        $this->actingAs($this->halina)
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => '@jurek'])
            ->assertRedirect(route('recipes.shares.index', $this->przepis));

        $this->assertSame(0, RecipeShare::query()->count(), 'Pierwszy krok nie może niczego zapisać.');

        $this->actingAs($this->halina)->get(route('recipes.shares.index', $this->przepis))
            ->assertOk()
            ->assertSee('Sprawdź, komu pokazujesz przepis')
            ->assertSee('Jurek')
            ->assertSee('Tak, pokaż tej osobie')
            // Punkt 1 analizy prawnej: zakres przed potwierdzeniem — tylko odczyt, bez dalszego udostępniania.
            ->assertSee('Ta osoba może przepis tylko czytać.')
            ->assertSee('nie może go pokazać dalej')
            ->assertSee('Pokazujesz wyłącznie sam przepis.')
            ->assertSee('Kto widzi ten przepis w serwisie: Tylko Ty.');

        $this->actingAs($this->halina)
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => '@jurek', 'potwierdzam' => '1'])
            ->assertRedirect(route('recipes.shares.index', $this->przepis))
            ->assertSessionHas('status');

        $this->assertSame(1, RecipeShare::query()->where('recipient_id', $this->jurek->getKey())->count());
        // Udostępnienie nie zmienia widoczności przepisu dla serwisu.
        $this->assertSame('private', $this->przepis->fresh()->visibility);

        $this->actingAs($this->halina)->get(route('recipes.shares.index', $this->przepis))
            ->assertOk()->assertSee('Jurek')->assertSee('Odbierz dostęp');
        $this->actingAs($this->halina)->get(route('recipes.show', $this->przepis))
            ->assertOk()->assertSee('Komu pokazuję (1)');
    }

    public function test_drugie_udostepnienie_tej_samej_osobie_nie_tworzy_drugiego_wiersza_ani_powiadomienia(): void
    {
        $pierwsze = $this->udostepnij();
        $drugie = $this->udostepnij();

        $this->assertSame($pierwsze->getKey(), $drugie->getKey());
        $this->assertSame(1, RecipeShare::query()->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->jurek->getKey())->count(),
            'Jedno powiadomienie w serwisie na parę (decyzja właściciela z 2.10.2026, D-333) — drugie udostępnienie go nie powtarza.');
    }

    /** @return array<string, array{string}> */
    public static function odmowyBezZdradzania(): array
    {
        return [
            'nazwa nieistniejąca' => ['nie-ma-takiej'],
            'odbiorca zablokował autorkę' => ['blokada'],
            'odbiorca zawieszony' => ['zawieszony'],
            'odbiorca zbanowany' => ['zbanowany'],
        ];
    }

    #[DataProvider('odmowyBezZdradzania')]
    public function test_odmowa_ma_jedno_zdanie_i_nie_zdradza_stanu_konta(string $przypadek): void
    {
        $nazwa = 'jurek';

        match ($przypadek) {
            'nie-ma-takiej' => $nazwa = 'nie.ma.takiej.osoby',
            'blokada' => app(BlockUser::class)->handle($this->jurek, $this->halina),
            'zawieszony' => $this->jurek->suspend(now()->addDays(3)),
            'zbanowany' => $this->jurek->ban(),
            default => null,
        };

        $this->actingAs($this->halina)
            ->from(route('recipes.shares.index', $this->przepis))
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => $nazwa])
            ->assertRedirect(route('recipes.shares.index', $this->przepis))
            ->assertSessionHasErrors(['nazwa' => UdostepnijPrzepis::NIE_DA_SIE]);

        // Poprawnie wpisana nazwa nie znika po odmowie.
        $this->assertSame($nazwa, session()->getOldInput('nazwa'));
        $this->assertSame(0, RecipeShare::query()->count());
    }

    public function test_przepis_dla_wszystkich_i_szkic_nie_daja_sie_udostepnic(): void
    {
        $publiczny = Recipe::factory()->create(['author_id' => $this->halina->getKey(), 'visibility' => 'public']);
        $szkic = Recipe::factory()->draft()->create(['author_id' => $this->halina->getKey(), 'visibility' => 'private']);

        foreach ([$publiczny, $szkic] as $przepis) {
            $this->actingAs($this->halina)
                ->post(route('recipes.shares.store', $przepis), ['nazwa' => 'jurek', 'potwierdzam' => '1'])
                ->assertForbidden();
        }

        // Kontrola dodatnia: ten sam formularz na przepisie „Tylko ja" przechodzi.
        $this->actingAs($this->halina)
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => 'jurek', 'potwierdzam' => '1'])
            ->assertRedirect();
        $this->assertSame(1, RecipeShare::query()->count());
        $this->actingAs($this->halina)->get(route('recipes.shares.index', $publiczny))
            ->assertOk()->assertSee('Ten przepis widzą wszyscy');
    }

    public function test_limit_osob_na_przepis(): void
    {
        config(['kuking.udostepnienia.max_osob' => 1]);
        $this->udostepnij();

        $this->actingAs($this->halina)
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => 'basia', 'potwierdzam' => '1'])
            ->assertSessionHasErrors(['nazwa' => UdostepnijPrzepis::PELNY]);
        $this->assertSame(1, RecipeShare::query()->count());
    }

    public function test_samej_sobie_nie_da_sie_udostepnic(): void
    {
        $this->actingAs($this->halina)
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => 'halina'])
            ->assertSessionHasErrors(['nazwa' => UdostepnijPrzepis::SAM_SOBIE]);
    }

    // ── Odbiorca widzi jeden przepis i tylko na stronie czytania ──

    public function test_odbiorca_czyta_przepis_a_strona_nie_trafia_do_cache_ani_wyszukiwarek(): void
    {
        $this->udostepnij();

        $odpowiedz = $this->actingAs($this->jurek)->get($this->strona())->assertOk()
            ->assertSee('Sernik babci Wandy')
            ->assertSee('Przepis udostępniony Tobie')
            ->assertSee('Zrezygnuj z dostępu');

        $cache = (string) $odpowiedz->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $html = (string) $odpowiedz->getContent();
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
        // Bez martwych przycisków: odbiorca nie ma `view()`, więc nie ma dróg, które go wymagają.
        $this->assertStringNotContainsString(route('cooked.create', $this->przepis), $html);
        $this->assertStringNotContainsString(route('recipes.fork', $this->przepis), $html);
        $this->assertStringNotContainsString(route('recipes.comment', $this->przepis), $html);

        // Zwykły adres przepisu prowadzi odbiorcę na stronę czytania, nie w 403.
        $this->actingAs($this->jurek)->get(route('recipes.show', $this->przepis))
            ->assertRedirect($this->strona());

        $this->actingAs($this->jurek)->get(route('recipes.shared.index'))
            ->assertOk()->assertSee('Sernik babci Wandy')->assertSee('Halina');
        $this->actingAs($this->jurek)->get(route('collections.index'))
            ->assertOk()->assertSee('Przepisy udostępnione mi');
    }

    public function test_obca_osoba_i_gosc_nie_czytaja_przez_sam_adres(): void
    {
        $this->udostepnij();

        $this->actingAs($this->basia)->get($this->strona())->assertForbidden();
        $this->actingAs($this->basia)->get(route('recipes.show', $this->przepis))->assertForbidden();
        $this->actingAs($this->basia)->get(route('recipes.shared.index'))->assertOk()->assertDontSee('Sernik babci Wandy');
        $this->actingAs($this->basia)->get(route('collections.index'))->assertOk()->assertDontSee('Przepisy udostępnione mi');

        $this->app['auth']->forgetGuards();
        $this->get($this->strona())->assertRedirect(route('login'));
        $this->get(route('recipes.show', $this->przepis))->assertForbidden();

        // Kontrola dodatnia na tym samym przepisie.
        $this->actingAs($this->jurek)->get($this->strona())->assertOk();
    }

    public function test_udostepnienie_nie_daje_innych_drog_niz_odczyt(): void
    {
        $this->udostepnij();

        $this->actingAs($this->jurek)->get(route('cooked.create', $this->przepis))->assertForbidden();
        $this->actingAs($this->jurek)->post(route('recipes.fork', $this->przepis))->assertForbidden();
        $this->actingAs($this->jurek)->post(route('recipes.comment', $this->przepis), ['body' => 'Pyszne'])->assertForbidden();
        $this->actingAs($this->jurek)->get(route('cooking.show', $this->przepis))->assertForbidden();
        $this->assertContains($this->actingAs($this->jurek)->get(route('recipes.history', $this->przepis))->getStatusCode(), [403, 404]);

        $this->assertFalse($this->jurek->can('view', $this->przepis));
        $this->assertFalse($this->jurek->can('cook', $this->przepis));
        $this->assertFalse($this->jurek->can('fork', $this->przepis));
        $this->assertTrue($this->jurek->can('readShared', $this->przepis));
    }

    public function test_udostepniony_przepis_nie_wychodzi_w_wyszukiwarce_mapie_strony_ani_na_profilu(): void
    {
        $this->udostepnij();
        $this->actingAs($this->jurek)->post(route('social.follow', 'halina'));
        Cache::flush();

        $this->actingAs($this->jurek)->get(route('search', ['q' => 'sernik', 'sekcja' => 'przepisy']))
            ->assertOk()->assertDontSee('Sernik babci Wandy');
        $this->actingAs($this->jurek)->get(route('home'))->assertOk()->assertDontSee('Sernik babci Wandy');
        $this->actingAs($this->jurek)->get(route('profile.show', 'halina'))->assertOk()->assertDontSee('Sernik babci Wandy');

        $this->app['auth']->forgetGuards();
        $this->assertStringNotContainsString($this->przepis->slug, (string) $this->get(route('sitemap'))->assertOk()->getContent());

        // Kontrola dodatnia: ten sam przepis dla wszystkich wychodzi w wyszukiwarce.
        $this->przepis->forceFill(['visibility' => 'public'])->save();
        $this->actingAs($this->jurek)->get(route('search', ['q' => 'sernik', 'sekcja' => 'przepisy']))
            ->assertOk()->assertSee('Sernik babci Wandy');
    }

    // ── Koniec dostępu: od razu, bez cache ──

    /**
     * Udostępnienie obejmuje SAM przepis (analiza prawna 2.10.2026, punkt 2):
     * prywatne dane autorki przy tym przepisie — dopisek z gotowania,
     * zapamiętane porcje, plan na tydzień, notatka w zeszycie, jej własne
     * „Ugotowałem” z dniem gotowania — nie wychodzą do odbiorcy.
     */
    public function test_odbiorca_widzi_sam_przepis_bez_prywatnych_danych_autorki(): void
    {
        $teraz = now();
        DB::table('cooking_notes')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->halina->getKey(), 'recipe_id' => $this->przepis->getKey(),
            'body' => 'DOPISEK-Z-GOTOWANIA', 'revision' => 1, 'expires_at' => $teraz->copy()->addDay(), 'created_at' => $teraz, 'updated_at' => $teraz]);
        DB::table('recipe_serving_preferences')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->halina->getKey(), 'recipe_id' => $this->przepis->getKey(),
            'servings' => 37, 'created_at' => $teraz, 'updated_at' => $teraz]);
        DB::table('meal_plan_entries')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->halina->getKey(), 'recipe_id' => $this->przepis->getKey(),
            'day' => '2026-11-18', 'label' => null, 'created_at' => $teraz, 'updated_at' => $teraz]);
        $zeszyt = Collection::create(['owner_id' => $this->halina->getKey(), 'name' => 'Rodzinne', 'visibility' => 'private']);
        $zeszyt->recipes()->attach($this->przepis->getKey(), ['created_at' => $teraz, 'note' => 'NOTATKA-W-ZESZYCIE']);
        CookedEvent::factory()->create(['user_id' => $this->halina->getKey(), 'recipe_id' => $this->przepis->getKey(), 'note' => 'MOJE-UGOTOWANIE']);
        $this->udostepnij();

        $html = (string) $this->actingAs($this->jurek)->get($this->strona())->assertOk()->assertSee('Sernik babci Wandy')->getContent();

        foreach (['DOPISEK-Z-GOTOWANIA', '37 porcji', '18 listopada', 'Planer', 'NOTATKA-W-ZESZYCIE', 'MOJE-UGOTOWANIE', 'Rodzinne'] as $prywatne) {
            $this->assertStringNotContainsString($prywatne, $html, "Strona udostępnienia pokazała prywatne dane autorki: {$prywatne}");
        }
        // Kontrola dodatnia: autorka widzi swój zeszyt z notatką — dane naprawdę istnieją.
        $this->actingAs($this->halina)->get(route('collections.show', $zeszyt))->assertOk()->assertSee('NOTATKA-W-ZESZYCIE');
        $this->actingAs($this->jurek)->get(route('collections.show', $zeszyt))->assertForbidden();
    }

    /** Punkt 4 analizy prawnej: udostępnienie jednej osobie nie wysyła treści do moderacji modelem. */
    public function test_udostepnienie_nie_wysyla_przepisu_do_analizy_ani_do_openai(): void
    {
        Queue::fake();
        Http::fake();

        $this->actingAs($this->halina)
            ->post(route('recipes.shares.store', $this->przepis), ['nazwa' => 'jurek', 'potwierdzam' => '1'])
            ->assertRedirect();
        $this->actingAs($this->jurek)->get($this->strona())->assertOk();

        $this->assertSame(1, RecipeShare::query()->count());
        Queue::assertNotPushed(PrzeanalizujTresc::class);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame('private', $this->przepis->fresh()->visibility);
    }

    /** Punkt 3: adres to nie uprawnienie — cudzy i losowy identyfikator nic nie otwiera. */
    public function test_po_cofnieciu_ten_sam_adres_i_losowy_identyfikator_nic_nie_daja(): void
    {
        $udostepnienie = $this->udostepnij();
        $adres = $this->strona();
        $this->actingAs($this->halina)->delete(route('recipes.shares.destroy', ['recipe' => $this->przepis, 'share' => $udostepnienie]));

        $this->actingAs($this->jurek)->get($adres)->assertForbidden();
        $this->actingAs($this->jurek)->delete(route('recipes.shared.leave', $udostepnienie))->assertNotFound();
        $this->actingAs($this->jurek)->delete(route('recipes.shared.leave', (string) Str::uuid()))->assertNotFound();
        $this->actingAs($this->halina)
            ->delete(route('recipes.shares.destroy', ['recipe' => $this->przepis, 'share' => (string) Str::uuid()]))
            ->assertNotFound();
    }

    public function test_odebranie_dostepu_dziala_od_nastepnego_zadania(): void
    {
        $udostepnienie = $this->udostepnij();
        $this->actingAs($this->jurek)->get($this->strona())->assertOk();

        $this->actingAs($this->halina)
            ->delete(route('recipes.shares.destroy', ['recipe' => $this->przepis, 'share' => $udostepnienie]))
            ->assertRedirect(route('recipes.shares.index', $this->przepis));

        $this->assertSame(0, RecipeShare::query()->count());
        $this->actingAs($this->jurek)->get($this->strona())->assertForbidden();
        $this->actingAs($this->jurek)->get(route('recipes.show', $this->przepis))->assertForbidden();
        $this->actingAs($this->jurek)->get(route('recipes.shared.index'))->assertOk()->assertDontSee('Sernik babci Wandy');
    }

    public function test_odbiorca_moze_sam_zrezygnowac_a_obcy_nie_moze_za_niego(): void
    {
        $udostepnienie = $this->udostepnij();

        $this->actingAs($this->basia)->delete(route('recipes.shared.leave', $udostepnienie))->assertForbidden();
        $this->assertSame(1, RecipeShare::query()->count());

        $this->actingAs($this->jurek)->delete(route('recipes.shared.leave', $udostepnienie))
            ->assertRedirect(route('recipes.shared.index'));
        $this->assertSame(0, RecipeShare::query()->count());
        $this->actingAs($this->jurek)->get($this->strona())->assertForbidden();
    }

    public function test_udostepnienie_innego_przepisu_pod_adresem_tego_to_404(): void
    {
        $inny = Recipe::factory()->create(['author_id' => $this->basia->getKey(), 'visibility' => 'private']);
        [$cudze] = app(UdostepnijPrzepis::class)->poNazwie($this->basia, $inny, 'jurek');

        $this->actingAs($this->halina)
            ->delete(route('recipes.shares.destroy', ['recipe' => $this->przepis, 'share' => $cudze]))
            ->assertNotFound();
        $this->assertSame(1, RecipeShare::query()->count());
    }

    /** @return array<string, array{bool}> */
    public static function kierunkiBlokady(): array
    {
        return [
            'odbiorca blokuje autorkę' => [true],
            'autorka blokuje odbiorcę' => [false],
        ];
    }

    #[DataProvider('kierunkiBlokady')]
    public function test_blokada_kasuje_dostep_a_odblokowanie_go_nie_przywraca(bool $blokujeOdbiorca): void
    {
        $this->udostepnij();
        $this->actingAs($this->jurek)->get($this->strona())->assertOk();

        [$kto, $kogo] = $blokujeOdbiorca ? [$this->jurek, $this->halina] : [$this->halina, $this->jurek];
        app(BlockUser::class)->handle($kto, $kogo);

        $this->assertSame(0, RecipeShare::query()->count(), 'Blokada ma skasować udostępnienie, nie tylko je zasłonić.');
        $this->actingAs($this->jurek)->get($this->strona())->assertForbidden();

        app(UnblockUser::class)->handle($kto, $kogo);
        $this->actingAs($this->jurek)->get($this->strona())->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function zawieszenia(): array
    {
        return [
            'odbiorca zawieszony' => ['odbiorca'],
            'autorka zawieszona' => ['autorka'],
        ];
    }

    #[DataProvider('zawieszenia')]
    public function test_zawieszenie_wstrzymuje_dostep_a_koniec_kary_go_przywraca(string $kto): void
    {
        $this->udostepnij();
        $konto = $kto === 'odbiorca' ? $this->jurek : $this->halina;

        $konto->suspend(now()->addDays(3));
        $this->assertFalse($this->jurek->fresh()->can('readShared', $this->przepis->fresh()));
        $this->actingAs($this->jurek->fresh())->get($this->strona())->assertForbidden();

        // Kara minęła — wiersz został, dostęp wraca (bez czekania na `reinstate()`).
        $this->travel(4)->days();
        $this->assertTrue($this->jurek->fresh()->can('readShared', $this->przepis->fresh()));
    }

    public function test_zawieszona_autorka_odbiera_dostep_ale_nie_udostepnia_nowym(): void
    {
        $udostepnienie = $this->udostepnij();
        $this->halina->suspend(now()->addDays(3));
        $halina = $this->halina->fresh();

        $this->assertFalse($halina->can('share', $this->przepis));
        $this->assertTrue($halina->can('manageShares', $this->przepis));
        $this->assertTrue(app(OdbierzDostepDoPrzepisu::class)->odbierz($halina, $udostepnienie));
    }

    public function test_przepis_ukryty_przez_moderacje_nie_otwiera_sie_przez_udostepnienie(): void
    {
        $this->udostepnij();
        $this->przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->actingAs($this->jurek)->get($this->strona())->assertForbidden();
        $this->actingAs($this->jurek)->get(route('recipes.shared.index'))->assertOk()->assertDontSee('Sernik babci Wandy');

        // Przywrócenie przez moderację — autorka nie odebrała dostępu, więc wraca.
        $this->przepis->forceFill(['status' => Recipe::STATUS_PUBLISHED])->save();
        $this->actingAs($this->jurek)->get($this->strona())->assertOk();
    }

    public function test_usuniecie_przepisu_kasuje_udostepnienia(): void
    {
        $this->udostepnij();

        $this->actingAs($this->halina)->delete(route('recipes.destroy', $this->przepis))->assertRedirect();

        $this->assertSame(0, RecipeShare::query()->count());
        $this->actingAs($this->jurek)->get($this->strona())->assertNotFound();
    }

    // ── Zdjęcia: główne i kroków tak, skan kartki nie ──

    public function test_odbiorca_widzi_zdjecia_przepisu_ale_nie_skan_kartki(): void
    {
        Storage::fake('public');
        $glowne = $this->zdjecie();
        $krokowe = $this->zdjecie();
        $skan = $this->zdjecie();
        $this->przepis->forceFill(['hero_media_id' => $glowne->getKey(), 'source_scan_media_id' => $skan->getKey()])->save();
        RecipeStep::query()->create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'instruction' => 'Utrzyj ser.', 'media_id' => $krokowe->getKey()]);

        // Przed udostępnieniem — odmowa (kontrola, że przyrząd mierzy).
        $this->actingAs($this->jurek)->get($glowne->url('feed'))->assertNotFound();

        $this->udostepnij();

        $glownaOdpowiedz = $this->actingAs($this->jurek)->get($glowne->url('feed'))->assertRedirect();
        // Punkt 5: zdjęcie prywatnego przepisu nigdy z publicznym cache —
        // po cofnięciu nie zostaje w CDN (nagłówek idzie też do podpisanego adresu R2).
        $naglowek = (string) $glownaOdpowiedz->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $naglowek);
        $this->assertStringContainsString('private', $naglowek);
        $this->assertStringNotContainsString('public', $naglowek);
        $this->assertStringNotContainsString('s-maxage', $naglowek);
        $this->actingAs($this->jurek)->get($krokowe->url('feed'))->assertRedirect();
        $this->actingAs($this->jurek)->get($skan->url('feed'))->assertNotFound();
        $this->actingAs($this->basia)->get($glowne->url('feed'))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get($glowne->url('feed'))->assertNotFound();
    }

    // ── RODO: eksport i wymazanie ──

    public function test_eksport_obu_stron_bez_tresci_cudzego_przepisu(): void
    {
        $this->przepis->forceFill(['source_note' => 'Z zeszytu babci, ul. Lipowa 3'])->save();
        $this->udostepnij();

        $paczkaHaliny = $this->paczka($this->halina->fresh());
        $this->assertSame('Sernik babci Wandy', $paczkaHaliny['udostepnione_przepisy']['udostepniam'][0]['przepis']);
        $this->assertSame('Jurek', $paczkaHaliny['udostepnione_przepisy']['udostepniam'][0]['komu']);
        $this->assertSame([], $paczkaHaliny['udostepnione_przepisy']['udostepnione_mi']);

        $paczkaJurka = $this->paczka($this->jurek->fresh());
        $this->assertSame('Halina', $paczkaJurka['udostepnione_przepisy']['udostepnione_mi'][0]['autor']);
        $this->assertStringNotContainsString('Lipowa', (string) json_encode($paczkaJurka, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, array{string}> */
    public static function wymazania(): array
    {
        return [
            'wymazanie odbiorcy' => ['odbiorca'],
            'wymazanie autorki' => ['autorka'],
        ];
    }

    #[DataProvider('wymazania')]
    public function test_wymazanie_konta_ktorejkolwiek_strony_kasuje_udostepnienie(string $kto): void
    {
        $this->udostepnij();
        $inny = Recipe::factory()->create(['author_id' => $this->basia->getKey(), 'visibility' => 'private']);
        app(UdostepnijPrzepis::class)->poNazwie($this->basia, $inny, 'jurek');

        $konto = $kto === 'odbiorca' ? $this->jurek : $this->halina;
        $konto->markForDeletion(User::DELETE_SCOPE_MINIMUM);
        app(EraseAccountData::class)->handle($konto->fresh());

        $this->assertSame(0, RecipeShare::query()->where('recipe_id', $this->przepis->getKey())->count());
        // Kontrola dodatnia: udostępnienie bez udziału wymazanej osoby zostaje
        // (przy wymazaniu odbiorcy znika i to — był jego odbiorcą).
        $this->assertSame($kto === 'odbiorca' ? 0 : 1, RecipeShare::query()->where('recipe_id', $inny->getKey())->count());
    }

    /** @return array<string, mixed> */
    private function paczka(User $user): array
    {
        return app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now());
    }

    private function zdjecie(): Media
    {
        $identyfikator = Str::uuid()->toString();
        $warianty = [];

        foreach (config('kuking.media.variants') as $nazwa => $krawedz) {
            $klucz = 'media/'.$identyfikator.'_'.$nazwa.'.webp';
            $warianty[$nazwa] = ['key' => $klucz, 'width' => $krawedz, 'height' => $krawedz];
            Storage::disk('public')->put($klucz, 'udawane-bajty-'.$nazwa);
        }

        return Media::factory()->create([
            'owner_id' => $this->halina->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }
}
