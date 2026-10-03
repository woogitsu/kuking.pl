<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Wydrukuj zeszyt” (#2351, F7): cały zeszyt jako książka do druku
 * z przeglądarki — okładka, spis treści, każdy przepis od nowej kartki.
 *
 * Pilnowane granice: dostęp przez `CollectionPolicy::view()` przy każdym
 * wejściu (także po cofnięciu dostępu), w środku wyłącznie to, co widzi
 * OGLĄDAJĄCY (bez tytułu i autora przepisów niedostępnych), `noindex`,
 * deterministyczny spis, stała liczba zapytań i limit długości książki.
 */
class WydrukZeszytuTest extends TestCase
{
    use RefreshDatabase;

    private User $halina;

    private User $jurek;

    private User $obca;

    private Collection $zeszyt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->jurek = $this->user('jurek', ['display_name' => 'Jurek']);
        $this->obca = $this->user('obca', ['display_name' => 'Obca Osoba']);
        $this->zeszyt = $this->zeszyt($this->halina, 'Książka babci', 'private');
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc): Collection
    {
        return Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => $nazwa,
            'description' => 'Przepisy na każdą niedzielę.',
            'visibility' => $widocznosc,
        ]);
    }

    /** Przepis z jednym składnikiem i jednym krokiem, odłożony do zeszytu. */
    private function przepis(User $autor, string $tytul, ?Collection $zeszyt = null, array $atrybuty = [], ?string $notatka = null): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul] + $atrybuty);
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'ingredient_text' => "Mąka do: {$tytul}"]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => "Wymieszaj: {$tytul}"]);
        ($zeszyt ?? $this->zeszyt)->recipes()->attach($przepis->getKey(), [
            'created_at' => now(),
            'note' => $notatka,
            'added_by_id' => ($zeszyt ?? $this->zeszyt)->owner_id,
        ]);

        return $przepis;
    }

    private function druk(?User $kto = null, ?Collection $zeszyt = null, array $parametry = []): TestResponse
    {
        $zeszyt ??= $this->zeszyt;
        $zadanie = $kto === null ? $this : $this->actingAs($kto);

        return $zadanie->get(route('collections.print', ['collection' => $zeszyt] + $parametry));
    }

    private function wersjaWZeszycie(Recipe $oryginal, string $tytul): Recipe
    {
        $wersja = $this->przepis($this->halina, $tytul);
        $wersja->forceFill([
            'source_type' => Recipe::SOURCE_ADAPTATION,
            'forked_from_id' => $oryginal->getKey(),
            'forked_at' => now(),
        ])->save();

        return $wersja;
    }

    public function test_wydruk_calego_zeszytu_pokazuje_sztuki_osobno_od_porcji_i_czasu(): void
    {
        $this->przepis($this->halina, 'Same sztuki', null, [
            'yield_count' => 24, 'yield_unit' => 'pierogi',
            'servings' => null, 'prep_minutes' => null, 'cook_minutes' => null,
        ]);
        $this->przepis($this->halina, 'Sztuki i porcje', null, [
            'yield_count' => 12, 'yield_unit' => 'bułki',
            'servings' => 4, 'prep_minutes' => 10, 'cook_minutes' => 20,
        ]);
        $this->przepis($this->halina, 'Bez sztuk', null, [
            'yield_count' => null, 'yield_unit' => null,
            'servings' => 2, 'prep_minutes' => null, 'cook_minutes' => null,
        ]);

        $html = (string) $this->druk($this->halina)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<p class="meta m-0">\s*24 szt\. \(pierogi\)\s*<\/p>/u', $html, 'SZTUKI_2876_WYDRUK: brak sztuk bez porcji i czasu albo pusty separator.');
        $this->assertMatchesRegularExpression('/<p class="meta m-0">\s*4 porcje\s*·\s*12 szt\. \(bułki\)\s*·\s*Czas: 30 min\s*<\/p>/u', $html, 'SZTUKI_2876_WYDRUK: porcje, sztuki i czas muszą pozostać osobnymi danymi.');
        $this->assertMatchesRegularExpression('/<p class="meta m-0">\s*2 porcje\s*<\/p>/u', $html, 'Przepis bez sztuk zachowuje dotychczasową metryczkę.');
    }

    public function test_wybrany_wydruk_pokazuje_sztuki_i_zachowuje_opcje_oraz_escapowanie(): void
    {
        $wybrany = $this->przepis($this->halina, 'Pierogi', null, [
            'yield_count' => 24, 'yield_unit' => '<script>alert(1)</script>',
            'servings' => null, 'prep_minutes' => null, 'cook_minutes' => null,
            'source_note' => '<b>Rodzinna historia</b>',
        ], 'Prywatna notatka');
        $wybrany->forceFill(['hero_media_id' => Media::factory()->create(['owner_id' => $this->halina->getKey()])->getKey()])->save();
        $this->przepis($this->halina, 'Niewybrany', null, ['yield_count' => 99]);

        $this->druk($this->halina, null, [
            'tryb' => 'wybrane', 'przepisy' => [(string) $wybrany->getKey()],
        ])->assertOk()->assertSee('class="zeszyt-zdjecie"', false)->assertDontSee('Prywatna notatka');

        $html = (string) $this->druk($this->halina, null, [
            'tryb' => 'wybrane', 'przepisy' => [(string) $wybrany->getKey()],
            'bez-zdjec' => 1, 'z-notatkami' => 1,
        ])->assertOk()->getContent();

        $this->assertStringContainsString('24 szt. (&lt;script&gt;alert(1)&lt;/script&gt;)', $html, 'SZTUKI_2876_WYDRUK: wybór przepisu nie może zgubić sztuk.');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;b&gt;Rodzinna historia&lt;/b&gt;', $html);
        $this->assertStringContainsString('Prywatna notatka', $html);
        $this->assertStringNotContainsString('Niewybrany', $html);
        $this->assertStringNotContainsString('99 szt.', $html);
        $this->assertStringNotContainsString('class="zeszyt-zdjecie"', $html);
    }

    public function test_wydruk_calosci_i_wyboru_podpisuje_widoczny_oryginal_niezaleznie_od_zdjec_i_notatek(): void
    {
        $oryginal = Recipe::factory()->create(['author_id' => $this->jurek->getKey(), 'title' => 'Oryginalny żurek']);
        $wersja = $this->wersjaWZeszycie($oryginal, 'Żurek po mojemu');
        $this->zeszyt->recipes()->updateExistingPivot($wersja->getKey(), ['note' => 'Prywatny dopisek do wersji']);
        $this->przepis($this->halina, 'Zwykły sernik');

        $caly = (string) $this->druk($this->halina)->assertOk()->getContent();
        $this->assertStringContainsString('Na podstawie przepisu:', $caly, 'PODPIS_2852_WIDOCZNY: papier pomija autora oryginału.');
        $this->assertStringContainsString('„Oryginalny żurek”', $caly, 'PODPIS_2852_WIDOCZNY: papier pomija tytuł oryginału.');
        $this->assertStringContainsString('· Jurek', $caly, 'PODPIS_2852_WIDOCZNY: papier pomija konto oryginału.');
        $this->assertStringNotContainsString('Prywatny dopisek do wersji', $caly);
        $this->assertSame(1, substr_count($caly, 'data-test="na-podstawie"'), 'Zwykły przepis nie może dostać podpisu wersji.');

        $wybrane = (string) $this->druk($this->halina, null, [
            'przepisy' => [(string) $wersja->getKey()], 'bez-zdjec' => 1, 'z-notatkami' => 1,
        ])->assertOk()->getContent();
        $this->assertStringContainsString('Na podstawie przepisu:', $wybrane, 'PODPIS_2852_WYBOR: wariant bez zdjęć gubi podpis.');
        $this->assertStringContainsString('„Oryginalny żurek”', $wybrane, 'PODPIS_2852_WYBOR: wybór gubi oryginał.');
        $this->assertStringContainsString('Prywatny dopisek do wersji', $wybrane);
        $this->assertStringNotContainsString('Zwykły sernik', $wybrane);
    }

    public function test_niewidoczny_zablokowany_i_skasowany_oryginal_daje_tylko_neutralny_podpis(): void
    {
        $autor = $this->user('autor2852', ['display_name' => 'Tajne Konto']);
        $oryginal = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Tajna receptura']);
        $this->wersjaWZeszycie($oryginal, 'Moja zupa');

        Block::create(['blocker_id' => $autor->getKey(), 'blocked_id' => $this->halina->getKey()]);
        $html = (string) $this->druk($this->halina)->assertOk()->getContent();
        $this->assertStringContainsString('Na podstawie przepisu innej osoby — oryginał jest niedostępny.', $html, 'PODPIS_2852_NIEDOSTEPNY: brak neutralnego podpisu.');
        $this->assertStringNotContainsString('Tajna receptura', $html);
        $this->assertStringNotContainsString('Tajne Konto', $html);
        $this->assertStringNotContainsString($oryginal->slug, $html);

        $oryginal->forceDelete();
        $html = (string) $this->druk($this->halina)->assertOk()->getContent();
        $this->assertStringContainsString('Na podstawie przepisu innej osoby — oryginał jest niedostępny.', $html, 'PODPIS_2852_NIEDOSTEPNY: twarde usunięcie nie zdejmuje podpisu.');
        $this->assertStringNotContainsString('Tajna receptura', $html);
    }

    public function test_zawężenie_do_obserwujacych_i_miekkie_usuniecie_nie_wyciekaja_na_wydruk(): void
    {
        $oryginal = Recipe::factory()->create([
            'author_id' => $this->jurek->getKey(), 'title' => 'Rosół Jurka', 'visibility' => 'followers',
        ]);
        $this->wersjaWZeszycie($oryginal, 'Mój rosół');

        $zamkniety = (string) $this->druk($this->halina)->assertOk()->getContent();
        $this->assertStringContainsString('oryginał jest niedostępny', $zamkniety, 'PODPIS_2852_NIEDOSTEPNY: zawężony oryginał ma neutralny podpis.');
        $this->assertStringNotContainsString('Rosół Jurka', $zamkniety);

        $this->halina->following()->attach($this->jurek->getKey(), ['created_at' => now()]);
        $widoczny = (string) $this->druk($this->halina)->assertOk()->getContent();
        $this->assertStringContainsString('Rosół Jurka', $widoczny, 'PODPIS_2852_WIDOCZNY: obserwująca osoba widzi tytuł oryginału.');

        $oryginal->delete();
        $usuniety = (string) $this->druk($this->halina)->assertOk()->getContent();
        $this->assertStringContainsString('oryginał jest niedostępny', $usuniety, 'PODPIS_2852_NIEDOSTEPNY: usunięty oryginał ma neutralny podpis.');
        $this->assertStringNotContainsString('Rosół Jurka', $usuniety);
    }

    public function test_wiele_wersji_nie_dodaje_zapytania_na_kazdy_oryginal(): void
    {
        $pierwszy = Recipe::factory()->create(['author_id' => $this->jurek->getKey(), 'title' => 'Oryginał pierwszy']);
        $this->wersjaWZeszycie($pierwszy, 'Wersja pierwsza');
        $jedna = $this->liczbaZapytan();

        foreach (range(2, 11) as $i) {
            $autor = $this->user("oryginal{$i}");
            $oryginal = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => "Oryginał {$i}"]);
            $this->wersjaWZeszycie($oryginal, "Wersja {$i}");
        }

        $wiele = $this->liczbaZapytan();
        $this->assertSame($jedna, $wiele, "PODPIS_2852_BEZ_N_PLUS_1: jedno {$jedna}, wiele {$wiele} zapytań.");
    }

    public function test_wlasciciel_dostaje_okladke_spis_i_przepisy_z_trescia(): void
    {
        $przepis = $this->przepis($this->halina, 'Żurek wielkanocny', null, [
            'source_type' => Recipe::SOURCE_FAMILY, 'source_person' => 'od babci Zosi', 'source_note' => 'Robiła go co Wielkanoc.', 'family_since_year' => 1974,
        ], 'Dodać więcej majeranku');

        // Notatki z zeszytu na wydruku tylko na jawne życzenie (#2438, D-333).
        $this->druk($this->halina, null, ['z-notatkami' => 1])
            ->assertOk()
            ->assertSeeInOrder(['Książka babci', 'Przepisy na każdą niedzielę.', 'Spis treści', 'Żurek wielkanocny', 'Przepis 1 z 1'])
            ->assertSee('Zeszyt osoby Halina')
            ->assertSee('Skąd ten przepis')
            ->assertSee('Robiła go co Wielkanoc.')
            ->assertSee('W rodzinie od 1974')
            ->assertSee('Mąka do: Żurek wielkanocny')
            ->assertSee('Wymieszaj: Żurek wielkanocny')
            ->assertSee('Notatka z zeszytu')
            ->assertSee('Dodać więcej majeranku')
            ->assertSee('Adres przepisu: '.$przepis->url())
            ->assertSee('Wydrukuj zeszyt')
            ->assertSee('data-drukuj-przepis', false);
    }

    public function test_strona_wydruku_ma_noindex_takze_dla_publicznego_zeszytu(): void
    {
        $publiczny = $this->zeszyt($this->halina, 'Publiczna książka', 'public');
        $this->przepis($this->halina, 'Sernik', $publiczny);

        $this->druk(null, $publiczny)
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_spis_jest_alfabetyczny_i_linkuje_do_wlasciwych_przepisow(): void
    {
        $this->przepis($this->halina, 'Zupa ogórkowa');
        $this->przepis($this->halina, 'Babka drożdżowa');
        $this->przepis($this->halina, 'Pierogi ruskie');

        $html = (string) $this->druk($this->halina)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="#przepis-1">Babka drożdżowa<\/a>.*<a href="#przepis-2">Pierogi ruskie<\/a>.*<a href="#przepis-3">Zupa ogórkowa<\/a>/s', $html);

        foreach ([1 => 'Babka drożdżowa', 2 => 'Pierogi ruskie', 3 => 'Zupa ogórkowa'] as $numer => $tytul) {
            $this->assertMatchesRegularExpression(
                '/<article class="zeszyt-przepis" id="przepis-'.$numer.'"[^>]*>\s*<p[^>]*>Przepis '.$numer.' z 3<\/p>\s*<h2[^>]*>'.preg_quote($tytul, '/').'<\/h2>/u',
                $html,
                "Pozycja {$numer} spisu nie prowadzi do przepisu „{$tytul}”.",
            );
        }

        // Deterministycznie: drugi wydruk jest identyczny co do znaku
        // (poza datą, która w jednym dniu jest ta sama).
        $this->assertSame($html, (string) $this->druk($this->halina)->getContent());
    }

    public function test_gosc_i_obcy_widza_publiczny_zeszyt_bez_notatek_a_prywatny_dostaje_403(): void
    {
        $this->przepis($this->halina, 'Kotlet', null, [], 'Notatka tylko dla domowników');

        $this->druk()->assertForbidden();
        $this->druk($this->obca)->assertForbidden();

        $this->zeszyt->forceFill(['visibility' => 'public'])->save();

        foreach ([null, $this->obca] as $widz) {
            $this->druk($widz)
                ->assertOk()
                ->assertSee('Kotlet')
                ->assertDontSee('Notatka tylko dla domowników')
                ->assertDontSee('Notatka z zeszytu');
        }
    }

    public function test_wspolpracownik_widzi_wydruk_z_notatkami_a_po_cofnieciu_dostepu_dostaje_403(): void
    {
        $this->przepis($this->halina, 'Kotlet', null, [], 'Notatka tylko dla domowników');
        $this->zeszyt->members()->attach($this->jurek->getKey(), ['created_at' => now()]);

        $this->druk($this->jurek, null, ['z-notatkami' => 1])
            ->assertOk()
            ->assertSee('Kotlet')
            ->assertSee('Notatka tylko dla domowników');
        // Domyślnie bez notatek także dla współpracownika (D-333).
        $this->druk($this->jurek)
            ->assertOk()
            ->assertSee('Kotlet')
            ->assertDontSee('Notatka tylko dla domowników');

        $this->zeszyt->members()->detach($this->jurek->getKey());

        $this->druk($this->jurek)->assertForbidden();
        // To samo, co ekran zeszytu — wydruk nie ma osobnej furtki.
        $this->actingAs($this->jurek)->get(route('collections.show', $this->zeszyt))->assertForbidden();
    }

    public function test_wlasny_prywatny_przepis_wlasciciela_jest_na_wydruku_ale_nie_u_wspolpracownika(): void
    {
        $this->przepis($this->halina, 'Tajny sernik', null, ['visibility' => 'private']);
        $this->zeszyt->members()->attach($this->jurek->getKey(), ['created_at' => now()]);

        $this->druk($this->halina)->assertOk()->assertSee('Tajny sernik');
        $this->druk($this->jurek)->assertOk()->assertDontSee('Tajny sernik');
    }

    /**
     * @return array<string, array{Closure(User, User, Recipe): mixed}>
     */
    public static function granice(): array
    {
        return [
            'przepis prywatny' => [fn (User $o, User $z, Recipe $r) => $r->forceFill(['visibility' => 'private'])->save()],
            'przepis dla obserwujących, oglądająca nie obserwuje' => [fn (User $o, User $z, Recipe $r) => $r->forceFill(['visibility' => 'followers'])->save()],
            'ukryty przez moderację' => [fn (User $o, User $z, Recipe $r) => $r->forceFill(['status' => Recipe::STATUS_HIDDEN])->save()],
            'usunięty przez moderację' => [fn (User $o, User $z, Recipe $r) => $r->forceFill(['status' => Recipe::STATUS_REMOVED])->save()],
            'skasowany przez autora' => [fn (User $o, User $z, Recipe $r) => $r->delete()],
            'autor zbanowany' => [fn (User $o, User $z, Recipe $r) => $z->forceFill(['status' => User::STATUS_BANNED])->save()],
            'autor zamyka konto' => [fn (User $o, User $z, Recipe $r) => $z->forceFill(['status' => User::STATUS_PENDING_DELETE])->save()],
            'oglądająca zablokowała autora' => [fn (User $o, User $z, Recipe $r) => $o->blocking()->attach($z->getKey(), ['created_at' => now()])],
            'autor zablokował oglądającą' => [fn (User $o, User $z, Recipe $r) => $z->blocking()->attach($o->getKey(), ['created_at' => now()])],
        ];
    }

    /**
     * @param  Closure(User, User, Recipe): void  $granica
     */
    #[DataProvider('granice')]
    public function test_niedostepny_cudzy_przepis_jest_pominiety_bez_tytulu_autora_i_adresu(Closure $granica): void
    {
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Kowal']);
        $this->przepis($this->halina, 'Widoczny barszcz');
        $ukryty = $this->przepis($zenek, 'Bigos Zenka z suszonymi śliwkami', null, [], 'Notatka do bigosu');
        $slug = $ukryty->slug;

        $granica($this->halina, $zenek, $ukryty);

        $html = (string) $this->druk($this->halina)->assertOk()->assertSee('Widoczny barszcz')->getContent();

        foreach (['Bigos Zenka z suszonymi śliwkami', 'Zenek Kowal', $slug, 'Notatka do bigosu', 'Mąka do: Bigos'] as $zakazane) {
            $this->assertStringNotContainsString($zakazane, $html, "Wydruk zdradził „{$zakazane}” przepisu, którego oglądająca nie widzi.");
        }
        // Spis i numeracja liczą tylko to, co na wydruku jest.
        $this->assertStringContainsString('Przepis 1 z 1', $html);
        $this->assertStringContainsString('1 przepis', $html);
    }

    public function test_cudzy_widoczny_przepis_jest_podpisany_autorem(): void
    {
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Kowal']);
        $this->przepis($zenek, 'Bigos Zenka');

        $this->druk($this->halina)->assertOk()->assertSee('Bigos Zenka')->assertSee('Zenek Kowal');
    }

    public function test_liczba_wykonan_liczy_tylko_to_co_widzi_ogladajacy_i_nie_jest_rankingiem(): void
    {
        $przepis = $this->przepis($this->halina, 'Sernik');
        $zablokowany = $this->user('zablokowany');
        $this->halina->blocking()->attach($zablokowany->getKey(), ['created_at' => now()]);
        CookedEvent::factory()->count(2)->create(['recipe_id' => $przepis->getKey()]);
        CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $zablokowany->getKey()]);

        $html = (string) $this->druk($this->halina)->assertOk()->assertSee('W Kuking: 2 wykonania')->getContent();

        $this->assertStringNotContainsString('3 wykonania', $html);
        $this->assertStringNotContainsStringIgnoringCase('ranking', $html);
    }

    public function test_bez_zdjec_to_zwykly_parametr_adresu(): void
    {
        $zdjecie = $this->przepis($this->halina, 'Sernik', null, []);
        $zdjecie->forceFill(['hero_media_id' => Media::factory()->create(['owner_id' => $this->halina->getKey()])->getKey()])->save();

        $this->druk($this->halina)
            ->assertOk()
            ->assertSee('class="zeszyt-zdjecie"', false)
            ->assertSee('class="post-photo"', false)
            ->assertSee('Bez zdjęć');

        $this->druk($this->halina, null, ['bez-zdjec' => 1])
            ->assertOk()
            ->assertDontSee('class="zeszyt-zdjecie"', false)
            ->assertDontSee('class="post-photo"', false)
            ->assertSee('Ze zdjęciami');
    }

    public function test_liczba_zapytan_nie_zalezy_od_liczby_przepisow(): void
    {
        $this->przepis($this->halina, 'Jedyny przepis');
        $jedno = $this->liczbaZapytan();

        foreach (range(1, 10) as $i) {
            $autor = $this->user("autor{$i}", ['display_name' => "Autor {$i}"]);
            $przepis = $this->przepis($autor, "Przepis numer {$i}", null, [], "Notatka {$i}");
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'group_name' => 'Ciasto', 'ingredient_text' => 'Jajko']);
            RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Upiecz']);
            CookedEvent::factory()->create(['recipe_id' => $przepis->getKey()]);
        }
        $jedenascie = $this->liczbaZapytan();

        $this->assertSame($jedno, $jedenascie, "1 przepis: {$jedno} zapytań, 11 przepisów: {$jedenascie} — wydruk ma N+1.");
    }

    private function liczbaZapytan(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->druk($this->halina)->assertOk();
        $liczba = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $liczba;
    }

    public function test_dlugi_zeszyt_jest_obciety_do_limitu_i_mowi_o_tym(): void
    {
        config(['kuking.collections.print_max_recipes' => 3]);
        foreach (['A', 'B', 'C', 'D', 'E'] as $litera) {
            $this->przepis($this->halina, "Przepis {$litera}");
        }

        $this->druk($this->halina)
            ->assertOk()
            ->assertSee('Przepis 3 z 3')
            ->assertDontSee('Przepis 4 z')
            ->assertDontSee('Przepis D')
            ->assertSee('pierwszych 3 w kolejności alfabetycznej');
    }

    public function test_pusty_zeszyt_daje_zdanie_zamiast_pustej_ksiazki(): void
    {
        $this->druk($this->halina)
            ->assertOk()
            ->assertSee('nie ma przepisów do wydrukowania')
            // Issue #2369: pusty stan nie zakłada rodzaju odbiorcy.
            ->assertDontSee('mógłbyś')
            ->assertDontSee('mogłabyś');
    }

    public function test_przycisk_na_stronie_zeszytu_jest_tekstem_i_tylko_gdy_jest_co_drukowac(): void
    {
        $this->actingAs($this->halina)->get(route('collections.show', $this->zeszyt))
            ->assertOk()
            ->assertDontSee('Wydrukuj zeszyt');

        $this->przepis($this->halina, 'Sernik');

        $this->actingAs($this->halina)->get(route('collections.show', $this->zeszyt))
            ->assertOk()
            ->assertSee('>Wydrukuj zeszyt</a>', false)
            ->assertSee(route('collections.print', ['collection' => $this->zeszyt, 'druk' => 1]), false);
    }

    public function test_strona_zeszytu_nie_pokazuje_przycisku_gdy_przepisy_sa_niewidoczne_dla_ogladajacego(): void
    {
        $publiczny = $this->zeszyt($this->halina, 'Publiczny', 'public');
        $this->przepis($this->halina, 'Prywatny sernik', $publiczny, ['visibility' => 'private']);

        $this->actingAs($this->obca)->get(route('collections.show', $publiczny))
            ->assertOk()
            ->assertDontSee('Wydrukuj zeszyt')
            ->assertDontSee('Prywatny sernik');
    }

    public function test_wydruk_niesie_alergeny_wedlug_autora_gdy_funkcja_wlaczona(): void
    {
        config(['kuking.alergeny.wlaczone' => true]);
        $zadeklarowany = $this->przepis($this->halina, 'Sernik');
        $zadeklarowany->forceFill(['allergen_status' => 'declared', 'allergens' => ['milk', 'eggs'], 'allergens_declared_at' => now()])->save();
        $this->przepis($this->halina, 'Szarlotka');

        $html = (string) $this->druk($this->halina)->assertOk()->getContent();

        $this->assertStringContainsString('Alergeny według autora:', $html);
        $this->assertStringContainsString('Alergeny: nie sprawdzono.', $html);
        $this->assertSame(2, substr_count($html, 'class="zeszyt-alergeny"'));
        $this->assertStringNotContainsString('id="alergeny"', $html);

        config(['kuking.alergeny.wlaczone' => false]);
        $this->druk($this->halina)->assertOk()->assertDontSee('zeszyt-alergeny', false);
    }

    public function test_adres_nie_jest_autoryzacja_nieistniejacy_zeszyt_to_404(): void
    {
        $this->actingAs($this->halina)
            ->get('/zeszyt/'.Str::uuid().'/do-druku')
            ->assertNotFound();
    }

    public function test_wydruk_ma_wlasny_limit_zapytan(): void
    {
        $this->przepis($this->halina, 'Sernik');

        $limit = (int) explode(',', (string) config('kuking.limits.zeszyt_druk'))[0];
        foreach (range(1, $limit) as $_) {
            $this->druk($this->halina)->assertOk();
        }

        $this->druk($this->halina)->assertStatus(429);
    }
}
