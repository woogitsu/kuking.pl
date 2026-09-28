<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\DrugiWpisW7Dni;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Drugi wpis w 7 dni” w `kuking:raport` (issue #29): kohorta pierwszych
 * wpisów, licznik, procent od progu próby — i nic poza liczbami.
 */
class RaportDrugiegoWpisuTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-25 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC'));
    }

    private function teraz(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TERAZ, 'UTC');
    }

    /** @param  list<CarbonImmutable>  $kiedy */
    private function autorZWpisami(array $kiedy, array $atrybuty = []): User
    {
        $autor = $this->user(null, $atrybuty);
        foreach ($kiedy as $chwila) {
            Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => $chwila]);
        }

        return $autor;
    }

    /** @return array{kohorta: int, z_drugim: int, procent: float|null} */
    private function wynik(): array
    {
        return app(DrugiWpisW7Dni::class)->policz();
    }

    public function test_drugi_wpis_w_siedmiu_dniach_liczy_sie_a_osmego_dnia_nie(): void
    {
        $pierwszy = $this->teraz()->subDays(20);
        $this->autorZWpisami([$pierwszy, $pierwszy->addDays(3)]);
        // Granica: dokładnie 7×24 h — jeszcze w oknie.
        $this->autorZWpisami([$pierwszy, $pierwszy->addDays(7)]);
        // KONTROLA UJEMNA: 7 dni i minuta po pierwszym.
        $this->autorZWpisami([$pierwszy, $pierwszy->addDays(7)->addMinute()]);
        $this->autorZWpisami([$pierwszy]);

        $w = $this->wynik();

        $this->assertSame(4, $w['kohorta']);
        $this->assertSame(2, $w['z_drugim']);
    }

    public function test_za_mlody_pierwszy_wpis_nie_wchodzi_do_kohorty(): void
    {
        // Pierwszy wpis 3 dni temu: jeszcze nie miał szansy na drugi.
        $this->autorZWpisami([$this->teraz()->subDays(3)]);
        // Pierwszy wpis sprzed okna kohorty.
        $stary = $this->teraz()->subDays(DrugiWpisW7Dni::KOHORTA_DNI + 1);
        $this->autorZWpisami([$stary, $stary->addDay()]);
        // Autor z pierwszym wpisem w oknie, drugim starym — liczy się PIERWSZY.
        $this->autorZWpisami([$this->teraz()->subDays(30), $this->teraz()->subDays(29)]);

        $w = $this->wynik();

        $this->assertSame(1, $w['kohorta']);
        $this->assertSame(1, $w['z_drugim']);
    }

    public function test_szkic_ukryty_i_usuniety_nie_sa_drugim_wpisem(): void
    {
        $pierwszy = $this->teraz()->subDays(20);
        $autor = $this->autorZWpisami([$pierwszy]);
        Post::factory()->draft()->create(['author_id' => $autor->getKey()]);
        Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => $pierwszy->addDay(), 'status' => Post::STATUS_HIDDEN]);
        Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => $pierwszy->addDays(2)])->delete();

        $w = $this->wynik();

        $this->assertSame(1, $w['kohorta']);
        $this->assertSame(0, $w['z_drugim']);
    }

    public function test_dwa_wpisy_w_tej_samej_chwili_to_pierwszy_i_drugi(): void
    {
        $chwila = $this->teraz()->subDays(20);
        $this->autorZWpisami([$chwila, $chwila]);

        $this->assertSame(1, $this->wynik()['z_drugim']);
    }

    public function test_wykluczone_konta_nie_licza_sie(): void
    {
        $pierwszy = $this->teraz()->subDays(20);
        $this->autorZWpisami([$pierwszy, $pierwszy->addDay()], ['is_seeded' => true]);
        $this->autorZWpisami([$pierwszy, $pierwszy->addDay()], ['status' => User::STATUS_BANNED]);

        $this->assertSame(0, $this->wynik()['kohorta']);
    }

    public function test_ponizej_progu_proby_procent_jest_pusty_a_od_progu_liczony(): void
    {
        $pierwszy = $this->teraz()->subDays(20);
        for ($i = 0; $i < DrugiWpisW7Dni::MINIMUM_OSOB - 1; $i++) {
            $this->autorZWpisami([$pierwszy, $pierwszy->addDay()]);
        }

        $this->assertNull($this->wynik()['procent']);

        $this->autorZWpisami([$pierwszy]);
        $w = $this->wynik();

        $this->assertSame(DrugiWpisW7Dni::MINIMUM_OSOB, $w['kohorta']);
        $this->assertSame(90.0, $w['procent']);
    }

    public function test_raport_pokazuje_liczby_bez_tresci_i_bez_nazw(): void
    {
        $pierwszy = $this->teraz()->subDays(20);
        $autor = $this->user('tajna_babcia');
        Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => $pierwszy, 'body' => 'Pierogi cioci Zosi']);
        Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => $pierwszy->addDay()]);

        $this->artisan('kuking:raport')
            // Jedna linia raportu — oczekiwania konsumują linie wyjścia.
            ->expectsOutputToContain('pominięte): 1 z 1 — za mało danych na procent')
            ->doesntExpectOutputToContain('tajna_babcia')
            ->doesntExpectOutputToContain('Pierogi')
            ->assertSuccessful();
    }

    public function test_pusta_kohorta_nie_wywraca_raportu(): void
    {
        $this->artisan('kuking:raport')
            ->expectsOutputToContain('Drugi wpis w 7 dni')
            ->assertSuccessful();
    }
}
