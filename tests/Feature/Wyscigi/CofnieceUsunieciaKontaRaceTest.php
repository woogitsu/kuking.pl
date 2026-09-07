<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Domain\Users\Actions\EraseAccountData;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KANDYDAT (audyt zewnętrzny, grupa 3): `PurgeExpiredAccountDeletions`
 * (egzekutor karencji) kontra cofnięcie usunięcia konta w tej samej chwili.
 *
 * OBA KOŃCE JUŻ MAJĄ `lockForUpdate()` NA TYM SAMYM WIERSZU (widziane przy
 * czytaniu kodu: `EraseAccountData::handle()` i `CancelAccountDeletion::
 * handle()`) — to jest deklaracja, nie dowód. Ten test PODKŁADA STAN "jakby
 * drugie żądanie już przeszło" dla obu możliwych kolejności i sprawdza, że
 * WYNIK jest spójny w obu przypadkach, a nie że kod "wygląda bezpiecznie".
 *
 * PRZYPADEK 1 (kolejność, którą opisuje komentarz `PurgeExpiredAccountDeletions`):
 * egzekutor karencji wybrał konto do wykonania (ma REFERENCJĘ z sprzed chwili),
 * ale ZANIM zdążył wywołać `EraseAccountData::handle()`, człowiek kliknął
 * "cofnij usunięcie". `handle()` MUSI zobaczyć świeży stan pod blokadą i nic
 * nie zrobić — nie wolno mu wymazać konta, które w międzyczasie ożyło.
 *
 * PRZYPADEK 2 (odwrotna kolejność): dane zostały już wymazane, a dopiero
 * PO TYM człowiek (albo zdublowane kliknięcie "cofnij") próbuje cofnąć.
 * `CancelAccountDeletion` MUSI odmówić z jasnym komunikatem, a NIE ustawić
 * `status = active` na koncie bez e-maila i hasła — to by "cofnięcie" (żywe
 * konto) zamieniło w pustą powłokę, gorszą niż odmowa.
 */
class CofnieceUsunieciaKontaRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_egzekutor_karencji_nie_wymazuje_konta_cofnietego_tuz_przed_jego_uruchomieniem(): void
    {
        $user = $this->user('do_usuniecia');
        $user->markForDeletion();
        $user->forceFill(['delete_requested_at' => now()->subDays(31)])->save();

        // Egzekutor karencji (PurgeExpiredAccountDeletions::handle()) w tym
        // miejscu ma już GOTOWĄ listę kont do wykonania — czyli referencję
        // $user sprzed chwili, o statusie `pending_delete`.
        $referencjaEgzekutora = $user->fresh();

        // W MIĘDZYCZASIE człowiek klika "cofnij usunięcie" i to żądanie
        // wygrywa wyścig o blokadę jako pierwsze.
        app(CancelAccountDeletion::class)->handle($user->fresh());

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status, 'Cofnięcie musi się udać, skoro doszło do niego pierwsze.');

        // Egzekutor dopiero TERAZ dociera do tego konta ze swoją STARĄ
        // referencją (`status = pending_delete` w pamięci) i woła
        // `EraseAccountData::handle()`, dokładnie jak robi to
        // `PurgeExpiredAccountDeletions::handle()` w pętli.
        $wykonano = app(EraseAccountData::class)->handle($referencjaEgzekutora);

        $this->assertFalse($wykonano, '`handle()` musi rozpoznać pod blokadą, że konto już nie jest pending_delete, i nic nie zrobić.');

        $swiezy = $user->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $swiezy->status, 'Egzekutor nie miał prawa nic zmienić na koncie, które w międzyczasie ożyło.');
        $this->assertNull($swiezy->data_erased_at, 'Konto, które ożyło, nie ma prawa zostać oznaczone jako wymazane.');
        $this->assertNotNull($swiezy->profile, 'Profil nie mógł zostać zanonimizowany dla konta, które w międzyczasie ożyło.');
    }

    public function test_cofniecie_po_wymazaniu_odmawia_zamiast_wskrzeszac_pusta_powloke(): void
    {
        $user = $this->user('do_usuniecia_2');
        $user->markForDeletion();
        $user->forceFill(['delete_requested_at' => now()->subDays(31)])->save();

        // Egzekutor karencji dociera pierwszy i wymazuje dane.
        $wykonano = app(EraseAccountData::class)->handle($user->fresh());
        $this->assertTrue($wykonano);

        $referencjaFormularza = $user->fresh(); // ma już `data_erased_at` ustawione

        // Człowiek (albo zdublowane kliknięcie "cofnij", które akurat
        // przegrało wyścig o blokadę) próbuje cofnąć DOPIERO TERAZ.
        try {
            app(CancelAccountDeletion::class)->handle($referencjaFormularza);
            $this->fail('Cofnięcie po wymazaniu danych musi się nie udać.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('nie da się już odzyskać', $e->getMessage());
        }

        $swiezy = $user->fresh();
        $this->assertSame(User::STATUS_ERASED, $swiezy->status, '"Cofnięcie" nie miało prawa ustawić `active` na koncie bez e-maila i hasła.');
    }
}
