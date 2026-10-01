<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Historia i porównanie zapisanych wersji przepisu (issue #2024).
 *
 * Granice pilnowane tutaj: widoczność jak strona przepisu (`RecipePolicy::view`),
 * brak historii przepisu nieopublikowanego (ukrytego, zdjętego, szkicu) —
 * także dla moderatora, brak zgadywania pól, których starsza migawka nie
 * zapisała, i stała liczba zapytań.
 */
class HistoriaWersjiPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function wersja(Recipe $przepis, int $numer, array $migawka = [], ?string $opis = null): RecipeVersion
    {
        $domyslna = [
            'title' => $przepis->title,
            'summary' => 'Opis przepisu',
            'servings' => 4,
            'ingredients' => [
                ['group_name' => null, 'text' => '500 g mąki', 'quantity' => 500, 'unit' => 'g', 'note' => null, 'substitutes' => null, 'no_amount' => false, 'position' => 0],
            ],
            'steps' => [
                ['position' => 0, 'instruction' => 'Wymieszaj mąkę z wodą.', 'timer_seconds' => null],
            ],
        ];

        return RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $przepis->author_id,
            'version_number' => $numer,
            'change_note' => $opis,
            'snapshot' => array_replace($domyslna, $migawka),
        ]);
    }

    private function przepisZWersjami(int $ile = 2, array $stan = []): Recipe
    {
        $przepis = Recipe::factory()->create($stan);
        for ($i = 1; $i <= $ile; $i++) {
            $this->wersja($przepis, $i, [], $i === 1 ? 'Pierwsza publikacja' : 'Aktualizacja przepisu');
        }

        return $przepis;
    }

    private function adresy(Recipe $przepis, int $numer = 1): array
    {
        return [
            route('recipes.history', $przepis->slug),
            route('recipes.history.version', [$przepis->slug, $numer]),
            route('recipes.history.changes', [$przepis->slug, $numer]),
        ];
    }

    public function test_gosc_widzi_liste_wersji_publicznego_przepisu_z_datami(): void
    {
        $przepis = $this->przepisZWersjami(3);

        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('Wersja 3')
            ->assertSee('Wersja 2')
            ->assertSee('Wersja 1')
            ->assertSee('Pierwsza publikacja')
            ->assertSee(now()->locale('pl')->isoFormat('D MMMM YYYY'))
            ->assertSee('Co się zmieniło względem wersji 2');
    }

    public function test_link_historii_zmian_jest_tylko_przy_co_najmniej_dwoch_wersjach(): void
    {
        $jedna = $this->przepisZWersjami(1);
        $dwie = $this->przepisZWersjami(2);
        $zero = Recipe::factory()->create();

        $this->get(route('recipes.show', $jedna->slug))->assertOk()->assertDontSee('Historia zmian');
        $this->get(route('recipes.show', $zero->slug))->assertOk()->assertDontSee('Historia zmian');
        $this->get(route('recipes.show', $dwie->slug))->assertOk()
            ->assertSee('Historia zmian')
            ->assertSee(route('recipes.history', $dwie->slug), false);
    }

    public function test_przepis_prywatny_jest_zamkniety_dla_gosci_i_obcych_a_otwarty_dla_autora(): void
    {
        $przepis = $this->przepisZWersjami(2, ['visibility' => 'private']);
        $obcy = $this->user();

        foreach ($this->adresy($przepis, 2) as $adres) {
            $this->get($adres)->assertForbidden();
            $this->actingAs($obcy)->get($adres)->assertForbidden();
            $this->actingAs($przepis->author)->get($adres)->assertOk();
            auth()->logout();
        }
    }

    public function test_przepis_dla_obserwujacych_widza_tylko_ten_kto_obserwuje_autora(): void
    {
        $przepis = $this->przepisZWersjami(2, ['visibility' => 'followers']);
        $obserwujacy = $this->user();
        $obserwujacy->following()->attach($przepis->author_id);
        $obcy = $this->user();

        foreach ($this->adresy($przepis, 2) as $adres) {
            $this->get($adres)->assertForbidden();
            $this->actingAs($obcy)->get($adres)->assertForbidden();
            $this->actingAs($obserwujacy)->get($adres)->assertOk();
            auth()->logout();
        }
    }

    public function test_blokada_i_zamkniete_konto_autora_odcinaja_historie_tak_jak_przepis(): void
    {
        $przepis = $this->przepisZWersjami(2);
        $widz = $this->user();
        app(BlockUser::class)->handle($przepis->author, $widz);

        foreach ($this->adresy($przepis, 2) as $adres) {
            $this->actingAs($widz)->get($adres)->assertForbidden();
        }

        $inny = $this->przepisZWersjami(2);
        $inny->author->fresh()->ban();
        foreach ($this->adresy($inny, 2) as $adres) {
            $this->get($adres)->assertForbidden();
        }
    }

    public function test_przepis_ukryty_zdjety_lub_szkic_nie_ma_historii_nawet_dla_moderatora_i_autora(): void
    {
        $moderator = $this->user();
        $moderator->promoteTo(User::ROLE_MODERATOR);

        foreach ([Recipe::STATUS_HIDDEN, Recipe::STATUS_REMOVED, Recipe::STATUS_DRAFT] as $status) {
            $przepis = $this->przepisZWersjami(2, ['status' => $status]);

            foreach ($this->adresy($przepis, 2) as $adres) {
                // Szkic jest wyłącznie autora (403 z `RecipePolicy::view`),
                // ukryty i zdjęty przechodzą `view` moderatora, ale nie
                // bramkę „opublikowany" (404).
                $this->actingAs($moderator)->get($adres)->assertStatus($status === Recipe::STATUS_DRAFT ? 403 : 404);
                $this->actingAs($przepis->author)->get($adres)->assertNotFound();
                auth()->logout();
            }
        }
    }

    public function test_przepis_ukryty_przez_moderacje_nie_pokazuje_linku_do_historii_moderatorowi(): void
    {
        $moderator = $this->user();
        $moderator->promoteTo(User::ROLE_MODERATOR);
        $przepis = $this->przepisZWersjami(2, ['status' => Recipe::STATUS_HIDDEN]);

        $this->actingAs($moderator)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee(route('recipes.history', $przepis->slug), false);
    }

    public function test_nieistniejaca_wersja_i_wersja_cudzego_przepisu_to_404(): void
    {
        $przepis = $this->przepisZWersjami(2);
        $inny = $this->przepisZWersjami(5);

        $this->get(route('recipes.history.version', [$przepis->slug, 3]))->assertNotFound();
        $this->get(route('recipes.history.changes', [$przepis->slug, 5]))->assertNotFound();
        $this->get(route('recipes.history', 'nie-ma-takiego-przepisu'))->assertNotFound();
        $this->get('/przepisy/'.$przepis->slug.'/historia/abc')->assertNotFound();
        // Ta sama wersja 5 pod własnym przepisem istnieje — 404 wyżej to brak cudzej wersji.
        $this->get(route('recipes.history.version', [$inny->slug, 5]))->assertOk();
    }

    /**
     * Numer w adresie to dowolny ciąg cyfr: zero i liczba większa niż
     * największy `int` mają dać 404, nie 500 (TypeError przy rzutowaniu na `int`).
     */
    public function test_numer_wersji_zero_ogromny_lub_ujemny_to_404_a_nie_500(): void
    {
        $przepis = $this->przepisZWersjami(2);

        foreach (['0', '99999999999999999999', '9223372036854775808', '-1', '1.5'] as $numer) {
            $this->get('/przepisy/'.$przepis->slug.'/historia/'.$numer)->assertNotFound();
            $this->get('/przepisy/'.$przepis->slug.'/historia/'.$numer.'/zmiany')->assertNotFound();
        }
    }

    public function test_widok_wersji_pokazuje_zapisana_tresc_a_brak_klucza_to_brak_danych(): void
    {
        $przepis = Recipe::factory()->create([
            'source_type' => Recipe::SOURCE_EXTERNAL,
            'source_url' => 'https://dzisiejszy-adres.example/przepis',
            'summary' => 'DZISIEJSZY OPIS',
        ]);
        $this->wersja($przepis, 1, [
            'summary' => 'Stary opis z 2026',
            'source_type' => Recipe::SOURCE_EXTERNAL,
            // brak klucza `source_url` — starsza migawka (#896)
            'ingredients' => [
                ['group_name' => 'Ciasto', 'text' => '500 g mąki', 'note' => 'przesianej', 'substitutes' => 'mąka orkiszowa', 'position' => 0],
            ],
            'steps' => [['position' => 0, 'instruction' => 'Zagnieć ciasto.', 'timer_seconds' => 600]],
        ]);
        $this->wersja($przepis, 2);

        $this->get(route('recipes.history.version', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee('Stary opis z 2026')
            ->assertSee('500 g mąki')
            ->assertSee('przesianej')
            ->assertSee('Zamiast tego: mąka orkiszowa')
            ->assertSee('Minutnik: 10 min')
            ->assertSee('Brak danych')
            ->assertDontSee('dzisiejszy-adres.example')
            ->assertDontSee('DZISIEJSZY OPIS')
            ->assertSee('Nowsza wersja (2)');
    }

    public function test_ekrany_nie_pokazuja_edytora_wersji(): void
    {
        $przepis = Recipe::factory()->create();
        $edytor = $this->user(null, ['display_name' => 'Zenobia Edytorska']);
        RecipeVersion::create([
            'recipe_id' => $przepis->getKey(), 'editor_id' => $edytor->getKey(), 'version_number' => 1,
            'change_note' => null, 'snapshot' => ['title' => 'A'],
        ]);
        $this->wersja($przepis, 2);

        foreach ($this->adresy($przepis, 2) as $adres) {
            $this->get($adres)->assertOk()->assertDontSee('Zenobia')->assertDontSee($edytor->getKey());
        }
    }

    public function test_porownanie_slowami_pokazuje_dodane_usuniete_i_zmienione(): void
    {
        $przepis = Recipe::factory()->create();
        $this->wersja($przepis, 1, [
            'servings' => 4,
            'ingredients' => [
                ['group_name' => null, 'text' => '500 g mąki', 'note' => null, 'substitutes' => null, 'position' => 0],
                ['group_name' => null, 'text' => '2 jajka', 'note' => null, 'substitutes' => null, 'position' => 1],
            ],
            'steps' => [
                ['position' => 0, 'instruction' => 'Wymieszaj składniki.', 'timer_seconds' => null],
                ['position' => 1, 'instruction' => 'Piecz 30 minut.', 'timer_seconds' => 1800],
            ],
        ]);
        $this->wersja($przepis, 2, [
            'servings' => 6,
            'ingredients' => [
                ['group_name' => null, 'text' => '750 g mąki', 'note' => null, 'substitutes' => null, 'position' => 0],
                ['group_name' => null, 'text' => '2 jajka', 'note' => 'większe', 'substitutes' => null, 'position' => 1],
                ['group_name' => null, 'text' => 'szczypta soli', 'note' => null, 'substitutes' => null, 'position' => 2],
            ],
            'steps' => [
                ['position' => 0, 'instruction' => 'Wymieszaj składniki.', 'timer_seconds' => null],
                ['position' => 1, 'instruction' => 'Odpocznij 10 minut.', 'timer_seconds' => null],
                ['position' => 2, 'instruction' => 'Piecz 40 minut.', 'timer_seconds' => 2400],
            ],
        ]);

        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))
            ->assertOk()
            ->assertSee('Zmieniono: Ilość porcji')
            ->assertSee('Było: 4')
            ->assertSee('Jest: 6')
            ->assertSee('Dodano')
            ->assertSee('Usunięto')
            ->assertSee('Jest: szczypta soli')
            ->assertSee('Było: 500 g mąki')
            ->assertSee('Jest: 2 jajka — większe')
            ->assertSee('Zmieniono: krok 2')
            ->assertSee('Było: Piecz 30 minut.')
            ->assertSee('Jest: Odpocznij 10 minut.')
            ->assertSee('Dodano: krok 3')
            ->assertSee('Jest: Piecz 40 minut.');
    }

    public function test_ekran_porownania_pokazuje_minutniki_z_obu_zapisanych_migawek(): void
    {
        $przepis = Recipe::factory()->create();
        $this->wersja($przepis, 1, ['steps' => [
            ['position' => 0, 'instruction' => 'Piecz ciasto', 'timer_seconds' => 600],
            ['position' => 1, 'instruction' => 'Wyjmij z formy', 'timer_seconds' => 125],
        ]]);
        $this->wersja($przepis, 2, ['steps' => [
            ['position' => 0, 'instruction' => 'Piecz ciasto na złoty kolor', 'timer_seconds' => 1200],
            ['position' => 1, 'instruction' => 'Ostudź przed krojeniem', 'timer_seconds' => 95],
            ['position' => 2, 'instruction' => 'Posyp cukrem', 'timer_seconds' => 60],
        ]]);

        $html = $this->get(route('recipes.history.changes', [$przepis->slug, 2]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<section[^>]*aria-labelledby="hz-kroki"[^>]*>(.*?)<\/section>/s', $html, $sekcja));
        $kroki = html_entity_decode(strip_tags($sekcja[1]));

        $this->assertStringContainsString('Było: Piecz ciasto (minutnik: 10 min)', $kroki);
        $this->assertStringContainsString('Jest: Piecz ciasto na złoty kolor (minutnik: 20 min)', $kroki);
        $this->assertStringContainsString('Było: Wyjmij z formy (minutnik: 125 s)', $kroki);
        $this->assertStringContainsString('Jest: Ostudź przed krojeniem (minutnik: 95 s)', $kroki);
        $this->assertStringContainsString('Jest: Posyp cukrem (minutnik: 1 min)', $kroki);
    }

    public function test_pierwsza_wersja_nie_ma_z_czym_sie_porownac(): void
    {
        $przepis = $this->przepisZWersjami(2);

        $this->get(route('recipes.history.changes', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee('nie ma z czym jej porównać');
    }

    public function test_identyczne_wersje_mowia_o_braku_roznic(): void
    {
        $przepis = $this->przepisZWersjami(2);

        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))
            ->assertOk()
            ->assertSee('nie ma różnic');
    }

    public function test_pole_ktorego_brakuje_w_starszej_migawce_nie_jest_zmiana(): void
    {
        $przepis = Recipe::factory()->create();
        $this->wersja($przepis, 1, ['source_type' => Recipe::SOURCE_EXTERNAL]);
        $this->wersja($przepis, 2, ['source_type' => Recipe::SOURCE_EXTERNAL, 'source_url' => 'https://nowy.example/x']);

        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))
            ->assertOk()
            ->assertDontSee('Dodano: Adres strony')
            ->assertSee('starsza wersja nie zapisała tych pól')
            ->assertSee('Adres strony, z której pochodzi przepis')
            // #2240: bez sprzecznych komunikatów — nie „nie ma różnic",
            // tylko „nie widać różnic w tym, co da się porównać".
            ->assertDontSee('W tekście i danych przepisu nie ma różnic')
            ->assertSee('W tym, co da się porównać, nie widać różnic.');
    }

    public function test_lista_ma_paginacje_pokaz_starsze(): void
    {
        $przepis = $this->przepisZWersjami(25);

        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('Wersja 25')
            ->assertDontSee('Wersja 5<', false)
            ->assertSee('Pokaż starsze wersje');
        $this->get(route('recipes.history', $przepis->slug).'?page=2')
            ->assertOk()
            ->assertSee('Wersja 1')
            ->assertDontSee('Pokaż starsze wersje');
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wersji(): void
    {
        $malo = $this->przepisZWersjami(2);
        $duzo = $this->przepisZWersjami(30);

        $policz = function (string $adres): int {
            $ile = 0;
            DB::listen(function () use (&$ile): void {
                $ile++;
            });
            $this->get($adres)->assertOk();

            return $ile;
        };

        $this->assertSame(
            $policz(route('recipes.history', $malo->slug)),
            $policz(route('recipes.history', $duzo->slug)),
        );
        $this->assertSame(
            $policz(route('recipes.history.version', [$malo->slug, 2])),
            $policz(route('recipes.history.version', [$duzo->slug, 29])),
        );
        $this->assertSame(
            $policz(route('recipes.history.changes', [$malo->slug, 2])),
            $policz(route('recipes.history.changes', [$duzo->slug, 29])),
        );
        // Sufit bezwzględny: strona bez wachlarza zapytań.
        $this->assertLessThanOrEqual(8, $policz(route('recipes.history', $duzo->slug)));
        $this->assertLessThanOrEqual(10, $policz(route('recipes.history.changes', [$duzo->slug, 29])));
    }

    public function test_strona_przepisu_dodaje_co_najwyzej_jedno_zapytanie_o_historie(): void
    {
        $bez = Recipe::factory()->create();
        $z = $this->przepisZWersjami(30);

        $ile = function (Recipe $p): int {
            $n = 0;
            DB::listen(function ($q) use (&$n): void {
                if (str_contains($q->sql, 'recipe_versions')) {
                    $n++;
                }
            });
            $this->get(route('recipes.show', $p->slug))->assertOk();

            return $n;
        };

        $this->assertSame(1, $ile($bez));
        $this->assertSame(1, $ile($z));
    }

    private function staryAdres(Recipe $przepis, string $stary): void
    {
        DB::table('recipe_slug_redirects')->insert([
            'slug' => $stary,
            'recipe_id' => $przepis->getKey(),
            'created_at' => now(),
        ]);
    }

    public function test_stary_slug_przekierowuje_301_na_kazdy_ekran_historii(): void
    {
        $przepis = $this->przepisZWersjami(2);
        $this->staryAdres($przepis, 'stary-adres-przepisu');

        $this->get('/przepisy/stary-adres-przepisu/historia')
            ->assertStatus(301)
            ->assertRedirect(route('recipes.history', $przepis->slug));
        $this->get('/przepisy/stary-adres-przepisu/historia/2')
            ->assertStatus(301)
            ->assertRedirect(route('recipes.history.version', [$przepis->slug, 2]));
        $this->get('/przepisy/stary-adres-przepisu/historia/2/zmiany')
            ->assertStatus(301)
            ->assertRedirect(route('recipes.history.changes', [$przepis->slug, 2]));
    }

    public function test_porownanie_pokazuje_zmiane_alergenow_tylko_przy_wlaczonej_fladze(): void
    {
        $przepis = Recipe::factory()->create();
        $this->wersja($przepis, 1, ['allergen_status' => 'declared', 'allergens' => ['celery']]);
        $this->wersja($przepis, 2, ['allergen_status' => 'declared', 'allergens' => ['celery', 'milk']]);

        config(['kuking.alergeny.wlaczone' => true]);
        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))
            ->assertOk()
            ->assertSee('Alergeny według autora')
            ->assertSee('mleko, seler');

        config(['kuking.alergeny.wlaczone' => false]);
        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))
            ->assertOk()
            ->assertDontSee('Alergeny według autora');
    }

    public function test_stary_slug_przepisu_prywatnego_i_nieopublikowanego_to_404_bez_adresu_w_naglowku(): void
    {
        $prywatny = $this->przepisZWersjami(2, ['visibility' => 'private']);
        $this->staryAdres($prywatny, 'stary-prywatny');
        $ukryty = $this->przepisZWersjami(2, ['status' => Recipe::STATUS_HIDDEN]);
        $this->staryAdres($ukryty, 'stary-ukryty');

        $this->get('/przepisy/stary-prywatny/historia')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/przepisy/stary-ukryty/historia/1')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/przepisy/nie-ma-takiego/historia')->assertNotFound();
    }

    public function test_etykieta_porownania_pokazuje_faktycznego_poprzednika_gdy_numeracja_ma_luke(): void
    {
        $przepis = Recipe::factory()->create();
        foreach ([1, 3, 7] as $numer) {
            $this->wersja($przepis, $numer, [], 'Aktualizacja przepisu');
        }

        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('względem wersji 3')
            ->assertSee('względem wersji 1')
            ->assertDontSee('względem wersji 6')
            ->assertDontSee('względem wersji 2');
        $this->get(route('recipes.history.version', [$przepis->slug, 7]))
            ->assertOk()
            ->assertSee('względem wersji 3')
            ->assertDontSee('względem wersji 6');
        // Najstarsza wersja nie ma poprzednika, więc nie ma przycisku porównania.
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))
            ->assertOk()
            ->assertDontSee('Co się zmieniło względem');
    }

    public function test_polityka_view_jest_liczona_raz_na_ekran_historii(): void
    {
        $przepis = $this->przepisZWersjami(3);
        $razy = 0;
        Gate::before(function (?User $u, string $ability) use (&$razy): null {
            if ($ability === 'view') {
                $razy++;
            }

            return null;
        });

        foreach ($this->adresy($przepis, 2) as $adres) {
            $razy = 0;
            $this->get($adres)->assertOk();
            $this->assertSame(1, $razy, $adres);
        }
    }

    public function test_ekran_historii_mowi_ze_wersje_sa_publiczne(): void
    {
        $przepis = $this->przepisZWersjami(2);

        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('Wersje są publiczne tak samo jak przepis')
            // #2270: zamiast odsyłać do „Napisz do nas" ekran mówi, że autor
            // może ukryć pojedynczą wersję.
            ->assertSee('Autor może ukryć pojedynczą wersję')
            ->assertDontSee('Pojedynczej wersji nie da się usunąć samemu');
    }

    public function test_ponowna_publikacja_bez_zmian_nie_dopisuje_identycznej_wersji(): void
    {
        $autor = User::factory()->create();
        $dane = fn (string $tytul): array => [
            'author' => $autor,
            'attributes' => ['title' => $tytul, 'visibility' => 'public', 'source_type' => 'own'],
            'ingredients' => [['text' => '1 kurczak']],
            'steps' => [['instruction' => 'Zalej wodą.']],
            'publish' => true,
        ];

        $przepis = app(PublishRecipe::class)->handle(...$dane('Rosół babci Zofii'));
        $this->assertSame(1, $przepis->versions()->count());

        $przepis = app(PublishRecipe::class)->handle(...$dane('Rosół babci Zofii'), existing: $przepis);
        $this->assertSame(1, $przepis->versions()->count(), 'Identyczna treść nie ma dawać drugiej wersji.');

        $przepis = app(PublishRecipe::class)->handle(...$dane('Rosół babci Zofii — z lubczykiem'), existing: $przepis);
        $this->assertSame([2, 1], $przepis->versions()->pluck('version_number')->map(fn ($n) => (int) $n)->all());
        $this->assertSame('Aktualizacja przepisu', $przepis->versions()->first()->change_note);
    }
}
