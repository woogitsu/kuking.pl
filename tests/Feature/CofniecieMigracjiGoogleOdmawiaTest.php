<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie zabiera po cichu wejścia na konto (issue #258, D-069).
 *
 * DLACZEGO TA MIGRACJA JEST INNA NIŻ TA OD LINKU E-MAIL
 * `login_link_tokens` wolno skasować bez pytania: traci się linki W DRODZE,
 * a każdy taki człowiek ma hasło. Tutaj jest odwrotnie: konto założone drogą
 * Google **nigdy nie miało hasła** (w kolumnie leży skrót wartości losowej,
 * której nikt nie zna). `DROP TABLE tozsamosci_zewnetrzne` zabiera tej osobie
 * jedyną drogę wejścia, jaką zna — zostaje jej odzyskiwanie hasła i wiadomość
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

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ZGODA], $_ENV[self::ZGODA]);
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php',
        );
    }

    private function tabelaIstnieje(): bool
    {
        return Schema::hasTable('tozsamosci_zewnetrzne');
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
        $this->assertSame('109876543210987654321', (string) DB::table('tozsamosci_zewnetrzne')
            ->where('user_id', $basia->getKey())->value('identyfikator'));
        $this->assertTrue($this->tabelaIstnieje());
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma czego stracić, więc nie ma o co pytać.
        $this->migracja()->down();

        $this->assertFalse($this->tabelaIstnieje());

        // Migrujemy z powrotem, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        $this->migracja()->up();

        $this->assertTrue($this->tabelaIstnieje());
    }

    public function test_cofniecie_przechodzi_gdy_wlasciciel_powie_to_wprost(): void
    {
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertFalse($this->tabelaIstnieje(),
            'Świadoma zgoda ma przepuszczać cofnięcie — inaczej migracja jest nie do cofnięcia nigdy.');

        $this->migracja()->up();
    }

    public function test_migracja_sprawdza_czy_wolno_zanim_cokolwiek_skasuje(): void
    {
        // Kolejność w `down()` ma znaczenie: gdyby `dropColumn` stał przed
        // sprawdzeniem, wyjątek leciałby już po utracie danych. Czytamy kod,
        // bo w działaniu tej różnicy nie widać — w obu wersjach leci wyjątek.
        $kod = (string) file_get_contents(database_path(
            'migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php',
        ));

        $sprawdzenie = strpos($kod, '$this->ilePowiazanych()');
        $kasowanie = strpos($kod, 'dropIfExists(self::TABELA)');

        $this->assertNotFalse($sprawdzenie);
        $this->assertNotFalse($kasowanie);
        $this->assertLessThan($kasowanie, $sprawdzenie,
            'Sprawdzenie „czy wolno" musi stać PRZED skasowaniem tabeli.');
    }
}
