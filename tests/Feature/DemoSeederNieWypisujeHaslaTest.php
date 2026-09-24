<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DemoSeeder nie wypisuje hasła z `KUKING_DEMO_HASLO` (issue #1295).
 *
 * CI podaje hasło z sekretu i robi `migrate:fresh --seed`, więc wyjście
 * seedera staje się logiem joba. Test przechwytuje CAŁE wyjście prawdziwego
 * `db:seed` — nie pojedynczą linię — bo hasło może wyciec każdą z nich.
 *
 * Kontrola dodatnia jest w samym teście: ścieżka bez zmiennej w trybie
 * interaktywnym MUSI pokazać wylosowane hasło i to hasło MUSI działać.
 * Gdyby wyjście w ogóle nie niosło haseł (np. przechwytywanie nie działało),
 * ta asercja by oblała, zamiast przepuścić brak jako sukces.
 */
class DemoSeederNieWypisujeHaslaTest extends TestCase
{
    use RefreshDatabase;

    private const FIKCYJNE_HASLO = 'Fikcyjne-Haslo-Z-Sekretu-1295';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Storage::fake('public');
        $this->withoutMockingConsoleOutput();
    }

    /**
     * @param  array<string, mixed>  $opcje
     */
    private function zasiej(array $opcje = []): string
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true] + $opcje);

        return Artisan::output();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function tryby(): array
    {
        return [
            'interaktywnie' => [[]],
            'bez interakcji (CI)' => [['--no-interaction' => true]],
        ];
    }

    /**
     * @param  array<string, mixed>  $opcje
     */
    #[Test]
    #[DataProvider('tryby')]
    public function test_haslo_z_sekretu_nie_trafia_do_wyjscia_zasiewu(array $opcje): void
    {
        config(['kuking.demo.haslo' => self::FIKCYJNE_HASLO]);

        $wyjscie = $this->zasiej($opcje);

        $this->assertStringNotContainsString(self::FIKCYJNE_HASLO, $wyjscie,
            'DemoSeeder wypisał hasło z KUKING_DEMO_HASLO. W CI to wyjście jest logiem joba (#1295).');
        $this->assertStringContainsString('KUKING_DEMO_HASLO', $wyjscie,
            'Komunikat końcowy ma powiedzieć, skąd wziąć hasło.');
        // Lista logowalnych kont zostaje — bez niej zasiew jest bezużyteczny.
        $this->assertStringContainsString('(moderator)', $wyjscie);

        $moderator = User::query()->where('email', 'like', '%@example.test')
            ->where('role', 'moderator')->firstOrFail();
        $this->assertTrue(Hash::check(self::FIKCYJNE_HASLO, (string) $moderator->password),
            'Konta demo mają dalej przyjmować hasło z sekretu — inaczej przeglądarka w CI się nie zaloguje.');
    }

    #[Test]
    public function test_wylosowane_haslo_widac_tylko_w_trybie_interaktywnym(): void
    {
        config(['kuking.demo.haslo' => null]);

        $wyjscie = $this->zasiej();

        $this->assertSame(1, preg_match('/Hasło do wszystkich kont niżej: (\S+)/u', $wyjscie, $trafienie),
            'W trybie interaktywnym operator lokalny ma zobaczyć wylosowane hasło — bez niego nikt go nie odzyska.');
        $moderator = User::query()->where('email', 'like', '%@example.test')
            ->where('role', 'moderator')->firstOrFail();
        $this->assertTrue(Hash::check($trafienie[1], (string) $moderator->password),
            'Wypisane hasło nie otwiera konta moderatora demo.');
    }

    #[Test]
    public function test_wylosowane_haslo_nie_trafia_do_wyjscia_bez_interakcji(): void
    {
        config(['kuking.demo.haslo' => null]);

        $wyjscie = $this->zasiej(['--no-interaction' => true]);

        $this->assertStringNotContainsString('Hasło do wszystkich kont niżej:', $wyjscie);
        $this->assertStringContainsString('KUKING_DEMO_HASLO', $wyjscie,
            'Bez interakcji komunikat ma powiedzieć, jak uzyskać działające hasło.');

        // Żaden token z wyjścia nie otwiera konta moderatora — hasło nie wyciekło
        // w innym miejscu ani w innej formie.
        $moderator = User::query()->where('email', 'like', '%@example.test')
            ->where('role', 'moderator')->firstOrFail();
        foreach (preg_split('/[\s:(),.]+/u', $wyjscie, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) === 16) {
                $this->assertFalse(Hash::check($token, (string) $moderator->password),
                    'Wylosowane hasło trafiło do wyjścia zasiewu bez interakcji.');
            }
        }
    }
}
