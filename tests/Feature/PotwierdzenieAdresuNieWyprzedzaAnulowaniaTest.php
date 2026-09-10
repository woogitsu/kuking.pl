<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\CancelEmailChange;
use App\Domain\Users\Actions\ConfirmEmailChange;
use App\Domain\Users\Actions\RequestEmailChange;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AUTH-01 / RACE-01 z audytu drugiej warstwy (10.09.2026).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CZEGO TE TESTY PILNUJĄ
 * ══════════════════════════════════════════════════════════════════════
 *
 * Jednej obietnicy, którą serwis składa w trzech miejscach:
 * **ustawienie nowego hasła unieważnia oczekującą zmianę adresu**. Wołają to
 * `PasswordResetController::reset()` i `SecuritySettingsController::
 * updatePassword()`, a `PendingEmailChange` wymienia jako jedną z trzech
 * dróg wygaszenia żądania.
 *
 * Ta obietnica ma znaczenie w jednym konkretnym scenariuszu: ktoś obcy miał
 * chwilowy dostęp do konta, zamówił zmianę adresu na swój, a właściciel
 * odzyskuje konto ustawiając nowe hasło. Jeśli link napastnika po tym nadal
 * działa, to mechanizm zaprojektowany na wypadek przejęcia konta daje się
 * przejęciu obejść.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TEST NIE IDZIE PRZEZ HTTP — I DLACZEGO TO NIE JEST WYGODNICTWO
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo przez HTTP TEN BŁĄD SIĘ NIE POKAZUJE, a test, który go nie pokazuje,
 * nie jest testem tego błędu. `EmailSettingsController::confirm()` czyta
 * wiersz świeżo przy każdym żądaniu, więc w teście sekwencyjnym po
 * anulowaniu po prostu nic nie znajdzie i odpowie ładnym komunikatem.
 *
 * Błąd żył DOKŁADNIE w okienku między odczytem w kontrolerze a transakcją
 * w `ConfirmEmailChange::handle()`. Akcja dostawała MODEL i nigdy nie
 * czytała wiersza ponownie: przypisywała nowy adres, a `$zmiana->delete()`
 * kasowało zero wierszy — bez błędu. Te testy odtwarzają to okienko
 * deterministycznie: pobierają model, wykonują anulowanie, a POTEM wołają
 * akcję ze starym modelem. To jest ten sam przeplot, tylko wymuszony
 * ręcznie, a nie wyproszony od dwóch połączeń i zegara.
 *
 * CZEGO TE TESTY NIE DOWODZĄ, ŻEBY NIE BYŁO NIEPOROZUMIENIA: nie dowodzą
 * poprawnej KOLEJNOŚCI blokad przy dwóch równoległych połączeniach do
 * PostgreSQL — do tego trzeba dwóch procesów i wymuszonego przeplotu na
 * poziomie bazy (audyt 20 słusznie tego wymaga jako osobnego kryterium).
 * Dowodzą rzeczy węższej i akurat tej, która była złamana: że akcja NIE
 * UFA modelowi podanemu z zewnątrz i sprawdza stan jeszcze raz, już pod
 * blokadą. Kolejność blokad jest osobno opisana i osadzona w jednym
 * miejscu — `App\Domain\Users\ZamekKonta`.
 */
class PotwierdzenieAdresuNieWyprzedzaAnulowaniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Żądanie zmiany adresu wysyła dwa listy; tu nie o nie chodzi.
        Notification::fake();
    }

    private function basia(): User
    {
        return $this->user('basia', ['email' => 'basia@example.test']);
    }

    private function zamow(User $user, string $nowy): PendingEmailChange
    {
        return app(RequestEmailChange::class)->handle($user, $nowy);
    }

    #[Test]
    public function test_potwierdzenie_starym_modelem_nie_przechodzi_po_ustawieniu_nowego_hasla(): void
    {
        $basia = $this->basia();

        // 1. Napastnik zamawia zmianę adresu na swój.
        $zmiana = $this->zamow($basia, 'napastnik@example.test');

        // 2. Właściciel odzyskuje konto: ustawia nowe hasło, co ma unieważnić
        //    oczekującą zmianę. Wołamy dokładnie tę akcję, którą woła
        //    `PasswordResetController::reset()`.
        app(CancelEmailChange::class)->handle($basia, CancelEmailChange::POWOD_RESET_HASLA);

        // 3. Napastnik klika swój link. Model ma w rękach ten sam, co przed
        //    anulowaniem — to jest odtworzone okienko wyścigu.
        try {
            app(ConfirmEmailChange::class)->handle($basia->fresh(), $zmiana);
            $this->fail('Potwierdzenie przeszło po anulowaniu — obietnica „nowe hasło unieważnia zmianę adresu" jest złamana.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('nie działa', $e->getMessage());
        }

        $this->assertSame(
            'basia@example.test',
            $basia->fresh()->email,
            'Adres konta ZMIENIŁ SIĘ mimo anulowania — to jest przejęcie konta.',
        );
    }

    #[Test]
    public function test_potwierdzenie_starym_modelem_nie_przechodzi_po_recznym_anulowaniu(): void
    {
        $basia = $this->basia();
        $zmiana = $this->zamow($basia, 'nowa.basia@example.test');

        app(CancelEmailChange::class)->handle($basia, CancelEmailChange::POWOD_RECZNIE);

        $this->expectException(BladDlaCzlowieka::class);

        try {
            app(ConfirmEmailChange::class)->handle($basia->fresh(), $zmiana);
        } finally {
            $this->assertSame('basia@example.test', $basia->fresh()->email);
        }
    }

    #[Test]
    public function test_stary_link_nie_dziala_po_zamowieniu_nowszej_zmiany(): void
    {
        $basia = $this->basia();

        $pierwsza = $this->zamow($basia, 'pierwsza@example.test');
        // Nowsze zamówienie kasuje poprzedni wiersz i tworzy własny.
        $this->zamow($basia, 'druga@example.test');

        try {
            app(ConfirmEmailChange::class)->handle($basia->fresh(), $pierwsza);
            $this->fail('Stary link przeszedł po zamówieniu nowszej zmiany.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('nie działa', $e->getMessage());
        }

        $this->assertSame('basia@example.test', $basia->fresh()->email);
    }

    /**
     * KONTROLA DODATNIA. Bez niej trzy testy wyżej przeszłyby także wtedy,
     * gdyby potwierdzanie adresu było zepsute NA ZAWSZE i nie działało
     * nigdy — bo one wszystkie sprawdzają, że adres się NIE zmienił.
     */
    #[Test]
    public function test_normalna_droga_nadal_zmienia_adres(): void
    {
        $basia = $this->basia();
        $zmiana = $this->zamow($basia, 'nowa.basia@example.test');

        $nowy = app(ConfirmEmailChange::class)->handle($basia, $zmiana);

        $this->assertSame('nowa.basia@example.test', $nowy);
        $this->assertSame('nowa.basia@example.test', $basia->fresh()->email);
        $this->assertSame(0, PendingEmailChange::count(), 'Żądanie ma zniknąć po potwierdzeniu.');
    }
}
