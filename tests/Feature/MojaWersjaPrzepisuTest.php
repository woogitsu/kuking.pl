<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\MojaWersja;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Block;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Moja wersja" — przepis na podstawie cudzego, z zachowanym autorstwem
 * oryginału (issue #23, D-301).
 *
 * Kryteria akceptacji z issue, każde osobnym testem:
 *  - wersja zachowuje `forked_from_id` i pokazuje autora oryginału,
 *  - wersja bez zmian jest odrzucana z komunikatem po polsku,
 *  - wersja ma `noindex`, dopóki nie spełni progu unikalności,
 *  - usunięcie oryginału nie usuwa wersji.
 * Do tego granice prywatności (Policy), powiadomienie autora oryginału
 * i lista „Wersje innych osób".
 */
class MojaWersjaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function oryginal(User $autor, string $widocznosc = 'public'): Recipe
    {
        return app(PublishRecipe::class)->handle(
            $autor,
            ['title' => 'Rosół babci Zofii', 'visibility' => $widocznosc],
            [['text' => '1 kura rosołowa'], ['text' => '2 marchewki'], ['text' => 'pietruszka', 'note' => 'korzeń']],
            [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez trzy godziny.', 'timer_minutes' => 180],
                ['instruction' => 'Dodaj warzywa i gotuj jeszcze godzinę, potem przecedź.']],
            publish: true,
        );
    }

    private function zrobWersje(User $kto, Recipe $oryginal): Recipe
    {
        $this->actingAs($kto)->post(route('recipes.fork', $oryginal->slug))->assertRedirect();

        return Recipe::query()->where('author_id', $kto->getKey())->where('forked_from_id', $oryginal->getKey())->sole();
    }

    /** @param  array<string, mixed>  $zmiany */
    private function opublikujPrzezFormularz(User $kto, Recipe $wersja, array $zmiany = []): TestResponse
    {
        return $this->actingAs($kto)->put(route('recipes.update', $wersja), array_merge([
            'title' => $wersja->title,
            'visibility' => 'public',
            'ingredients' => [['text' => '1 kura rosołowa'], ['text' => '2 marchewki'], ['text' => 'pietruszka', 'note' => 'korzeń']],
            'steps' => [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez trzy godziny.', 'timer_minutes' => 180],
                ['instruction' => 'Dodaj warzywa i gotuj jeszcze godzinę, potem przecedź.']],
        ], $zmiany));
    }

    public function test_wersja_to_prywatny_szkic_z_podpisem_oryginalu_i_bez_cudzych_zdjec(): void
    {
        $basia = $this->user('basia23');
        $oryginal = $this->oryginal($basia);
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $oryginal->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();
        $jan = $this->user('jan23');

        $this->actingAs($jan)->post(route('recipes.fork', $oryginal->slug))
            ->assertRedirect()
            ->assertSessionHas('status');

        $wersja = Recipe::query()->where('author_id', $jan->getKey())->sole();

        $this->assertSame($oryginal->getKey(), $wersja->forked_from_id);
        $this->assertNotNull($wersja->forked_at);
        $this->assertSame(Recipe::STATUS_DRAFT, $wersja->status);
        $this->assertSame('private', $wersja->visibility);
        $this->assertNull($wersja->hero_media_id, 'Zdjęcie autora oryginału nie przechodzi do cudzej wersji.');
        $this->assertSame(['1 kura rosołowa', '2 marchewki', 'pietruszka'], $wersja->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame([10800, null], $wersja->steps()->pluck('timer_seconds')->all());

        // Kreator pokazuje podpis — nazwy dosłownie, bez odmiany (COPY_STYLE).
        $this->actingAs($jan)->get(route('recipes.create', ['szkic' => $wersja->getKey()]))
            ->assertOk()
            ->assertSee('Na podstawie przepisu:', false)
            ->assertSee('„Rosół babci Zofii”', false)
            ->assertSee('· '.$basia->displayName(), false);

        // Szkic wersji jest wyłącznie jej autora.
        $this->actingAs($basia)->get(route('recipes.show', $wersja->slug))->assertForbidden();
    }

    public function test_drugie_klikniecie_oddaje_ten_sam_szkic(): void
    {
        $oryginal = $this->oryginal($this->user('basia23b'));
        $jan = $this->user('jan23b');

        $pierwsza = $this->zrobWersje($jan, $oryginal);
        $this->actingAs($jan)->post(route('recipes.fork', $oryginal->slug))
            ->assertRedirect(route('recipes.create', ['szkic' => $pierwsza->getKey()]));

        $this->assertSame(1, Recipe::query()->where('forked_from_id', $oryginal->getKey())->count());
    }

    public function test_policy_nie_pozwala_na_wersje_wlasnego_zawezonego_ani_przy_blokadzie(): void
    {
        $basia = $this->user('basia23c');
        $jan = $this->user('jan23c');

        // Własny przepis się poprawia, nie „forkuje".
        $this->actingAs($basia)->post(route('recipes.fork', $this->oryginal($basia)->slug))->assertForbidden();

        // Przepis dla obserwujących — nawet obserwujący nie wyniesie go dalej.
        $dlaObserwujacych = $this->oryginal($basia, 'followers');
        $jan->following()->attach($basia->getKey());
        $this->assertTrue($jan->can('view', $dlaObserwujacych), 'Kontrola: obserwujący przepis widzi.');
        $this->actingAs($jan)->post(route('recipes.fork', $dlaObserwujacych->slug))->assertForbidden();

        // Blokada w którąkolwiek stronę.
        $publiczny = $this->oryginal($basia);
        Block::create(['blocker_id' => $basia->getKey(), 'blocked_id' => $jan->getKey()]);
        $this->actingAs($jan)->post(route('recipes.fork', $publiczny->slug))->assertForbidden();

        // Gość nie ma przycisku ani trasy.
        auth()->logout();
        $this->post(route('recipes.fork', $publiczny->slug))->assertRedirect(route('login'));

        $this->assertSame(0, Recipe::query()->whereNotNull('forked_at')->count());
    }

    public function test_wersja_bez_zmian_jest_odrzucana_z_komunikatem_po_polsku(): void
    {
        $oryginal = $this->oryginal($this->user('basia23d'));
        $jan = $this->user('jan23d');
        $wersja = $this->zrobWersje($jan, $oryginal);

        // Nowy tytuł to nie zmiana przepisu.
        $this->opublikujPrzezFormularz($jan, $wersja, ['title' => 'Mój rosół'])
            ->assertSessionHasErrors(['title' => MojaWersja::KOMUNIKAT_BEZ_ZMIAN]);

        $wersja->refresh();
        $this->assertSame(Recipe::STATUS_DRAFT, $wersja->status);
        $this->assertSame('Rosół babci Zofii', $wersja->title, 'Odrzucenie cofa cały zapis, nie tylko publikację.');

        // Kontrola dodatnia: jedna zmiana w kroku wystarcza.
        $this->opublikujPrzezFormularz($jan, $wersja, [
            'steps' => [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez cztery godziny.', 'timer_minutes' => 240],
                ['instruction' => 'Dodaj warzywa i gotuj jeszcze godzinę, potem przecedź.']],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($wersja->fresh()->isPublished());
    }

    public function test_opublikowana_wersja_pokazuje_autora_oryginalu_a_oryginal_liste_wersji_bez_licznika(): void
    {
        $basia = $this->user('basia23e');
        $oryginal = $this->oryginal($basia);
        $jan = $this->user('jan23e');
        $wersja = $this->zrobWersje($jan, $oryginal);
        $this->opublikujPrzezFormularz($jan, $wersja, ['ingredients' => [['text' => '1 kura rosołowa'], ['text' => '3 marchewki'], ['text' => 'seler']]])
            ->assertSessionHasNoErrors();
        $wersja->refresh();

        auth()->logout();

        $this->get(route('recipes.show', $wersja->slug))
            ->assertOk()
            ->assertSee('Na podstawie przepisu:', false)
            ->assertSee('href="'.route('recipes.show', $oryginal->slug).'"', false)
            ->assertSee('· '.$basia->displayName(), false);

        $this->get(route('recipes.show', $oryginal->slug))
            ->assertOk()
            ->assertSee('Wersje innych osób')
            ->assertSee(route('recipes.show', $wersja->slug), false)
            ->assertDontSee('1 wersja')
            ->assertDontSee('Zrób swoją wersję');

        $this->actingAs($this->user('ktos23e'))->get(route('recipes.show', $oryginal->slug))
            ->assertSee('Zrób swoją wersję');
    }

    public function test_prywatna_wersja_nie_trafia_na_liste_oryginalu(): void
    {
        $oryginal = $this->oryginal($this->user('basia23f'));
        $jan = $this->user('jan23f');
        $wersja = $this->zrobWersje($jan, $oryginal);
        $this->opublikujPrzezFormularz($jan, $wersja, ['visibility' => 'private', 'ingredients' => [['text' => 'indyk']]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($wersja->fresh()->isPublished());
        auth()->logout();
        $this->get(route('recipes.show', $oryginal->slug))->assertDontSee('Wersje innych osób');
        $this->actingAs($this->user('obcy23f'))->get(route('recipes.show', $oryginal->slug))->assertDontSee('Wersje innych osób');
    }

    public function test_wersja_ma_noindex_dopoki_nie_przekroczy_progu_unikalnosci(): void
    {
        $oryginal = $this->oryginal($this->user('basia23g'));
        $jan = $this->user('jan23g');
        $wersja = $this->zrobWersje($jan, $oryginal);

        // Jedna zmieniona liczba — realna zmiana, ale tekst prawie ten sam.
        $this->opublikujPrzezFormularz($jan, $wersja, ['ingredients' => [['text' => '1 kura rosołowa'], ['text' => '3 marchewki'], ['text' => 'pietruszka', 'note' => 'korzeń']]])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertLessThan(MojaWersja::PROG_UNIKALNOSCI, MojaWersja::udzialNowegoTekstu($wersja->fresh(), $oryginal));
        $this->get(route('recipes.show', $wersja->slug))->assertSee('<meta name="robots" content="noindex, follow">', false);

        // Po prawdziwym przepisaniu kroków po swojemu wersja wraca do indeksu.
        $this->opublikujPrzezFormularz($jan, $wersja->fresh(), [
            'steps' => [['instruction' => 'Kurę obsmaż najpierw w garnku na maśle, aż się zezłoci.'],
                ['instruction' => 'Zalej wrzątkiem, dorzuć opalaną cebulę i ziele angielskie, gotuj dwie godziny.']],
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertGreaterThanOrEqual(MojaWersja::PROG_UNIKALNOSCI, MojaWersja::udzialNowegoTekstu($wersja->fresh(), $oryginal));
        $this->get(route('recipes.show', $wersja->slug))
            ->assertDontSee('<meta name="robots"', false)
            ->assertSee('<link rel="canonical" href="'.route('recipes.show', $wersja->slug).'"', false);
    }

    public function test_json_ld_wersji_wskazuje_oryginal_w_is_based_on(): void
    {
        $oryginal = $this->oryginal($this->user('basia23h'));
        $jan = $this->user('jan23h');
        $wersja = $this->zrobWersje($jan, $oryginal);
        $this->opublikujPrzezFormularz($jan, $wersja, ['ingredients' => [['text' => 'indyk'], ['text' => 'lubczyk']]])
            ->assertSessionHasNoErrors();
        $wersja->refresh()->forceFill(['hero_media_id' => Media::factory()->create(['owner_id' => $jan->getKey()])->getKey()])->save();
        auth()->logout();

        $html = $this->get(route('recipes.show', $wersja->slug))->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', (string) $html, $bloki);
        $przepis = collect($bloki[1])->map(fn ($b) => json_decode($b, true))->firstWhere('@type', 'Recipe');

        $this->assertNotNull($przepis, 'Brak bloku Recipe — test nie mierzyłby niczego.');
        $this->assertSame($oryginal->url(), $przepis['isBasedOn']);
    }

    public function test_usuniecie_oryginalu_nie_usuwa_wersji_i_zostawia_podpis(): void
    {
        $basia = $this->user('basia23i');
        $oryginal = $this->oryginal($basia);
        $jan = $this->user('jan23i');
        $wersja = $this->zrobWersje($jan, $oryginal);
        $this->opublikujPrzezFormularz($jan, $wersja, ['ingredients' => [['text' => 'indyk']]])->assertSessionHasNoErrors();

        $this->actingAs($basia)->delete(route('recipes.destroy', $oryginal->slug))->assertRedirect();
        auth()->logout();

        $this->assertNotNull($wersja->fresh());
        $this->get(route('recipes.show', $wersja->slug))
            ->assertOk()
            ->assertSee('oryginał jest niedostępny')
            ->assertDontSee('href="'.route('recipes.show', $oryginal->slug).'"', false);

        // Twarde skasowanie (wymazanie konta autora oryginału) zeruje
        // wskazanie, ale znacznik wersji zostaje — wersja nie udaje własnej.
        $oryginal->forceDelete();
        $wersja->refresh();
        $this->assertNull($wersja->forked_from_id);
        $this->assertNotNull($wersja->forked_at);
        $this->get(route('recipes.show', $wersja->slug))->assertOk()->assertSee('oryginał jest niedostępny');
    }

    public function test_publikacja_publicznej_wersji_raz_powiadamia_autora_oryginalu(): void
    {
        $basia = $this->user('basia23j');
        $oryginal = $this->oryginal($basia);
        $jan = $this->user('jan23j');
        $wersja = $this->zrobWersje($jan, $oryginal);

        $this->opublikujPrzezFormularz($jan, $wersja, ['ingredients' => [['text' => 'indyk']]])->assertSessionHasNoErrors();
        // Kolejna edycja opublikowanej wersji nie powiadamia drugi raz.
        $this->opublikujPrzezFormularz($jan, $wersja->fresh(), ['ingredients' => [['text' => 'indyk'], ['text' => 'seler']]])->assertSessionHasNoErrors();

        $powiadomienia = Notification::query()->where('user_id', $basia->getKey())->where('type', Notification::TYPE_FORKED)->get();
        $this->assertCount(1, $powiadomienia);
        $this->assertSame($jan->getKey(), $powiadomienia->first()->actor_id);
        $this->assertSame((string) $wersja->getKey(), $powiadomienia->first()->data['fork_id']);
        $this->assertSame($wersja->fresh()->url(), $powiadomienia->first()->adresDocelowy());

        $this->actingAs($basia)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('własna wersja Twojego przepisu')
            ->assertSee('„Rosół babci Zofii”', false);
    }

    public function test_prywatna_wersja_nie_powiadamia_autora_oryginalu(): void
    {
        $basia = $this->user('basia23k');
        $jan = $this->user('jan23k');
        $wersja = $this->zrobWersje($jan, $this->oryginal($basia));

        $this->opublikujPrzezFormularz($jan, $wersja, ['visibility' => 'private', 'ingredients' => [['text' => 'indyk']]])->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_FORKED)->count());
    }

    public function test_wersje_nie_trafiaja_do_mapy_strony(): void
    {
        $oryginal = $this->oryginal($this->user('basia23l'));
        $jan = $this->user('jan23l');
        $wersja = $this->zrobWersje($jan, $oryginal);
        $this->opublikujPrzezFormularz($jan, $wersja, ['ingredients' => [['text' => 'indyk']]])->assertSessionHasNoErrors();
        Cache::flush();
        auth()->logout();

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(route('recipes.show', $oryginal->slug), false)
            ->assertDontSee(route('recipes.show', $wersja->fresh()->slug), false);
    }

    public function test_eksport_danych_mowi_ze_przepis_jest_wersja(): void
    {
        $oryginal = $this->oryginal($this->user('basia23m'));
        $jan = $this->user('jan23m');
        $this->zrobWersje($jan, $oryginal);

        $dane = app(CollectUserExportData::class)->handle($jan, new ExportPhotoPlan($jan), Carbon::now());
        $przepis = $dane['przepisy'][0];

        $this->assertNotNull($przepis['moja_wersja_od']);
        $this->assertSame(['tytul' => 'Rosół babci Zofii', 'adres_w_serwisie' => $oryginal->slug], $przepis['na_podstawie_przepisu']);
    }
}
