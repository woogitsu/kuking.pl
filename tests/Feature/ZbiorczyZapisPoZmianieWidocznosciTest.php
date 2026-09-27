<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Przegląd PR #1213 (D-070): partia zapisów trzyma `actor_id` pierwszej
 * osoby, a lista powiadomień wycinała wiersz, gdy TA osoba była dla autora
 * niewidoczna. Blokada albo ban ustawione PO zapisie zabierały więc całe
 * „A oraz 2 inne osoby…”, a każdy kolejny zapis dopisywał się do ukrytego
 * wiersza — autor nie dowiadywał się o nikim więcej.
 *
 * Blokady i bany są tu zawsze ustawiane PO zapisach — dokładnie ten
 * przeplot był usterką. Zablokowanie przed zapisem pilnuje osobny test
 * (`ZbiorczyZapisTrzymaGranicePowiadomienTest`).
 */
class ZbiorczyZapisPoZmianieWidocznosciTest extends TestCase
{
    use RefreshDatabase;

    public function test_blokada_pierwszej_osoby_po_zapisie_nie_ukrywa_reszty_partii_ani_nowych_osob(): void
    {
        $autor = $this->user('wid_autor_1');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $anna = $this->user('wid_anna', ['display_name' => 'Anna']);
        $bogdan = $this->user('wid_bogdan', ['display_name' => 'Bogdan']);
        $celina = $this->user('wid_celina', ['display_name' => 'Celina']);

        $this->zapisz($anna, $przepis);
        $this->zapisz($bogdan, $przepis);
        $this->zapisz($celina, $przepis);

        app(BlockUser::class)->handle($autor, $anna);

        $partia = $this->widocznaPartia($autor);
        $this->assertSame('Bogdan oraz 2 inne osoby zapisały Twój przepis', $partia->naglowekZapisu());
        $this->assertSame(1, $autor->fresh()->unreadNotificationsCount());

        $dorota = $this->user('wid_dorota', ['display_name' => 'Dorota']);
        $this->zapisz($dorota, $przepis);

        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->count());
        $partia = $this->widocznaPartia($autor);
        $this->assertSame(
            [$anna->getKey(), $bogdan->getKey(), $celina->getKey(), $dorota->getKey()],
            $partia->data['savers'],
        );
        $this->assertSame('Bogdan oraz 3 inne osoby zapisały Twój przepis', $partia->naglowekZapisu());

        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Bogdan oraz 3 inne osoby zapisały Twój przepis')
            ->assertDontSee('Anna');
    }

    public function test_blokada_ustawiona_przez_pierwsza_osobe_po_zapisie_tez_nie_ukrywa_reszty(): void
    {
        $autor = $this->user('wid_autor_2');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $anna = $this->user('wid_anna_2', ['display_name' => 'Anna']);
        $bogdan = $this->user('wid_bogdan_2', ['display_name' => 'Bogdan']);

        $this->zapisz($anna, $przepis);
        $this->zapisz($bogdan, $przepis);

        app(BlockUser::class)->handle($anna, $autor);

        $this->assertSame('Bogdan oraz 1 inna osoba zapisała Twój przepis', $this->widocznaPartia($autor)->naglowekZapisu());
    }

    public function test_ban_pierwszej_osoby_po_zapisie_nie_ukrywa_reszty_partii_ani_nowych_osob(): void
    {
        $autor = $this->user('wid_autor_3');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $anna = $this->user('wid_anna_3', ['display_name' => 'Anna']);
        $bogdan = $this->user('wid_bogdan_3', ['display_name' => 'Bogdan']);

        $this->zapisz($anna, $przepis);
        $this->zapisz($bogdan, $przepis);

        $anna->ban();
        $this->assertSame(User::STATUS_BANNED, $anna->fresh()->status, 'Kontrola danych: ban naprawdę założony.');

        $this->assertSame('Bogdan oraz 1 inna osoba zapisała Twój przepis', $this->widocznaPartia($autor)->naglowekZapisu());

        $celina = $this->user('wid_celina_3', ['display_name' => 'Celina']);
        $this->zapisz($celina, $przepis);

        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->count());
        $this->assertSame('Bogdan oraz 2 inne osoby zapisały Twój przepis', $this->widocznaPartia($autor)->naglowekZapisu());
    }

    public function test_partia_z_sama_ukryta_osoba_znika_a_nowa_osoba_ja_przywraca(): void
    {
        $autor = $this->user('wid_autor_4');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $anna = $this->user('wid_anna_4', ['display_name' => 'Anna']);

        $this->zapisz($anna, $przepis);
        app(BlockUser::class)->handle($autor, $anna);

        // Jedyna osoba niewidoczna — jak pojedyncze powiadomienie od
        // zablokowanej osoby: nie ma go na liście ani w liczniku.
        $this->assertSame(0, $autor->notifications()->visibleTo($autor)->count());
        $this->assertSame(0, $autor->fresh()->unreadNotificationsCount());

        $bogdan = $this->user('wid_bogdan_4', ['display_name' => 'Bogdan']);
        $this->zapisz($bogdan, $przepis);

        // Kontrola dodatnia na sam rdzeń usterki: nowa osoba trafiła do
        // TEGO SAMEGO wiersza, który przed poprawką zostawał ukryty.
        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->count());
        $this->assertSame(1, $autor->fresh()->unreadNotificationsCount());
        $this->assertSame('Bogdan oraz 1 inna osoba zapisała Twój przepis', $this->widocznaPartia($autor)->naglowekZapisu());
    }

    public function test_odblokowanie_przywraca_imie_pierwszej_osoby(): void
    {
        $autor = $this->user('wid_autor_5');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $anna = $this->user('wid_anna_5', ['display_name' => 'Anna']);
        $bogdan = $this->user('wid_bogdan_5', ['display_name' => 'Bogdan']);

        $this->zapisz($anna, $przepis);
        $this->zapisz($bogdan, $przepis);
        app(BlockUser::class)->handle($autor, $anna);
        $this->assertSame('Bogdan oraz 1 inna osoba zapisała Twój przepis', $this->widocznaPartia($autor)->naglowekZapisu());

        DB::table('blocks')->where('blocker_id', $autor->getKey())->delete();

        $this->assertSame('Anna oraz 1 inna osoba zapisała Twój przepis', $this->widocznaPartia($autor)->naglowekZapisu());
    }

    public function test_dolaczenie_do_partii_przesuwa_created_at(): void
    {
        $autor = $this->user('wid_autor_6');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->travelTo(now()->subDays(10));
        $this->zapisz($this->user('wid_stara'), $przepis);
        $this->travelBack();

        $przed = $this->widocznaPartia($autor)->created_at;
        $this->zapisz($this->user('wid_nowa'), $przepis);
        $po = $this->widocznaPartia($autor)->created_at;

        $this->assertTrue($przed->lt(now()->subDays(9)), 'Kontrola danych: partia naprawdę sprzed 10 dni.');
        $this->assertTrue($po->gt(now()->subMinute()), 'Dołączenie nowej osoby ma liczyć wiek partii od teraz.');
    }

    public function test_naglowek_partii_kosztuje_tyle_samo_zapytan_niezaleznie_od_jej_wielkosci(): void
    {
        $mala = $this->zapytaniaNaglowka(3);
        $duza = $this->zapytaniaNaglowka(40);

        // Kontrola dodatnia: naprawdę pytamy bazę — inaczej „tyle samo”
        // znaczyłoby tylko „zero i zero”.
        $this->assertGreaterThan(0, $mala);
        $this->assertSame($mala, $duza, 'Liczba zapytań rośnie z wielkością partii (N+1).');
        // Jedno zapytanie o pierwszą widoczną osobę + jedno o jej profil
        // (imię). Przed poprawką: jedno o wszystkich + jedno o blokadę
        // na KAŻDĄ osobę, dwa razy (nagłówek i reszta zdania osobno).
        $this->assertLessThanOrEqual(2, $duza, 'Nagłówek partii ma kosztować stałą, małą liczbę zapytań.');
    }

    /** Ile zapytań kosztuje nagłówek + reszta zdania partii o $ile osobach, z których co trzecia jest zablokowana. */
    private function zapytaniaNaglowka(int $ile): int
    {
        $autor = $this->user('wid_autor_n'.$ile);
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        for ($i = 0; $i < $ile; $i++) {
            $this->zapisz($this->user("wid_n{$ile}_{$i}"), $przepis);
        }

        $partia = $this->widocznaPartia($autor);
        $this->assertCount($ile, $partia->data['savers'], 'Kontrola danych: partia ma zadaną wielkość.');

        foreach ($partia->data['savers'] as $numer => $id) {
            if ($numer % 3 === 0) {
                app(BlockUser::class)->handle($autor, User::query()->findOrFail($id));
            }
        }

        $partia = $this->widocznaPartia($autor);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $partia->naglowekZapisu();
        $partia->resztaZapisu();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function zapisz(User $kto, Recipe $przepis): void
    {
        app(SaveRecipeToCollection::class)->handle($kto, $przepis);
    }

    private function widocznaPartia(User $autor): Notification
    {
        return $autor->notifications()
            ->visibleTo($autor)
            ->where('type', Notification::TYPE_SAVED)
            ->sole();
    }
}
