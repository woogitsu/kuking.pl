<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie zabiera po cichu wejścia na konto (issue #258, D-069).
 *
 * DLACZEGO TA MIGRACJA JEST INNA NIŻ TA OD LINKU E-MAIL
 * `login_link_tokens` wolno skasować bez pytania: traci się linki W DRODZE,
 * a każdy taki człowiek ma hasło. Tutaj jest odwrotnie: konto założone drogą
 * Google **nigdy nie miało hasła** (w kolumnie leży skrót wartości losowej,
 * której nikt nie zna). Skasowanie `google_sub` zabiera tej osobie jedyną
 * drogę wejścia, jaką zna — zostaje jej odzyskiwanie hasła i wiadomość
 * z linkiem, czyli dwie rzeczy, których nie umie połowa naszej grupy
 * (`docs/research/AUDIENCE_50_PLUS.md`).
 *
 * `php artisan migrate:rollback` wpisuje się odruchowo, zwykle w pośpiechu
 * i zwykle wtedy, gdy coś już poszło nie tak. Jedyne ostrzeżenie w komentarzu
 * pliku, którego w takiej chwili nikt nie otwiera, nie jest ostrzeżeniem.
 *
 * Ten test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która
 * nie cofa się nigdy, blokowałaby staging i lokalne bazy bez powodu.
 */
class CofniecieMigracjiGoogleOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ZGODA], $_ENV[self::ZGODA]);
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_10_500000_add_google_account_to_users.php',
        );
    }

    private function kolumnaIstnieje(string $kolumna): bool
    {
        return DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['users', $kolumna],
        ) !== [];
    }

    public function test_cofniecie_odmawia_gdy_ktos_wchodzi_kontem_google(): void
    {
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');

        try {
            $this->migracja()->down();

            $this->fail('Cofnięcie przeszło i skasowało powiązanie z kontem Google.');
        } catch (RuntimeException $e) {
            // Komunikat ma powiedzieć, ILU osób to dotyczy, CO ZROBIĆ ZAMIAST
            // TEGO i jak powiedzieć wprost „wiem, co robię". „Ktoś coś
            // straci" nie zatrzymuje nikogo o drugiej w nocy.
            $this->assertStringContainsString('1 kont', $e->getMessage());
            $this->assertStringContainsString('KUKING_WEJSCIE_GOOGLE=false', $e->getMessage());
            $this->assertStringContainsString(self::ZGODA, $e->getMessage());
        }

        // NAJWAŻNIEJSZE: powiązanie nadal jest. Odmowa, która i tak zdążyła
        // skasować dane, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertSame('109876543210987654321', $basia->refresh()->google_sub);
        $this->assertTrue($this->kolumnaIstnieje('google_sub'));
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma czego stracić, więc nie ma o co pytać.
        $this->migracja()->down();

        $this->assertFalse($this->kolumnaIstnieje('google_sub'));
        $this->assertFalse($this->kolumnaIstnieje('google_connected_at'));

        // Migrujemy z powrotem, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        $this->migracja()->up();

        $this->assertTrue($this->kolumnaIstnieje('google_sub'));
    }

    public function test_cofniecie_przechodzi_gdy_wlasciciel_powie_to_wprost(): void
    {
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');

        $_SERVER[self::ZGODA] = 'true';

        $this->migracja()->down();

        $this->assertFalse($this->kolumnaIstnieje('google_sub'),
            'Świadoma zgoda ma przepuszczać cofnięcie — inaczej migracja jest nie do cofnięcia nigdy.');

        $this->migracja()->up();
    }

    public function test_migracja_sprawdza_czy_wolno_zanim_cokolwiek_skasuje(): void
    {
        // Kolejność w `down()` ma znaczenie: gdyby `dropColumn` stał przed
        // sprawdzeniem, wyjątek leciałby już po utracie danych. Czytamy kod,
        // bo w działaniu tej różnicy nie widać — w obu wersjach leci wyjątek.
        $kod = (string) file_get_contents(database_path(
            'migrations/2026_09_10_500000_add_google_account_to_users.php',
        ));

        $sprawdzenie = strpos($kod, '$this->ilePowiazanych()');
        $kasowanie = strpos($kod, "dropColumn(['google_sub'");

        $this->assertNotFalse($sprawdzenie);
        $this->assertNotFalse($kasowanie);
        $this->assertLessThan($kasowanie, $sprawdzenie,
            'Sprawdzenie „czy wolno" musi stać PRZED kasowaniem kolumn.');
    }
}
