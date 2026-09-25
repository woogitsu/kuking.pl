<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Users\Actions\RequestEmailChange;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NOWE HASŁO A POTWIERDZENIE ZAMÓWIONEJ ZMIANY ADRESU NA DWÓCH
 * POŁĄCZENIACH (issue #1358).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PRZEPLOT, KTÓRY TO ROZSTRZYGA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Proces A ustawia nowe hasło prawdziwym kontrolerem (zmiana w ustawieniach
 * albo reset linkiem). Bariera przyrządu zatrzymuje go ZARAZ PO zapisie
 * `users.password`. W tym oknie proces B potwierdza zamówioną wcześniej
 * zmianę adresu — link napastnika.
 *
 *   dziś (jedna transakcja pod ZamekKonta)  stary kod (hasło przed blokadą)
 *   ─────────────────────────────────────   ────────────────────────────────
 *   A: blokada konta, zapis hasła, stoi     A: zapis hasła (zatwierdzony), stoi
 *   B: czeka na blokadę konta               B: blokada wolna — zmienia adres,
 *   zwolnienie bariery:                        konsumuje żądanie, zatwierdza
 *   A kasuje żądanie i zatwierdza           zwolnienie bariery:
 *   B pod blokadą nie znajduje żądania      A: `CancelEmailChange` nie ma już
 *   → adres bez zmian                          czego kasować
 *                                           → konto z adresem napastnika
 *
 * KONTROLE DODATNIE: A naprawdę zmienił hasło; B naprawdę dotarł do
 * rewalidacji pod blokadą (odmowa `BladDlaCzlowieka`, nie błąd przyrządu);
 * a osobny test pokazuje, że samo potwierdzenie tym samym przyrządem
 * zmienia adres — czyli „adres bez zmian" nie bierze się z zepsutego
 * scenariusza.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Potwierdzenie zakończone CAŁE przed wejściem A pod blokadę jest
 * wcześniejszym, zamkniętym zdarzeniem i nowe hasło go nie cofa. Tu mierzymy
 * okno po zapisie hasła — to, które zostawiał stary kod.
 */
#[Group('dwa-polaczenia')]
final class HasloPodBlokadaKontaTest extends TestDwochPolaczen
{
    private const NOWE_HASLO = 'zupelnienowehaslo789';

    /** @var list<string> */
    private array $adresyZTokenem = [];

    protected function tearDown(): void
    {
        if ($this->adresyZTokenem !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $this->adresyZTokenem)->delete();
            $this->adresyZTokenem = [];
        }

        parent::tearDown();
    }

    /** @return array{0: User, 1: PendingEmailChange, 2: string} */
    private function kontoZZamowionaZmiana(): array
    {
        Notification::fake();

        $konto = $this->konto();
        $napastnik = 'n'.bin2hex(random_bytes(6)).'@example.test';
        $zmiana = app(RequestEmailChange::class)->handle($konto, $napastnik);

        return [$konto->fresh(), $zmiana, (string) $konto->email];
    }

    /** @return array<string, array{0: string}> */
    public static function drogi(): array
    {
        return ['zmiana w ustawieniach' => ['zmiana'], 'reset linkiem' => ['reset']];
    }

    #[DataProvider('drogi')]
    public function test_potwierdzenie_adresu_nie_wchodzi_miedzy_zapis_hasla_a_anulowanie(string $droga): void
    {
        [$konto, $zmiana, $staryAdres] = $this->kontoZZamowionaZmiana();

        $argumenty = ['konto' => (string) $konto->getKey(), 'droga' => $droga, 'haslo' => self::NOWE_HASLO];

        if ($droga === 'zmiana') {
            $argumenty['obecne'] = 'haslo-testowe-123';
        } else {
            $argumenty['token'] = Password::createToken($konto);
            $argumenty['email'] = $staryAdres;
            $this->adresyZTokenem[] = $staryAdres;
        }

        // Bariera: blokada doradcza, na którą A staje zaraz po zapisie hasła.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1358, 1)', []);

        $haslo = $this->wTle('ustaw-haslo', $argumenty);
        $this->czekajNaZablokowane(1);

        $potwierdzenie = $this->wTle('potwierdz-adres', [
            'konto' => (string) $konto->getKey(),
            'zmiana' => (string) $zmiana->getKey(),
        ]);
        $this->czekajAzSkonczyAlboStanie($potwierdzenie);

        $this->zwolnijBariere($bariera);

        $wynikHasla = $haslo->wynik();
        $wynikPotwierdzenia = $potwierdzenie->wynik();

        $this->assertBezZakleszczenia($wynikHasla, 'ustawienie hasła');
        $this->assertBezZakleszczenia($wynikPotwierdzenia, 'potwierdzenie adresu');

        // Kontrola dodatnia: A doszedł do końca i hasło naprawdę się zmieniło.
        $this->assertTrue($wynikHasla['ok'], 'Ustawienie hasła padło: '.$wynikHasla['komunikat']);
        $this->assertSame([], $wynikHasla['wartosc']['bledy'] ?? null, 'Kontroler odmówił ustawienia hasła.');
        $this->assertTrue(Hash::check(self::NOWE_HASLO, (string) $konto->fresh()?->password));

        // SEDNO: po odzyskaniu konta adres jest właściciela.
        $this->assertSame(
            $staryAdres,
            $konto->fresh()?->email,
            'Link napastnika zmienił adres w oknie między zapisem hasła a anulowaniem zmiany adresu.',
        );
        $this->assertSame(0, PendingEmailChange::query()->where('user_id', $konto->getKey())->count());

        // Kontrola dodatnia: B naprawdę dotarł do rewalidacji pod blokadą.
        $this->assertFalse($wynikPotwierdzenia['ok']);
        $this->assertSame(BladDlaCzlowieka::class, $wynikPotwierdzenia['wyjatek'], $wynikPotwierdzenia['komunikat']);
        $this->assertStringContainsString('nie działa', $wynikPotwierdzenia['komunikat']);
    }

    public function test_samo_potwierdzenie_tym_przyrzadem_zmienia_adres(): void
    {
        [$konto, $zmiana] = $this->kontoZZamowionaZmiana();

        $wynik = $this->wTle('potwierdz-adres', [
            'konto' => (string) $konto->getKey(),
            'zmiana' => (string) $zmiana->getKey(),
        ])->wynik();

        $this->assertTrue($wynik['ok'], 'Potwierdzenie padło: '.$wynik['komunikat']);
        $this->assertSame(User::normalizeEmail($zmiana->new_email), $konto->fresh()?->email);
    }

    /**
     * Stary kod pozwala B skończyć od razu, nowy trzyma go w kolejce za A.
     * Czekamy na jedno albo drugie, żeby test na starym kodzie oblał się na
     * asercji o adresie, a nie na „przeplot się nie ustawił".
     */
    private function czekajAzSkonczyAlboStanie(ProcesRownolegly $proces): void
    {
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;

        while (microtime(true) < $koniec) {
            if (! $proces->trwa()) {
                return;
            }

            $czekajacych = $this->obserwator->query(
                "SELECT count(*) FROM pg_stat_activity
                 WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'",
            );

            if ($czekajacych !== false && (int) $czekajacych->fetchColumn() >= 2) {
                return;
            }

            usleep(20_000);
        }

        $this->fail('Potwierdzenie adresu ani nie skończyło się, ani nie stanęło w kolejce po blokadę.');
    }
}
