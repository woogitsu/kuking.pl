<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie zdejmuje po cichu drugiego składnika (DB-01, D-238).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Migracja `2026_09_06_120000_add_two_factor_to_users_table` kasowała w `down()`
 * wszystkie cztery kolumny 2FA bez żadnego warunku. Jej własny komentarz bronił
 * tego zdaniem, że „nikt nie zostaje zablokowany, bo wymóg drugiego składnika
 * znika razem z kolumnami" — i to jest prawda, która maskuje problem. Cofnięcie
 * nie wybija nikogo z serwisu; ono ZDEJMUJE OCHRONĘ. Konto moderatora, o którym
 * właściciel wie, że jest chronione dwoma składnikami, wraca do samego hasła,
 * a sekret TOTP i kody zapasowe przepadają bezpowrotnie — są zaszyfrowane i nie
 * ma ich skąd odtworzyć.
 *
 * Zasada D-088 nazywa to wprost: `down()` nie ma prawa przywracać stanu
 * groźnego. W repozytorium pilnuje jej dziś kilkanaście testów `Cofniecie*` —
 * dla dziennika zgód, zaproszeń, zgłoszeń prawnych, tożsamości Google
 * i Facebooka. Dla 2FA, czyli dla najbardziej wrażliwej z tych wartości,
 * nie było ani jednego.
 *
 * Ten test sprawdza OBIE strony granicy. Sama odmowa nie wystarczy: migracja,
 * która nigdy się nie cofa, blokowałaby `migrate:refresh` w CI i u dewelopera,
 * gdzie nie ma czego stracić.
 */
