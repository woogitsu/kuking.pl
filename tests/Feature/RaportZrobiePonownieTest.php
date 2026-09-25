<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZrobiePonownie;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Zrobię ponownie" w `kuking:raport` jako trzy stany: tak / nie / brak
 * odpowiedzi — `NULL` nie jest „nie" (issue #1509, #767).
 */
class RaportZrobiePonownieTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-08 12:00:00';

    private User $autorka;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC'));
        $this->autorka = $this->user('autorka');
        $this->przepis = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
    }

    private function teraz(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TERAZ, 'UTC');
    }

    private function wykonanie(User $kto, ?bool $zrobiePonownie, ?CarbonImmutable $kiedy = null, ?Recipe $przepis = null): void
    {
        CookedEvent::factory()->create([
            'user_id' => $kto->getKey(),
            'recipe_id' => ($przepis ?? $this->przepis)->getKey(),
            'would_make_again' => $zrobiePonownie,
            'cooked_at' => $kiedy ?? $this->teraz()->subDay(),
        ]);
    }

    public function test_trzy_stany_brak_odpowiedzi_nie_jest_nie(): void
    {
        $this->wykonanie($this->user('tak_1'), true);
        $this->wykonanie($this->user('tak_2'), true);
        $this->wykonanie($this->user('nie_1'), false);
        $this->wykonanie($this->user('brak_1'), null);
        $this->wykonanie($this->user('brak_2'), null);
        $this->wykonanie($this->user('brak_3'), null);

        $cudze = app(ZrobiePonownie::class)->policz()['cudze'];

        $this->assertSame(2, $cudze['tak']);
        // KONTROLA: gdyby `NULL` wpadał do „nie", byłoby tu 4.
        $this->assertSame(1, $cudze['nie']);
        $this->assertSame(3, $cudze['brak']);
        $this->assertSame(6, $cudze['wszystkie']);
        $this->assertSame(50.0, $cudze['odsetek_odpowiedzi']);
        // Trzy odpowiedzi to mniej niż minimum — bez procentu.
        $this->assertNull($cudze['odsetek_tak']);
    }

    public function test_odsetek_tak_liczy_mianownik_bez_braku_odpowiedzi(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->wykonanie($this->user("tak_{$i}"), true);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->wykonanie($this->user("nie_{$i}"), false);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->wykonanie($this->user("brak_{$i}"), null);
        }

        $cudze = app(ZrobiePonownie::class)->policz()['cudze'];

        // 15 / (15 + 5); z brakami w mianowniku wyszłoby 37,5.
        $this->assertSame(75.0, $cudze['odsetek_tak']);
        $this->assertSame(50.0, $cudze['odsetek_odpowiedzi']);
    }

    public function test_wlasny_przepis_autora_liczy_sie_osobno(): void
    {
        $this->wykonanie($this->autorka, true);
        $this->wykonanie($this->user('gosc'), false);

        $wynik = app(ZrobiePonownie::class)->policz();

        $this->assertSame(['tak' => 1, 'nie' => 0, 'brak' => 0], array_intersect_key($wynik['wlasne'], ['tak' => 0, 'nie' => 0, 'brak' => 0]));
        $this->assertSame(['tak' => 0, 'nie' => 1, 'brak' => 0], array_intersect_key($wynik['cudze'], ['tak' => 0, 'nie' => 0, 'brak' => 0]));
    }

    public function test_powtorne_wykonanie_tej_samej_osoby_liczy_sie_kazde_osobno(): void
    {
        // Ta sama osoba, ten sam przepis, zmienione zdanie — oba gotowania
        // są w raporcie (jednostką jest wykonanie, nie para osoba–przepis).
        $basia = $this->user('basia');
        $this->wykonanie($basia, true, $this->teraz()->subDays(5));
        $this->wykonanie($basia, false, $this->teraz()->subDays(2));

        $cudze = app(ZrobiePonownie::class)->policz()['cudze'];

        $this->assertSame(1, $cudze['tak']);
        $this->assertSame(1, $cudze['nie']);
        $this->assertSame(2, $cudze['wszystkie']);
    }

    public function test_okno_trzydziestu_dni_i_ukryty_przepis(): void
    {
        $this->wykonanie($this->user('dawno'), true, $this->teraz()->subDays(ZrobiePonownie::DNI)->subMinute());
        $this->wykonanie($this->user('na_granicy'), true, $this->teraz()->subDays(ZrobiePonownie::DNI)->addMinute());

        // Przepis ukryty moderacyjnie zostawia historyczne wykonanie.
        $ukryty = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
        $this->wykonanie($this->user('z_ukrytego'), null, przepis: $ukryty);
        $ukryty->delete();

        $cudze = app(ZrobiePonownie::class)->policz()['cudze'];

        $this->assertSame(1, $cudze['tak']);
        $this->assertSame(1, $cudze['brak']);
    }

    public function test_wyklucza_konta_testowe(): void
    {
        config(['kuking.account.test_usernames' => ['qa_wewnetrzne']]);
        $this->wykonanie($this->user('qa_wewnetrzne'), false);
        $this->wykonanie($this->user('prawdziwa'), true);

        $cudze = app(ZrobiePonownie::class)->policz()['cudze'];

        $this->assertSame(1, $cudze['tak']);
        $this->assertSame(0, $cudze['nie']);
    }

    public function test_komenda_pokazuje_trzy_stany_bez_danych_osobowych(): void
    {
        $this->wykonanie($this->user('tak_osoba'), true);
        $this->wykonanie($this->user('nie_osoba'), false);
        $this->wykonanie($this->user('cicha_osoba'), null);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Cudze przepisy: tak 1 · nie 1 · brak odpowiedzi 1 · odpowiedziało 66,7% z 3 wykonań · za mało danych')
            ->expectsOutputToContain('Własne przepisy autora: brak wykonań w tym okresie.')
            ->doesntExpectOutputToContain('autorka')
            ->doesntExpectOutputToContain('cicha_osoba')
            ->doesntExpectOutputToContain($this->przepis->title);
    }

    public function test_pusta_baza_nie_wywala_komendy(): void
    {
        CookedEvent::query()->delete();

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Cudze przepisy: brak wykonań w tym okresie.');
    }
}
