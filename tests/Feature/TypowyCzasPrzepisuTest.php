<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\TypowyCzasPrzepisu;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Typowy rzeczywisty czas z wykonań (#2067, D-333): próg 5 różnych osób,
 * mediana na osobę, odcięcie powyżej doby, zaokrąglenie do 5 minut, blokady
 * i status konta kucharza. Koszt zapytania: `TypowyCzasPrzepisuKosztTest`.
 */
final class TypowyCzasPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->user('autorka_czasu')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'prep_minutes' => 20,
            'cook_minutes' => 25,
        ]);
    }

    /** @param  list<int|null>  $minuty  jedno wykonanie na każdą wartość, każde innej osoby */
    private function osobyZCzasami(array $minuty, array $atrybutyOsoby = []): array
    {
        $osoby = [];
        foreach ($minuty as $m) {
            $osoba = $this->user(null, $atrybutyOsoby);
            $this->wykonanie($osoba, $m);
            $osoby[] = $osoba;
        }

        return $osoby;
    }

    private function wykonanie(User $osoba, ?int $minuty): CookedEvent
    {
        return CookedEvent::factory()->create([
            'recipe_id' => $this->przepis->getKey(),
            'user_id' => $osoba->getKey(),
            'actual_minutes' => $minuty,
        ]);
    }

    public function test_cztery_osoby_to_za_malo_i_nie_ma_nic_na_stronie(): void
    {
        $this->osobyZCzasami([60, 60, 60, 60]);

        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, null));
        $this->get(route('recipes.show', $this->przepis->slug))->assertOk()
            ->assertDontSee('Gotujący zwykle potrzebują', escape: false)
            ->assertDontSee('za mało', escape: false);
    }

    public function test_piec_osob_pokazuje_mediane_i_dwa_zdania_na_stronie(): void
    {
        $this->osobyZCzasami([50, 55, 60, 65, 70]);

        $wynik = TypowyCzasPrzepisu::dla($this->przepis, null);

        $this->assertSame(60, $wynik?->minuty);
        $this->assertSame(5, $wynik?->osoby);

        $this->get(route('recipes.show', $this->przepis->slug))->assertOk()
            ->assertSee('Autor podaje około 45 min.', escape: false)
            ->assertSee('Gotujący zwykle potrzebują około 60 min (na podstawie 5 osób).', escape: false);
    }

    public function test_od_90_minut_czasy_sa_w_godzinach_na_calej_stronie(): void
    {
        $this->przepis->forceFill(['prep_minutes' => 30, 'cook_minutes' => 60])->save();
        $this->osobyZCzasami([120, 120, 120, 120, 120]);

        $html = $this->get(route('recipes.show', $this->przepis->slug))->assertOk()
            ->assertSee('<strong>Około 1 godz. 30 min</strong>', escape: false)
            ->assertSee('Autor podaje około 1 godz. 30 min.', escape: false)
            ->assertSee('Gotujący zwykle potrzebują około 2 godz. (na podstawie 5 osób).', escape: false)
            ->getContent();

        // Dane strukturalne zostają w ISO 8601, nie w godzinach po polsku.
        $this->assertSame('PT1H30M', $this->przepis->fresh()->totalTimeIso());

        // Dawny zapis „N min” przy czasie od 90 minut nie wraca w kafelku ani w zdaniach.
        $this->assertDoesNotMatchRegularExpression('/Około 90 min|około 90 min|około 120 min/u', $html);
    }

    public function test_bez_czasu_autora_jest_tylko_zdanie_o_gotujacych(): void
    {
        $this->przepis->forceFill(['prep_minutes' => null, 'cook_minutes' => null])->save();
        $this->osobyZCzasami([40, 40, 40, 40, 40]);

        $this->get(route('recipes.show', $this->przepis->slug))->assertOk()
            ->assertDontSee('Autor podaje', escape: false)
            ->assertSee('Gotujący zwykle potrzebują około 40 min', escape: false);
    }

    public function test_osoba_liczy_sie_raz_nawet_gdy_gotowala_wiele_razy(): void
    {
        $wielokrotna = $this->user();
        foreach ([60, 60, 60, 60, 60, 60] as $m) {
            $this->wykonanie($wielokrotna, $m);
        }
        $this->osobyZCzasami([60, 60, 60]);

        // 4 osoby (9 wykonań) — poniżej progu.
        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, null));

        $this->osobyZCzasami([60]);
        $this->assertSame(5, TypowyCzasPrzepisu::dla($this->przepis, null)?->osoby);
    }

    public function test_wykonania_jednej_osoby_wchodza_jako_jej_mediana_a_nie_pojedynczo(): void
    {
        // Osoba A: 10 wykonań po 200 min (jej mediana 200). Pozostałe 4 osoby: 20.
        // Mediana median = 20; gdyby liczyć wykonania, wyszłoby 200.
        $zapalona = $this->user();
        foreach (range(1, 10) as $_) {
            $this->wykonanie($zapalona, 200);
        }
        $this->osobyZCzasami([20, 20, 20, 20]);

        $this->assertSame(20, TypowyCzasPrzepisu::dla($this->przepis, null)?->minuty);
    }

    public function test_brak_czasu_zero_i_powyzej_doby_nie_licza_sie(): void
    {
        $this->osobyZCzasami([30, 30, 30, 30]);
        // Szósta, siódma i ósma osoba: nie podały czasu, wpisały 0 albo ponad dobę.
        $this->osobyZCzasami([null, 0, 1441, 10080]);

        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, null), 'Tylko 4 osoby mają ważny czas.');

        // Dokładnie doba (1440) jeszcze wchodzi.
        $this->osobyZCzasami([1440]);
        $wynik = TypowyCzasPrzepisu::dla($this->przepis, null);

        $this->assertSame(5, $wynik?->osoby);
        $this->assertSame(30, $wynik?->minuty, 'Mediana 30, 30, 30, 30, 1440 to 30; 1441+ nie wchodzą w ogóle.');
    }

    public function test_odciete_wartosci_nie_przesuwaja_mediany(): void
    {
        $this->osobyZCzasami([10, 10, 10, 10, 10, 5000, 5000, 5000, 5000, 5000]);

        $this->assertSame(10, TypowyCzasPrzepisu::dla($this->przepis, null)?->minuty);
    }

    #[DataProvider('zaokraglenia')]
    public function test_zaokraglenie_do_pieciu_minut(float $mediana, int $oczekiwane): void
    {
        $this->assertSame($oczekiwane, TypowyCzasPrzepisu::zaokraglij($mediana));
    }

    /** @return array<string, array{float, int}> */
    public static function zaokraglenia(): array
    {
        return [
            '2 min to najmniej 5' => [2.0, 5],
            '7 w dół' => [7.0, 5],
            '8 w górę' => [8.0, 10],
            '62,5 w górę do 65' => [62.5, 65],
            '62 w dół do 60' => [62.0, 60],
            '1440' => [1440.0, 1440],
        ];
    }

    public function test_mediana_z_parzystej_liczby_osob_jest_zaokraglana(): void
    {
        // Mediany osób: 41, 42, 43, 44, 45, 46 → 43,5 → 45.
        $this->osobyZCzasami([41, 42, 43, 44, 45, 46]);

        $this->assertSame(45, TypowyCzasPrzepisu::dla($this->przepis, null)?->minuty);
    }

    public function test_osoba_zablokowana_przez_widza_nie_liczy_sie_dla_niego_ale_liczy_dla_goscia(): void
    {
        $osoby = $this->osobyZCzasami([60, 60, 60, 60, 60]);
        $widz = $this->user();

        $this->assertSame(5, TypowyCzasPrzepisu::dla($this->przepis, $widz)?->osoby);

        Block::query()->create(['blocker_id' => $widz->getKey(), 'blocked_id' => $osoby[0]->getKey(), 'created_at' => now()]);

        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, $widz), 'Widz zablokował jedną z pięciu osób — zostały cztery.');
        $this->assertSame(5, TypowyCzasPrzepisu::dla($this->przepis, null)?->osoby, 'Gość nie ma blokad.');
        $this->actingAs($widz)->get(route('recipes.show', $this->przepis->slug))->assertOk()
            ->assertDontSee('Gotujący zwykle potrzebują', escape: false);
    }

    /**
     * HTML gościa z cache brzegu (#610) niesie liczbę liczoną BEZ blokad, więc
     * jest taki sam dla każdego gościa i nie zdradza niczyich blokad.
     */
    public function test_gosc_w_cache_brzegu_dostaje_liczbe_bez_blokad(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $osoby = $this->osobyZCzasami([60, 60, 60, 60, 60]);
        $widz = $this->user();
        Block::query()->create(['blocker_id' => $widz->getKey(), 'blocked_id' => $osoby[0]->getKey(), 'created_at' => now()]);

        $odpowiedz = $this->get(route('recipes.show', $this->przepis->slug))->assertOk();

        $this->assertStringContainsString('s-maxage=120', (string) $odpowiedz->headers->get('Cache-Control'));
        $odpowiedz->assertSee('na podstawie 5 osób', escape: false);
    }

    public function test_blokada_w_druga_strone_tez_wylacza_osobe(): void
    {
        $osoby = $this->osobyZCzasami([60, 60, 60, 60, 60]);
        $widz = $this->user();
        Block::query()->create(['blocker_id' => $osoby[1]->getKey(), 'blocked_id' => $widz->getKey(), 'created_at' => now()]);

        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, $widz));
    }

    public function test_konto_zbanowane_i_do_usuniecia_nie_liczy_sie_nikomu(): void
    {
        $this->osobyZCzasami([60, 60, 60, 60]);
        $zbanowana = $this->user();
        $zbanowana->forceFill(['status' => User::STATUS_BANNED])->save();
        $this->wykonanie($zbanowana, 60);

        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, null), 'Wykonanie zbanowanej osoby nie wchodzi do agregatu.');
        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, $this->user()));

        $doUsuniecia = $this->user();
        $doUsuniecia->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        $this->wykonanie($doUsuniecia, 60);
        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, null));

        // Kontrola dodatnia: zawieszone konto zostaje (ta sama granica co galeria).
        $zawieszona = $this->user();
        $zawieszona->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->wykonanie($zawieszona, 60);
        $this->assertSame(5, TypowyCzasPrzepisu::dla($this->przepis, null)?->osoby);
    }

    public function test_wykonania_innego_przepisu_nie_wchodza(): void
    {
        $inny = Recipe::factory()->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        foreach (range(1, 5) as $_) {
            CookedEvent::factory()->create(['recipe_id' => $inny->getKey(), 'user_id' => $this->user()->getKey(), 'actual_minutes' => 30]);
        }

        $this->assertNull(TypowyCzasPrzepisu::dla($this->przepis, null));
        $this->assertSame(5, TypowyCzasPrzepisu::dla($inny, null)?->osoby);
    }

    #[DataProvider('odmiany')]
    public function test_slowo_osoby_odmienia_sie_po_polsku(int $osoby, string $oczekiwane): void
    {
        $this->assertStringContainsString("na podstawie {$osoby} {$oczekiwane})", (new TypowyCzasPrzepisu(30, $osoby))->zdanie());
    }

    /** @return array<string, array{int, string}> */
    public static function odmiany(): array
    {
        return [
            '5' => [5, 'osób'], '12' => [12, 'osób'], '22' => [22, 'osoby'], '25' => [25, 'osób'],
            '112' => [112, 'osób'], '103' => [103, 'osoby'],
        ];
    }
}