class CofniecieMigracji2faOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'migrations/2026_09_06_120000_add_two_factor_to_users_table.php';

    private function migracja(): object
    {
        return require database_path(self::SCIEZKA);
    }

    /** Konto z 2FA NAPRAWDĘ włączonym — sekret i potwierdzenie razem. */
    private function kontoZPotwierdzonym2fa(string $username = 'moderatorka'): void
    {
        $osoba = $this->user($username);

        // Sekret MUSI iść razem z potwierdzeniem: pilnuje tego CHECK
        // `users_two_factor_confirmed_requires_secret_check` z tej samej migracji.
        DB::table('users')->where('id', $osoba->getKey())->update([
            'two_factor_secret' => 'zaszyfrowany-sekret',
            'two_factor_backup_codes' => 'zaszyfrowane-kody',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_cofniecie_odmawia_gdy_ktos_ma_potwierdzone_2fa(): void
    {
        $this->kontoZPotwierdzonym2fa();

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM (D-133):
        // `$this->fail()` rzuca `AssertionFailedError`, a ta dziedziczy przez
        // `PHPUnit\Framework\Exception` po `RuntimeException`, więc postawiona
        // wewnątrz `try` wpadłaby do własnego `catch`.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało sekrety 2FA.');

        // Komunikat ma mówić, ILU kont to dotyczy i CO ZROBIĆ. JEDNO konto,
        // nie pięć: liczba stoi na końcu zdania, za rzeczownikiem w mianowniku,
        // więc jedynka jest tu poprawna po polsku (D-132).
        $this->assertStringContainsString(
            'Liczba kont z potwierdzoną weryfikacją dwuetapową: 1.',
            $odmowa->getMessage(),
        );

        // Niegramatyczna forma „1 kont" nie ma prawa się tu pojawić.
        $this->assertStringNotContainsString('1 kont ', $odmowa->getMessage());

        $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK', $odmowa->getMessage());
        $this->assertStringContainsString('SELECT id, email FROM users', $odmowa->getMessage());

        // NAJWAŻNIEJSZE: dane nadal są. Odmowa, która i tak zdążyła skasować
        // kolumny, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertSame(1, DB::table('users')->whereNotNull('two_factor_confirmed_at')->count());
        $this->assertSame(1, DB::table('users')->whereNotNull('two_factor_secret')->count());
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma czego stracić, więc nie ma o co pytać. Migracja odmawiająca
        // ZAWSZE zablokowałaby `migrate:refresh` w CI i lokalnie bez powodu —
        // a ten krok chodzi w `scripts/check.sh` przed każdym pchnięciem.
        $this->migracja()->down();

        $kolumny = DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['users', 'two_factor_secret'],
        );

        $this->assertSame([], $kolumny, 'Cofnięcie nie usunęło kolumny two_factor_secret.');

        // Wracamy, żeby nie zostawić bazy w połowie drogi dla kolejnych
        // testów w tym samym procesie.
        $this->migracja()->up();
    }

    public function test_sam_sekret_bez_potwierdzenia_nie_blokuje_cofniecia(): void
    {
        // Sekret zapisany BEZ potwierdzenia to konto W TRAKCIE włączania 2FA —
        // ekran włączenia pokazuje sekret, zanim człowiek wpisze pierwszy kod.
        // To nie jest ochrona, którą można stracić: po cofnięciu człowiek po
        // prostu zaczyna włączanie od nowa. Gdyby strażnik liczył sam sekret,
        // jedno porzucone włączanie blokowałoby rollback na stałe.
        $osoba = $this->user('zaczynajaca');

        DB::table('users')->where('id', $osoba->getKey())->update([
            'two_factor_secret' => 'sekret-jeszcze-niepotwierdzony',
            'two_factor_confirmed_at' => null,
        ]);

        $this->migracja()->down();

        $kolumny = DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['users', 'two_factor_secret'],
        );

        $this->assertSame([], $kolumny, 'Niepotwierdzony sekret zablokował cofnięcie.');

        $this->migracja()->up();
    }

    public function test_furtka_przepuszcza_cofniecie_gdy_ktos_swiadomie_ja_ustawil(): void
    {
        $this->kontoZPotwierdzonym2fa();

        // `putenv()`, bo strażnik czyta `getenv()` — a czyta `getenv()`, bo na
        // produkcji konfiguracja bywa zbuforowana (`config:cache`) i `env()`
        // zwracałby wtedy `null` dokładnie tam, gdzie furtka jest potrzebna.
        putenv('KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK=1');

        try {
            $this->migracja()->down();

            $kolumny = DB::select(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                ['users', 'two_factor_secret'],
            );

            $this->assertSame([], $kolumny, 'Furtka nie przepuściła cofnięcia.');

            $this->migracja()->up();
        } finally {
            // Bez tego zmienna zostaje w procesie i ROZBRAJA STRAŻNIKA
            // w kolejnych testach tej samej baterii — fałszywa zieleń
            // dokładnie tam, gdzie mierzymy odmowę.
            putenv('KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK');
        }
    }

    public function test_straznik_stoi_przed_kazda_operacja_niszczaca(): void
    {
        // Kolejność w `down()` ma znaczenie i w działaniu tej różnicy nie widać:
        // w obu wersjach leci wyjątek, tylko w złej — już po utracie danych.
        // Zdjęcie CHECK-a liczy się tak samo jak `dropColumn`: po nim schemat
        // nie pilnuje już niezmiennika, który ta migracja wprowadziła.
        $kod = (string) file_get_contents(database_path(self::SCIEZKA));

        $poczatekDown = strpos($kod, 'public function down(): void');
        $this->assertNotFalse($poczatekDown);

        $sprawdzenie = strpos($kod, "DB::table('users')->whereNotNull('two_factor_confirmed_at')->count()", $poczatekDown);
        $zdjecieChecku = strpos($kod, 'DROP CONSTRAINT IF EXISTS users_two_factor_confirmed_requires_secret_check', $poczatekDown);
        $kasowanieKolumn = strpos($kod, '$table->dropColumn(', $poczatekDown);

        $this->assertNotFalse($sprawdzenie, 'W down() nie ma sprawdzenia liczby kont z potwierdzonym 2FA.');
        $this->assertNotFalse($zdjecieChecku);
        $this->assertNotFalse($kasowanieKolumn);

        $this->assertLessThan(
            $zdjecieChecku,
            $sprawdzenie,
            'Sprawdzenie stoi PO zdjęciu CHECK-a — schemat przestaje pilnować niezmiennika, zanim ktokolwiek zaprotestuje.',
        );

        $this->assertLessThan(
            $kasowanieKolumn,
            $sprawdzenie,
            'Sprawdzenie stoi PO dropColumn — wyjątek poleciałby już po utracie sekretów.',
        );
    }
}
