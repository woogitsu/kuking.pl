<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoUgotuje;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Co ugotuję z tego, co mam” (V2, D-285).
 *
 * Reguła doboru jednym zdaniem: najpierw przepisy, w których brakuje
 * najmniej składników z listy; przy remisie krótszy łączny czas. Nic więcej —
 * w szczególności NIE reakcje innych ludzi (AGENTS.md §8, §12).
 */
class CoUgotujeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_nie_wchodzi(): void
    {
        $this->get(route('pantry.cook'))->assertRedirect(route('login'));
    }

    public function test_pusta_lista_mowi_co_zrobic(): void
    {
        $this->actingAs($this->user())->get(route('pantry.cook'))
            ->assertOk()
            ->assertSee('Najpierw wpisz, co masz w domu')
            ->assertSee(route('pantry.index'), false);
    }

    public function test_kolejnosc_najpierw_najmniej_brakujacych_potem_czas_a_nie_popularnosc(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['jajka', 'mąka', 'mleko', 'masło', 'cukier']);

        $wolny = $this->przepis('Wolny naleśnik', ['jajka', 'mąka', 'mleko', 'masło', 'szczypta soli'], 30, 60);
        $szybki = $this->przepis('Szybki omlet', ['jajka', 'mleko', 'masło', 'szczypiorek'], 5, 5);
        $komplet = $this->przepis('Kogel-mogel', ['2 żółtka', 'cukier'], 5, 0);
        $bezCzasu = $this->przepis('Placki bez czasu', ['jajka', 'mąka', 'kefir'], null, null);
        $this->przepis('Bigos', ['kapusta kiszona', 'kiełbasa'], 60, 120);

        // „Kogel-mogel”: żółtka nie ma na liście, cukier jest — 1 brakujący.
        // Dopiero przepis ze WSZYSTKIM ma brakujących 0:
        $wszystko = $this->przepis('Ciasto ucierane', ['Jajko', 'mąki', 'masła', 'cukier puder'], 20, 45);

        // Popularność nie ma nic do rzeczy: „Wolny naleśnik” ugotowano
        // dziesięć razy, a i tak nie przeskoczy szybszego przepisu z tą samą
        // liczbą brakujących.
        CookedEvent::factory()->count(10)->create(['recipe_id' => $wolny->getKey()]);

        $wynik = app(CoUgotuje::class)->dla($ja);

        $this->assertSame(
            [$wszystko->title, $komplet->title, $szybki->title, $wolny->title, $bezCzasu->title],
            $wynik['przepisy']->pluck('title')->all(),
        );
        $this->assertSame(['2 żółtka'], $wynik['brakujace'][$komplet->getKey()]);
        $this->assertSame([], $wynik['brakujace'][$wszystko->getKey()]);
    }

    public function test_przy_przepisie_widac_ile_mam_i_czego_brakuje(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['Jajka', 'mąka', 'mleko', 'olej', 'cukier']);
        $this->przepis('Naleśniki', ['2 jajka', 'Mąka pszenna typ 500', 'mleko 3,2%', 'olej', 'cukier', 'szczypta soli', 'woda gazowana'], 10, 20);
        $this->przepis('Jajecznica', ['jajko', 'masło'], 2, 5);

        $this->actingAs($ja)->get(route('pantry.cook'))
            ->assertOk()
            ->assertSee(CoUgotuje::REGULA)
            ->assertSee('Masz 5 z 7 składników.')
            ->assertSee('Brakuje: szczypta soli, woda gazowana.')
            ->assertSee('Masz 1 z 2 składników.')
            ->assertSee('Brakuje: masło.');
    }

    public function test_wszystkie_skladniki_to_osobny_komunikat(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['ziemniaki', 'sól']);
        $this->przepis('Ziemniaki gotowane', ['Ziemniak', 'sól do smaku'], 5, 20);

        $this->actingAs($ja)->get(route('pantry.cook'))
            ->assertOk()
            ->assertSee('Masz wszystkie składniki (2).');
    }

    public function test_bierze_tylko_przepisy_ktore_wolno_mi_otworzyc(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['jajka']);

        $publiczny = $this->przepis('Publiczna jajecznica', ['jajka']);
        $this->przepis('Cudza prywatna', ['jajka'], atrybuty: ['visibility' => 'private']);
        $this->przepis('Cudza dla obserwujących', ['jajka'], atrybuty: ['visibility' => 'followers']);
        $this->przepis('Mój szkic', ['jajka'], atrybuty: ['author_id' => $ja->getKey()], szkic: true);
        $mojPrywatny = $this->przepis('Mój prywatny', ['jajka'], atrybuty: ['author_id' => $ja->getKey(), 'visibility' => 'private']);

        $zablokowany = $this->user();
        $ja->blocking()->attach($zablokowany->getKey(), ['created_at' => now()]);
        $this->przepis('Od zablokowanej', ['jajka'], atrybuty: ['author_id' => $zablokowany->getKey()]);

        $blokujacy = $this->user();
        $blokujacy->blocking()->attach($ja->getKey(), ['created_at' => now()]);
        $this->przepis('Od blokującej', ['jajka'], atrybuty: ['author_id' => $blokujacy->getKey()]);

        $zbanowany = $this->user();
        $this->przepis('Od zbanowanej', ['jajka'], atrybuty: ['author_id' => $zbanowany->getKey()]);
        $zbanowany->ban();

        $tytuly = app(CoUgotuje::class)->dla($ja)['przepisy']->pluck('title')->sort()->values()->all();

        $this->assertSame([$mojPrywatny->title, $publiczny->title], $tytuly);
    }

    public function test_przepis_bez_zadnego_mojego_skladnika_nie_wchodzi(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mak']);
        $this->przepis('Makowiec', ['mak niebieski', 'miód']);
        $this->przepis('Makaron', ['makaron świderki']);

        $this->assertSame(['Makowiec'], app(CoUgotuje::class)->dla($ja)['przepisy']->pluck('title')->all());
    }

    /**
     * #1969: „mąka” i „mak” miały ten sam rdzeń (`maka` → `mak`), więc produkt
     * „mąka” zaliczał przepis z makiem i odwrotnie. Ekran jest odpowiedzią na
     * pytanie „co ugotuję z tego, co mam” — fałszywe „masz” to przepis, do
     * którego człowiek nie ma podstawowego składnika.
     */
    public function test_maka_i_mak_to_rozne_produkty_w_obie_strony(): void
    {
        $zMaka = $this->user();
        $this->lista($zMaka, ['mąka']);
        $this->przepis('Makowiec', ['mak do nadzienia']);
        $this->przepis('Chleb', ['mąka żytnia']);

        $this->assertSame(['Chleb'], app(CoUgotuje::class)->dla($zMaka)['przepisy']->pluck('title')->all());

        $zMakiem = $this->user();
        $this->lista($zMakiem, ['mak']);

        $this->assertSame(['Makowiec'], app(CoUgotuje::class)->dla($zMakiem)['przepisy']->pluck('title')->all());
    }

    /**
     * Produkt z listy → linijka składnika: czy „masz”. Rdzenie różnych
     * produktów nie mogą się łapać nawzajem (#1969), a odmiany tego samego
     * produktu dalej się łączą — bez tego poprawka byłaby wyłączeniem funkcji.
     */
    public function test_rdzenie_nie_lapia_sie_nawzajem_a_odmiany_dalej_pasuja(): void
    {
        $pasuje = fn (string $produkt, string $linijka): bool => (bool) DB::selectOne(
            'SELECT public.kuking_rdzenie_skladnika(?) <@ public.kuking_rdzenie_skladnika(?) AS jest',
            [$produkt, $linijka],
        )->jest;

        $rozne = [
            ['mąka', 'mak'], ['mąka', 'mak do nadzienia'], ['mąka', 'maku'], ['mąka', 'makaron'],
            ['mak', 'mąka'], ['mak', 'mąki'], ['mak', 'mąkę'], ['mak', 'makaron świderki'],
            ['makaron', 'mak'], ['makaron', 'mąka'], ['kawa', 'kawior'], ['woda', 'wódka'],
            ['ser', 'serek wiejski'], ['sól', 'solanka'], ['por', 'porzeczki'], ['sok', 'sos'],
            ['lód', 'lody waniliowe'], ['lody', 'lód do drinków'],
        ];
        foreach ($rozne as [$produkt, $linijka]) {
            $this->assertFalse($pasuje($produkt, $linijka), "„{$produkt}” nie może zaliczać składnika „{$linijka}”.");
        }

        $odmiany = [
            ['mąka', 'mąki'], ['mąka', 'mąkę'], ['mąka', 'mąką'], ['mąka', 'Mąka pszenna typ 500'], ['mąki', 'mąka'],
            ['mak', 'maku'], ['mak', 'mak niebieski'], ['ser', 'sera'], ['ser', 'ser żółty'], ['kawa', 'kawy'],
            ['woda', 'wody'], ['sól', 'soli'], ['ryż', 'ryżu'], ['sok', 'soku z cytryny'], ['ryba', 'ryby'],
            ['Cebula', 'cebule'], ['Pomidory', 'pomidorów'], ['jajka', 'Jajko'], ['masło', 'masła'],
            ['mleko', 'mleko 3,2%'], ['ziemniaki', 'Ziemniak'], ['kasza', 'kaszy jaglanej'],
        ];
        foreach ($odmiany as [$produkt, $linijka]) {
            $this->assertTrue($pasuje($produkt, $linijka), "„{$produkt}” powinno zaliczać składnik „{$linijka}”.");
        }
    }

    /**
     * Słownik form stoi w bazie (`kuking_formy_skladnikow()`), a wstępny filtr
     * `LIKE` w `CoUgotuje` szuka pierwszych trzech liter krótkiego rdzenia.
     * Filtr niczego nie gubi tylko wtedy, gdy każda forma zaczyna się od tych
     * trzech liter, a każdy rdzeń ze słownika ma najwyżej 4 litery — ten test
     * pilnuje tego przy każdym dopisaniu formy.
     */
    public function test_slownik_form_nie_psuje_wstepnego_filtra(): void
    {
        $slownik = json_decode((string) DB::selectOne('SELECT public.kuking_formy_skladnikow()::text AS s')->s, true);

        $this->assertIsArray($slownik);
        $this->assertGreaterThan(20, count($slownik));
        foreach ($slownik as $forma => $rdzen) {
            $this->assertLessThanOrEqual(4, strlen($rdzen), "Rdzeń „{$rdzen}” ze słownika jest dłuższy niż 4 litery.");
            $this->assertStringStartsWith(substr($rdzen, 0, 3), $forma, "Forma „{$forma}” nie zaczyna się od pierwszych liter rdzenia „{$rdzen}”.");
        }

        // Z krótkim rdzeniem przepis z formą spoza rdzenia („mąki”) dalej
        // przechodzi wstępny filtr.
        $ja = $this->user();
        $this->lista($ja, ['mąka']);
        $this->przepis('Kluski', ['2 szklanki mąki']);

        $this->assertSame(['Kluski'], app(CoUgotuje::class)->dla($ja)['przepisy']->pluck('title')->all());
    }

    public function test_cudza_lista_nie_wplywa_na_moj_wynik(): void
    {
        $ja = $this->user();
        $inna = $this->user();
        $this->lista($ja, ['jajka']);
        $this->lista($inna, ['masło']);
        $this->przepis('Jajecznica', ['jajka', 'masło']);

        $wynik = app(CoUgotuje::class)->dla($ja);

        $this->assertSame(1, (int) $wynik['przepisy']->first()?->skladnikow_brakuje);
        $this->assertSame(['masło'], $wynik['brakujace'][$wynik['przepisy']->first()?->getKey()]);
    }

    public function test_pokaz_wiecej_jest_przyciskiem_i_dociaga_reszte(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['jajka']);
        for ($i = 1; $i <= CoUgotuje::NA_STRONE + 1; $i++) {
            $this->przepis("Jajeczny przepis {$i}", ['jajka', 'dodatek '.$i], $i, 0);
        }

        $this->actingAs($ja)->get(route('pantry.cook'))
            ->assertOk()
            ->assertSee('Pokaż więcej przepisów')
            ->assertSee(route('pantry.cook', ['od' => CoUgotuje::NA_STRONE]), false)
            ->assertDontSee('Jajeczny przepis '.(CoUgotuje::NA_STRONE + 1).'<', false);

        $this->actingAs($ja)->get(route('pantry.cook', ['od' => CoUgotuje::NA_STRONE]))
            ->assertOk()
            ->assertSee('Jajeczny przepis '.(CoUgotuje::NA_STRONE + 1))
            ->assertDontSee('Pokaż więcej przepisów');
    }

    public function test_zeszyt_prowadzi_do_obu_ekranow(): void
    {
        $this->actingAs($this->user())->get(route('collections.index'))
            ->assertOk()
            ->assertSee(route('pantry.index'), false)
            ->assertSee(route('pantry.cook'), false);
    }

    /** @param  list<string>  $nazwy */
    private function lista(User $user, array $nazwy): void
    {
        foreach ($nazwy as $nazwa) {
            $user->pantryItems()->create(['name' => $nazwa]);
        }
    }

    /**
     * @param  list<string>  $skladniki
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepis(string $tytul, array $skladniki, ?int $przygotowanie = 10, ?int $gotowanie = 10, array $atrybuty = [], bool $szkic = false): Recipe
    {
        $fabryka = Recipe::factory();
        $przepis = ($szkic ? $fabryka->draft() : $fabryka)->create([
            'title' => $tytul,
            'prep_minutes' => $przygotowanie,
            'cook_minutes' => $gotowanie,
            ...(isset($atrybuty['author_id']) ? [] : ['author_id' => $this->user()->getKey()]),
            ...$atrybuty,
        ]);

        foreach ($skladniki as $i => $tekst) {
            $przepis->ingredients()->create(['position' => $i, 'ingredient_text' => $tekst]);
        }

        return $przepis;
    }
}
