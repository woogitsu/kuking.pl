<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #793: nazwa użytkownika w adresie formularza blokady to NIE AUTORYZACJA.
 *
 * Username da się zwolnić (zmiana w Ustawieniach) i od razu ponownie zająć —
 * `UsernameNotTaken` sprawdza tylko aktualne zajęcie, nie historię. Stary,
 * wciąż otwarty formularz „Zablokuj”/„Zdejmij blokadę” pod
 * `/@stara-nazwa/blokuj` po takiej zmianie trafia w kogoś INNEGO, niż widział
 * człowiek, który formularz otworzył.
 */
class BlokadaPoZmianieNazwyUzytkownikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_formularz_blokady_nie_blokuje_nowego_wlasciciela_nazwy(): void
    {
        $basia = $this->user('basia');
        $pierwotny = $this->user('do_zablokowania');

        // Formularz „Zablokuj” wyrenderował się, gdy `do_zablokowania`
        // wskazywało na $pierwotny — stąd ukryte pole `oczekiwany_id`.
        $oczekiwanyId = $pierwotny->getKey();

        // Pierwotna osoba zwalnia nazwę, a inna ją od razu zajmuje —
        // dokładnie tak, jak zwykła zmiana nazwy w Ustawieniach.
        Profile::query()->where('user_id', $pierwotny->getKey())->update(['username' => 'juz_nie_ta_sama']);
        $nowyWlasciciel = $this->user('do_zablokowania');

        $odpowiedz = $this->actingAs($basia)->post(route('social.block', 'do_zablokowania'), [
            'oczekiwany_id' => $oczekiwanyId,
        ]);

        $odpowiedz->assertSessionHasErrors('block');

        $this->assertFalse($basia->fresh()->hasBlockRelationWith($nowyWlasciciel->fresh()));
        $this->assertFalse($basia->fresh()->hasBlockRelationWith($pierwotny->fresh()));
    }

    public function test_stary_formularz_odblokowania_nie_zdejmuje_blokady_nowemu_wlascicielowi_nazwy(): void
    {
        $basia = $this->user('basia');
        $zablokowany = $this->user('zajety_login');

        Block::query()->create([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $zablokowany->getKey(),
            'created_at' => now(),
        ]);

        $oczekiwanyId = $zablokowany->getKey();

        // Zablokowana osoba zmienia nazwę, a nowa osoba wchodzi na jej miejsce.
        Profile::query()->where('user_id', $zablokowany->getKey())->update(['username' => 'stara_nazwa_zablokowanego']);
        $nowyWlasciciel = $this->user('zajety_login');

        $odpowiedz = $this->actingAs($basia)->delete(route('social.unblock', 'zajety_login'), [
            'oczekiwany_id' => $oczekiwanyId,
        ]);

        $odpowiedz->assertSessionHasErrors('block');

        // Blokada pierwotnej osoby zostaje — formularz miał ją zdjąć, nie
        // trafić w kogoś, kto akurat zajął zwolnioną nazwę.
        $this->assertTrue($basia->fresh()->hasBlockRelationWith($zablokowany->fresh()));
        $this->assertFalse($basia->fresh()->hasBlockRelationWith($nowyWlasciciel->fresh()));
    }

    public function test_zadanie_bez_oczekiwanego_id_dziala_jak_dawniej(): void
    {
        // Wsteczna zgodność: starsze wywołania (API, istniejące testy) nie
        // wysyłają `oczekiwany_id` — Policy i sama akcja i tak bronią
        // działania, więc brak pola nie blokuje zwykłego, świeżego kliknięcia.
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->actingAs($basia)->post(route('social.block', 'marek'))->assertSessionDoesntHaveErrors();

        $this->assertTrue($basia->fresh()->hasBlockRelationWith($marek->fresh()));
    }
}
