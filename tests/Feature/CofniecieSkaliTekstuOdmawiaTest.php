<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji rozszerzającej skalę tekstu w dół nie podnosi po cichu
 * cudzego ustawienia (D-088).
 *
 * DLACZEGO TA MIGRACJA WYMAGA STRAŻNIKA, CHOĆ NICZEGO NIE KASUJE
 * Cofnięcie zwęża CHECK z `BETWEEN 70 AND 140` do `BETWEEN 90 AND 140`. Samo
 * `ALTER TABLE` odbije się o bazę, gdy choć jedno konto ma zapisane 70 albo
 * 80 — i tu jest pułapka: najprostszym sposobem „naprawienia" tego cofnięcia
 * jest podniesienie tym kontom skali do 90 po cichu. Człowiek, który
 * świadomie ustawił sobie mniejszy tekst, zobaczyłby nagle większy, bez słowa
 * wyjaśnienia. A `down()` prawie nigdy nie występuje sam: po nim idzie
 * kolejny `migrate`, CHECK wraca i NIE MA BŁĘDU DO ZAUWAŻENIA.
 *
 * Test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która nie
 * cofa się nigdy, blokowałaby staging i lokalne bazy bez powodu — i jest
 * błędem tej samej wagi w drugą stronę.
 */
class CofniecieSkaliTekstuOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_PODNIES_SKALE_TEKSTU';

    private const PLIK = 'migrations/2026_09_11_600000_rozszerz_skale_tekstu_w_dol.php';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ZGODA], $_ENV[self::ZGODA]);
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /**
     * Czy baza przyjmuje dziś daną skalę.
     *
     * ZAGNIEŻDŻONA TRANSAKCJA NIE JEST OZDOBNIKIEM. Sprawdzamy to jedynym
     * uczciwym sposobem — próbą zapisu, która ma się odbić o CHECK — a
     * w PostgreSQL nieudane zapytanie przerywa CAŁĄ transakcję, tę samą,
     * w której `RefreshDatabase` trzyma test. Bez SAVEPOINT-a każde następne
     * zapytanie, także zwykły SELECT w asercji, kończyłoby się
     * `SQLSTATE[25P02] current transaction is aborted`. `catch` uspokaja PHP,
     * nie bazę.
     */
    private function bazaPrzyjmuje(int $skala): bool
    {
        $user = User::factory()->create();

        DB::beginTransaction();

        try {
            DB::table('users')->where('id', $user->getKey())->update(['text_scale' => $skala]);
        } catch (\Throwable) {
            DB::rollBack();

            return false;
        }

        DB::rollBack();

        return true;
    }

    public function test_po_migracji_baza_przyjmuje_siedemdziesiat_i_odbija_mniej(): void
    {
        $this->assertTrue($this->bazaPrzyjmuje(70),
            'Najmniejszy oferowany rozmiar musi dać się zapisać — inaczej opcja w ustawieniach kłamie.');

        $this->assertFalse($this->bazaPrzyjmuje(69),
            'Dolna granica ma nadal obowiązywać; bez niej CHECK przestaje cokolwiek pilnować.');
    }

    public function test_cofniecie_odmawia_gdy_ktos_ma_mniejszy_tekst(): void
    {
        $basia = User::factory()->create();
        DB::table('users')->where('id', $basia->getKey())->update(['text_scale' => 80]);

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM (D-133):
        // `$this->fail()` rzuca `AssertionFailedError`, a ta dziedziczy przez
        // `PHPUnit\Framework\Exception` po `RuntimeException`, więc
        // postawiona wewnątrz `try` wpadłaby do własnego `catch`.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i podniosło komuś rozmiar tekstu.');

        // Komunikat ma powiedzieć, ILU osób to dotyczy, CO ZROBIĆ ZAMIAST
        // TEGO i jak powiedzieć wprost „wiem, co robię".
        //
        // JEDNO konto, nie pięć: liczba stoi na końcu zdania, za rzeczownikiem
        // w mianowniku, więc jedynka jest tu poprawna po polsku (D-132).
        $this->assertStringContainsString(
            'Liczba kont, których to dotyczy: 1.',
            $odmowa->getMessage(),
        );

        // Stara, niegramatyczna forma nie ma prawa wrócić.
        $this->assertStringNotContainsString('na 1 kontach', $odmowa->getMessage());

        $this->assertStringContainsString('config/kuking.php', $odmowa->getMessage());
        $this->assertStringContainsString(self::ZGODA, $odmowa->getMessage());

        // NAJWAŻNIEJSZE: ustawienie nadal jest takie, jakie człowiek wybrał.
        // Odmowa, która i tak zdążyła je zmienić, byłaby tylko ładniejszym
        // komunikatem o stracie.
        $this->assertSame(80, (int) DB::table('users')->where('id', $basia->getKey())->value('text_scale'));
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nikt nie zszedł poniżej 90, więc nie ma czego stracić i nie ma o co
        // pytać. To jest kontrola dodatnia dla strażnika wyżej.
        $this->migracja()->down();

        $this->assertFalse($this->bazaPrzyjmuje(70),
            'Po cofnięciu baza ma znów odbijać 70 — inaczej `down()` nic nie cofnął.');

        $this->migracja()->up();

        $this->assertTrue($this->bazaPrzyjmuje(70));
    }

    public function test_cofniecie_przechodzi_gdy_wlasciciel_powie_to_wprost(): void
    {
        $basia = User::factory()->create();
        DB::table('users')->where('id', $basia->getKey())->update(['text_scale' => 70]);

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        // Zgoda wypowiedziana wprost podnosi skalę do dolnej granicy — i to
        // jest cena, o której komunikat odmowy uprzedza.
        $this->assertSame(90, (int) DB::table('users')->where('id', $basia->getKey())->value('text_scale'));

        $this->migracja()->up();
    }

    public function test_cofniecie_nie_rusza_kont_powyzej_progu(): void
    {
        // Odmowa i naprawa mają być WĄSKIE (D-088): konto z rozmiarem, którego
        // ta migracja nie dotyczy, nie może zostać przestawione przy okazji.
        $duzy = User::factory()->create();
        DB::table('users')->where('id', $duzy->getKey())->update(['text_scale' => 140]);

        $maly = User::factory()->create();
        DB::table('users')->where('id', $maly->getKey())->update(['text_scale' => 70]);

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertSame(140, (int) DB::table('users')->where('id', $duzy->getKey())->value('text_scale'),
            'Cofnięcie ruszyło konto, którego ta migracja w ogóle nie dotyczy.');

        $this->migracja()->up();
    }
}
