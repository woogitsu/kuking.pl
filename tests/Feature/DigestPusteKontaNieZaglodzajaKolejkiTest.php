<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * #2237 (audyt BP-01): osoby bez treści nie dostają znacznika wysyłki, więc
 * codziennie stoją na początku kolejki. Gdy było ich co najmniej
 * 3 × budżet, zajmowały całe okno kandydatów i list nie dochodził do nikogo
 * młodszego. Komenda dobiera teraz kolejne partie (stronicowanie kluczem),
 * aż wyczerpie budżet albo kolejkę.
 */
class DigestPusteKontaNieZaglodzajaKolejkiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.digest.wlaczony', true);
        config()->set('kuking.digest.odstep_dni', 7);
        config()->set('kuking.digest.okno_dni', 7);
        config()->set('kuking.digest.odstep_sekund', 0);
    }

    private function ciche(int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $this->user("cisza_{$i}")->forceFill(['created_at' => now()->subYear()->addMinutes($i)])->save();
        }
    }

    private function zTrescia(string $nazwa): User
    {
        $autor = $this->user($nazwa);
        $kucharz = $this->user("kucharz_{$nazwa}", ['wants_weekly_digest' => false]);
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create(['cooked_at' => now()->subDay()]);

        return $autor;
    }

    public function test_osoby_bez_tresci_nie_blokuja_na_zawsze_osob_z_trescia(): void
    {
        // Trzy starsze konta ze zgodą, którym w tygodniu nic się nie wydarzyło.
        $this->ciche(3);
        $autor = $this->zTrescia('autor_z_trescia');

        Mail::fake();

        // Trzy kolejne dni z budżetem jednego listu — autor powinien go dostać.
        $start = now()->startOfDay();
        foreach ([0, 1, 2] as $dzien) {
            $this->travelTo($start->copy()->addDays($dzien)->setTime(8, 30));
            Artisan::call('kuking:wyslij-podsumowania', ['--limit' => 1]);
        }

        Mail::assertQueued(PodsumowanieTygodnia::class, fn (PodsumowanieTygodnia $l) => $l->hasTo($autor->email));
    }

    public function test_budzet_schodzi_do_konca_przez_kilka_partii_bez_przeskakiwania_nikogo(): void
    {
        // Budżet 2 → partia 6. Siedem cichych kont zajmuje pierwszą partię
        // i początek drugiej; trzy osoby z treścią stoją dalej. Dwie pierwsze
        // mają dostać list DZIŚ, trzecia zostaje na jutro (budżet).
        $this->ciche(7);
        $pierwsza = $this->zTrescia('pierwsza');
        $druga = $this->zTrescia('druga');
        $trzecia = $this->zTrescia('trzecia');

        Mail::fake();

        $this->artisan('kuking:wyslij-podsumowania', ['--limit' => 2])
            ->expectsOutputToContain('Wysłano: 2. Pominięto bez treści: 7.')
            ->assertSuccessful();

        Mail::assertQueued(PodsumowanieTygodnia::class, 2);
        Mail::assertQueued(PodsumowanieTygodnia::class, fn (PodsumowanieTygodnia $l) => $l->hasTo($pierwsza->email));
        Mail::assertQueued(PodsumowanieTygodnia::class, fn (PodsumowanieTygodnia $l) => $l->hasTo($druga->email));
        Mail::assertNotQueued(PodsumowanieTygodnia::class, fn (PodsumowanieTygodnia $l) => $l->hasTo($trzecia->email));
    }

    public function test_kolejka_samych_osob_bez_tresci_nie_ostrzega_o_braku_miejsca(): void
    {
        // Wszyscy sprawdzeni, nikt nie ma treści: `ileCzeka()` zwraca 4,
        // ale nikt nie „czeka na miejsce w limicie" — ostrzeżenie o planie
        // płatnym byłoby fałszywym alarmem.
        $this->ciche(4);

        Mail::fake();

        $this->artisan('kuking:wyslij-podsumowania', ['--limit' => 1])
            ->expectsOutputToContain('Pominięto bez treści: 4.')
            ->doesntExpectOutputToContain('W kolejce czeka jeszcze')
            ->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_osoby_niesprawdzone_z_powodu_budzetu_sa_w_ostrzezeniu(): void
    {
        // Kontrola dodatnia poprzedniego testu: gdy budżet skończył się,
        // zanim przebieg doszedł do reszty kolejki, ostrzeżenie JEST.
        $this->zTrescia('pierwsza');
        $this->zTrescia('druga');
        $this->zTrescia('trzecia');

        Mail::fake();

        $this->artisan('kuking:wyslij-podsumowania', ['--limit' => 1])
            ->expectsOutputToContain('W kolejce czeka jeszcze 2')
            ->assertSuccessful();
    }
}
