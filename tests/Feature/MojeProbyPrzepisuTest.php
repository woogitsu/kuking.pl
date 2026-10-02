<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Gotowanie\ProbyPrzepisu;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * „Moje próby tego przepisu" (V2, #2412): prywatna historia i porównanie
 * własnych wykonań jednego przepisu.
 */
class MojeProbyPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->kucharz = $this->user('kucharz');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'title' => 'Zupa ogórkowa babci',
        ]);
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function proba(array $atrybuty = [], ?User $kto = null, ?Recipe $przepis = null): CookedEvent
    {
        $wersja = $atrybuty['recipe_version_id'] ?? null;
        unset($atrybuty['recipe_version_id']);

        $proba = CookedEvent::factory()->create($atrybuty + [
            'user_id' => ($kto ?? $this->kucharz)->getKey(),
            'recipe_id' => ($przepis ?? $this->przepis)->getKey(),
            'note' => 'Notatka testowa',
            'actual_minutes' => null,
        ]);

        if ($wersja !== null) {
            DB::table('cooked_events')->where('id', $proba->getKey())->update(['recipe_version_id' => $wersja]);
        }

        return $proba->refresh();
    }

    private function wersja(Recipe $przepis, string $notka = 'zmiana'): RecipeVersion
    {
        return app(SnapshotRecipeVersion::class)->handle($przepis->fresh(), $przepis->author, $notka);
    }

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $this->get(route('cooked.proby', $this->przepis->slug))->assertRedirect(route('login'));
    }

    public function test_widac_wylacznie_wlasne_proby_a_cudze_nie_trafiaja_do_html(): void
    {
        $this->proba(['note' => 'Moja pierwsza notatka']);
        $this->proba(['note' => 'Moja druga notatka', 'cooked_at' => now()->addDay()]);
        $this->proba(['note' => 'CUDZA-NOTATKA-OBCEJ-OSOBY'], $this->user('obca'));
        $this->proba(['note' => 'NOTATKA-AUTORA-PRZEPISU'], $this->autor);
        $this->proba(['note' => 'NOTATKA-INNEGO-PRZEPISU'], $this->kucharz, Recipe::factory()->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']));

        $html = $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString('Moja pierwsza notatka', $html);
        $this->assertStringContainsString('Moja druga notatka', $html);
        $this->assertStringContainsString('Liczba prób: <strong>2</strong>', $html);
        $this->assertStringNotContainsString('CUDZA-NOTATKA-OBCEJ-OSOBY', $html);
        $this->assertStringNotContainsString('NOTATKA-AUTORA-PRZEPISU', $html);
        $this->assertStringNotContainsString('NOTATKA-INNEGO-PRZEPISU', $html);
    }

    public function test_kolejnosc_jest_chronologiczna_i_deterministyczna_przy_remisie(): void
    {
        $t = now()->subDays(3)->startOfSecond();
        $this->proba(['note' => 'N-trzecia', 'cooked_at' => $t->copy()->addDay()]);
        $b = $this->proba(['note' => 'N-pierwsza', 'cooked_at' => $t]);
        $c = $this->proba(['note' => 'N-druga-remis', 'cooked_at' => $t]);

        [$pierwszy, $drugi] = $b->getKey() < $c->getKey() ? ['N-pierwsza', 'N-druga-remis'] : ['N-druga-remis', 'N-pierwsza'];

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))
            ->assertOk()
            ->assertSeeInOrder([$pierwszy, $drugi, 'N-trzecia']);

    }

    public function test_roznice_wersji_i_czasu_wzgledem_poprzedniej_proby(): void
    {
        $v1 = $this->wersja($this->przepis, 'start');
        $v2 = $this->wersja($this->przepis, 'poprawka');

        $this->proba(['cooked_at' => now()->subDays(5), 'actual_minutes' => 60, 'recipe_version_id' => $v1->getKey()]);
        $this->proba(['cooked_at' => now()->subDays(3), 'actual_minutes' => 45, 'recipe_version_id' => $v2->getKey()]);
        $this->proba(['cooked_at' => now()->subDay(), 'actual_minutes' => 45, 'recipe_version_id' => $v2->getKey()]);

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))
            ->assertOk()
            ->assertSee('To pierwsza zapisana próba.')
            ->assertSee('Wersja przepisu: '.$v2->version_number.' (poprzednio '.$v1->version_number.').')
            ->assertSee('Rzeczywisty czas: 45 min (poprzednio 60 min).')
            ->assertSee('Wersja i czas bez zmian względem poprzedniej próby.')
            ->assertSee('Zobacz wersję '.$v1->version_number)
            ->assertSee('Zobacz wersję '.$v2->version_number);
    }

    public function test_brak_danych_nie_udaje_porownania(): void
    {
        $this->proba(['cooked_at' => now()->subDays(2)]);
        $this->proba(['cooked_at' => now()->subDay()]);

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Brak danych do porównania wersji i czasu z poprzednią próbą.')
            ->assertDontSee('Wersja i czas bez zmian');
    }

    public function test_paginacja_porownuje_pierwsza_probe_strony_z_ostatnia_poprzednia(): void
    {
        $limit = ProbyPrzepisu::NA_STRONE;

        foreach (range(1, $limit + 1) as $i) {
            $this->proba(['cooked_at' => now()->subDays(100 - $i), 'actual_minutes' => $i * 10, 'note' => "Notatka numer $i"]);
        }

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Notatka numer 1')
            ->assertDontSee('Notatka numer '.($limit + 1).'<');

        $this->actingAs($this->kucharz)->get(route('cooked.proby', ['recipe' => $this->przepis->slug, 'page' => 2]))
            ->assertOk()
            ->assertSee('Próba '.($limit + 1).':')
            ->assertSee('Notatka numer '.($limit + 1))
            ->assertSee('(poprzednio '.Czas::czasPrzepisu($limit * 10).')')
            ->assertDontSee('To pierwsza zapisana próba.');
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_prob_ani_zdjec(): void
    {
        $v = $this->wersja($this->przepis);
        $licz = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))->assertOk();

            return $n;
        };

        $p = $this->proba(['cooked_at' => now()->subDays(20), 'recipe_version_id' => $v->getKey()]);
        $p->media()->attach(Media::factory()->create(['owner_id' => $this->kucharz->getKey()]), ['position' => 0]);
        $this->proba(['cooked_at' => now()->subDays(19), 'recipe_version_id' => $v->getKey()]);
        $licz();
        $malo = $licz();

        foreach (range(1, 8) as $i) {
            $p = $this->proba(['cooked_at' => now()->subDays(10 - $i), 'recipe_version_id' => $v->getKey()]);
            $p->media()->attach(Media::factory()->create(['owner_id' => $this->kucharz->getKey()]), ['position' => 0]);
        }
        $duzo = $licz();

        $this->assertLessThanOrEqual($malo + 1, $duzo, "Zapytań: $malo przy 2 próbach, $duzo przy 10.");
    }

    public function test_przepis_prywatny_innego_autora_nie_ujawnia_tytulu(): void
    {
        $prywatny = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'status' => Recipe::STATUS_PUBLISHED,
            'title' => 'Tajny-tytul-przepisu',
        ]);
        $this->proba(['note' => 'Moja notatka'], $this->kucharz, $prywatny);

        $odpowiedz = $this->actingAs($this->kucharz)->get(route('cooked.proby', $prywatny->slug));

        $odpowiedz->assertForbidden();
        $this->assertStringNotContainsString('Tajny-tytul-przepisu', (string) $odpowiedz->getContent());
    }

    public function test_przepis_usuniety_i_nieznany_adres_daja_404(): void
    {
        $this->proba();
        $slug = $this->przepis->slug;
        $this->przepis->delete();

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $slug))->assertNotFound();
        $this->actingAs($this->kucharz)->get(route('cooked.proby', 'nie-ma-takiego-przepisu'))->assertNotFound();
    }

    public function test_blokada_z_autorem_przepisu_zamyka_historie(): void
    {
        $this->proba(['note' => 'Moja notatka']);
        Block::create(['blocker_id' => $this->autor->getKey(), 'blocked_id' => $this->kucharz->getKey()]);

        $odpowiedz = $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug));

        $odpowiedz->assertForbidden();
        $this->assertStringNotContainsString('Zupa ogórkowa babci', (string) $odpowiedz->getContent());
    }

    public function test_wersja_ukryta_i_usunieta_nie_pokazuja_numeru_ani_odnosnika(): void
    {
        $v1 = $this->wersja($this->przepis, 'start');
        $v2 = $this->wersja($this->przepis, 'druga');
        $this->proba(['cooked_at' => now()->subDays(2), 'recipe_version_id' => $v1->getKey()]);
        $this->proba(['cooked_at' => now()->subDay(), 'recipe_version_id' => $v2->getKey()]);

        DB::table('recipe_versions')->where('id', $v1->getKey())->update(['hidden_at' => now(), 'hidden_by_role' => 'moderator']);

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Wersji z tej próby nie możemy już pokazać')
            ->assertSee('Wersja przepisu: '.$v2->version_number)
            ->assertDontSee('Zobacz wersję '.$v1->version_number)
            ->assertSee('Wersja przepisu inna niż przy poprzedniej próbie.');
    }

    public function test_pusty_stan_jest_spokojny_i_prowadzi_do_ugotowania(): void
    {
        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Nie ma tu jeszcze żadnej próby')
            ->assertSee(route('cooked.create', $this->przepis->slug), false);
    }

    public function test_odnosniki_do_historii_widzi_tylko_kucharz_z_wlasnym_wykonaniem(): void
    {
        $proba = $this->proba();
        $adres = route('cooked.proby', $this->przepis->slug);

        $this->actingAs($this->kucharz)->get(route('cooked.show', $proba))->assertOk()->assertSee($adres, false);
        $this->actingAs($this->kucharz)->get(route('recipes.show', $this->przepis->slug))->assertOk()->assertSee($adres, false);

        // Autor przepisu ogląda cudze wykonanie: bez odnośnika.
        $this->actingAs($this->autor)->get(route('cooked.show', $proba))->assertOk()->assertDontSee($adres, false);
        // Osoba bez własnej próby: strona przepisu bez odnośnika.
        $this->actingAs($this->user('obca'))->get(route('recipes.show', $this->przepis->slug))->assertOk()->assertDontSee($adres, false);
        $this->get(route('recipes.show', $this->przepis->slug))->assertOk()->assertDontSee($adres, false);
    }

    public function test_nie_tworzy_nowych_danych_a_widok_nic_nie_zapisuje(): void
    {
        $proba = $this->proba();
        $przed = DB::table('cooked_events')->count();

        $this->actingAs($this->kucharz)->get(route('cooked.proby', $this->przepis->slug))->assertOk();

        $this->assertSame($przed, DB::table('cooked_events')->count());
        $this->assertSame($proba->note, $proba->fresh()->note);
        $this->assertTrue(Gate::forUser($this->kucharz)->allows('view', $this->przepis));
    }
}
