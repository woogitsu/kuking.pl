<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\AktywniWTygodniu;
use App\Domain\Analytics\PowrotPoDniach;
use App\Domain\Analytics\ZasiegUgotowalem;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bramka V1 z `docs/ROADMAP.md` — „Planner/groups/forks dopiero gdy WAC
 * i D30 pokazują powroty" — zamieniona w liczbę: `php artisan kuking:raport`
 * i trzy klasy domenowe, które go zasilają (issue #114/#115).
 */
class RaportPowrotowTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-08 12:00:00';

    private function teraz(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TERAZ, 'UTC');
    }

    // ---------------------------------------------------------------
    // AktywniWTygodniu
    // ---------------------------------------------------------------

    public function test_aktywni_w_tygodniu_liczy_widzianych_w_ostatnich_siedmiu_dniach(): void
    {
        $basia = $this->user('basia', ['ostatnio_widziany_at' => $this->teraz()->subDays(2)]);
        // Poza oknem siedmiu dni — nie powinien się liczyć.
        $this->user('dawno', ['ostatnio_widziany_at' => $this->teraz()->subDays(8)]);
        // Nigdy niewidziany — `NULL` nie jest zerem, ale i tak się nie liczy.
        $this->user('nigdy');

        $liczba = app(AktywniWTygodniu::class)->liczba($this->teraz());

        $this->assertSame(1, $liczba);
    }

    public function test_aktywni_w_tygodniu_wyklucza_gospodarza(): void
    {
        $this->user('basia', ['ostatnio_widziany_at' => $this->teraz()]);

        config(['kuking.community.host_username' => 'woogitsu']);
        $this->user('woogitsu', ['ostatnio_widziany_at' => $this->teraz()]);

        $liczba = app(AktywniWTygodniu::class)->liczba($this->teraz());

        // KONTROLA: bez wykluczenia z `CookEligibility` ta liczba wynosiłaby 2.
        $this->assertSame(1, $liczba);
    }

    // ---------------------------------------------------------------
    // PowrotPoDniach
    // ---------------------------------------------------------------

    public function test_powrot_pusta_kohorta_daje_procent_null_nie_zero(): void
    {
        // Wszystkie konta są za młode, żeby w ogóle wejść do kohorty D30.
        $this->user('nowa', ['created_at' => $this->teraz()->subDays(2)]);

        $wynik = app(PowrotPoDniach::class)->policz(30, $this->teraz());

        $this->assertSame(0, $wynik['kwalifikujacy_sie']);
        $this->assertNull(
            $wynik['procent'],
            'Pusta kohorta powinna dać `null`, nie `0.0` — inaczej wygląda jak "nikt nie wraca", '
            .'a naprawdę nie ma jeszcze kogo liczyć.',
        );
    }

    public function test_powrot_liczy_tylko_konta_wystarczajaco_stare(): void
    {
        // Konto starsze niż 7 dni, które wróciło.
        $this->user('wraca', [
            'created_at' => $this->teraz()->subDays(10),
            'ostatnio_widziany_at' => $this->teraz(),
        ]);

        // Konto starsze niż 7 dni, które NIE wróciło (widziane tylko przy rejestracji).
        $swiezaData = $this->teraz()->subDays(10);
        $this->user('niewraca', [
            'created_at' => $swiezaData,
            'ostatnio_widziany_at' => $swiezaData,
        ]);

        // Konto ZA MŁODE (3 dni) — nie miało jeszcze szansy wrócić po tygodniu,
        // więc NIE wchodzi do kohorty w ogóle (kontrola: gdyby wchodziło jako
        // "niewraca", procent poniżej wyszedłby 33,3, nie 50,0).
        $this->user('za_mlode', [
            'created_at' => $this->teraz()->subDays(3),
            'ostatnio_widziany_at' => $this->teraz()->subDays(3),
        ]);

        $wynik = app(PowrotPoDniach::class)->policz(7, $this->teraz());

        $this->assertSame(2, $wynik['kwalifikujacy_sie']);
        $this->assertSame(1, $wynik['wrocilo']);
        $this->assertSame(50.0, $wynik['procent']);
    }

    public function test_powrot_wyklucza_konta_testowe(): void
    {
        $this->user('wraca', [
            'created_at' => $this->teraz()->subDays(10),
            'ostatnio_widziany_at' => $this->teraz(),
        ]);

        config(['kuking.account.test_usernames' => ['qa_wewnetrzne']]);
        $this->user('qa_wewnetrzne', [
            'created_at' => $this->teraz()->subDays(10),
            // Widziane TYLKO przy rejestracji — gdyby konto testowe się liczyło,
            // procent spadłby do 50,0 zamiast zostać 100,0.
            'ostatnio_widziany_at' => $this->teraz()->subDays(10),
        ]);

        $wynik = app(PowrotPoDniach::class)->policz(7, $this->teraz());

        $this->assertSame(1, $wynik['kwalifikujacy_sie']);
        $this->assertSame(100.0, $wynik['procent']);
    }

    // ---------------------------------------------------------------
    // ZasiegUgotowalem
    // ---------------------------------------------------------------

    public function test_zasieg_ugotowalem_liczy_przepisy_i_autorow_bez_duplikatow(): void
    {
        $autorka = $this->user('autorka_przepisu');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey()]);

        // Dwa różne wykonania TEGO SAMEGO przepisu przez dwie różne osoby —
        // przepis liczy się RAZ (dostał "choć jedno" Ugotowałem), ale to są
        // dwa osobne zdarzenia, bo to jest sedno D-005 (żadnego `UNIQUE
        // (user_id, recipe_id)`).
        CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $this->user('gotujaca_1')->getKey()]);
        CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $this->user('gotujaca_2')->getKey()]);

        // Dwa powiadomienia dla TEGO SAMEGO autora — liczy się RAZ: pytanie
        // jest "ilu autorów dostało powiadomienie", nie "ile powiadomień wysłano".
        Notification::create(['user_id' => $autorka->getKey(), 'type' => Notification::TYPE_COOKED, 'data' => []]);
        Notification::create(['user_id' => $autorka->getKey(), 'type' => Notification::TYPE_COOKED, 'data' => []]);

        // Powiadomienie INNEGO typu nie ma się liczyć do "Ugotowałem".
        Notification::create(['user_id' => $autorka->getKey(), 'type' => Notification::TYPE_FOLLOW, 'data' => []]);

        $wynik = app(ZasiegUgotowalem::class)->policz();

        $this->assertSame(1, $wynik['przepisy_z_ugotowalem']);
        $this->assertSame(1, $wynik['autorzy_powiadomieni']);
    }

    public function test_zasieg_ugotowalem_nie_wyklucza_gospodarza(): void
    {
        // KONTROLA CELOWA, przeciwna do testów WAC wyżej: ta klasa mierzy,
        // czy MECHANIZM działa, nie porównuje aktywność w czasie — więc
        // gospodarz i konta testowe MAJĄ się tu liczyć, w odróżnieniu od
        // `AktywniWTygodniu`/`PowrotPoDniach` (patrz komentarz klasy).
        config(['kuking.community.host_username' => 'woogitsu']);
        $gospodarz = $this->user('woogitsu');
        $przepis = Recipe::factory()->create(['author_id' => $gospodarz->getKey()]);
        CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $this->user('ktos')->getKey()]);
        Notification::create(['user_id' => $gospodarz->getKey(), 'type' => Notification::TYPE_COOKED, 'data' => []]);

        $wynik = app(ZasiegUgotowalem::class)->policz();

        $this->assertSame(1, $wynik['przepisy_z_ugotowalem']);
        $this->assertSame(1, $wynik['autorzy_powiadomieni']);
    }

    // ---------------------------------------------------------------
    // Komenda `kuking:raport`
    // ---------------------------------------------------------------

    public function test_pusta_baza_nie_wywala_komendy(): void
    {
        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Aktywni w ostatnich 7 dniach: 0')
            ->expectsOutputToContain('Powrót po 7 dniach (D7): brak kont sprzed co najmniej jednego tygodnia')
            ->expectsOutputToContain('Powrót po 30 dniach (D30): brak kont sprzed co najmniej jednego miesiąca')
            ->expectsOutputToContain('Przepisy z choć jednym „Ugotowałem”: 0')
            ->expectsOutputToContain('Autorzy powiadomieni o „Ugotowałem”: 0');
    }

    public function test_komenda_wypisuje_prawdziwe_liczby(): void
    {
        $this->travelTo($this->teraz());

        $this->user('aktywna', ['ostatnio_widziany_at' => $this->teraz()]);

        $autorka = $this->user('autorka', [
            'created_at' => $this->teraz()->subDays(40),
            'ostatnio_widziany_at' => $this->teraz(),
        ]);
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey()]);
        CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $this->user('kucharka')->getKey()]);
        Notification::create(['user_id' => $autorka->getKey(), 'type' => Notification::TYPE_COOKED, 'data' => []]);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Aktywni w ostatnich 7 dniach: 2')
            ->expectsOutputToContain('Przepisy z choć jednym „Ugotowałem”: 1')
            ->expectsOutputToContain('Autorzy powiadomieni o „Ugotowałem”: 1');
    }
}
