<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ten sam adres e-mail nie może założyć dwóch kont (issue #109).
 *
 * CO TU JEST SPRAWDZANE, A CO NIE
 * NIE sprawdzamy mutatora `User::email()` ani `findByLogin()` — te działają
 * i mają własne testy. Sprawdzamy warstwę NIŻEJ: czy ta sama reguła obowiązuje
 * w bazie także wtedy, gdy zapis omija Eloquenta.
 *
 * `$table->string('email')->unique()` porównuje BAJTY, więc dla PostgreSQL
 * `Jan@example.com` i `jan@example.com` to dwa różne adresy. Każdy zapis poza
 * modelem — `DB::table()->insert()`, seeder, przyszły import, ręczna naprawa
 * w `psql` podczas incydentu — mógł więc założyć drugie konto na ten sam adres.
 * Wtedy logowanie trafia raz na jedno konto, raz na drugie, człowiek widzi
 * „zniknięte" przepisy, a link do zmiany hasła dotyczy tylko jednego z nich.
 *
 * AGENTS.md §6: „walidacja w PHP jest dodatkiem, nie zamiennikiem”.
 */
class UnikalnyEmailBezWielkosciLiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_surowy_insert_nie_zaloz_drugiego_konta_na_ten_sam_adres(): void
    {
        User::factory()->create(['email' => 'jan@example.com']);

        $this->expectException(QueryException::class);

        // Celowo z pominięciem modelu: mutator normalizujący adres tu nie
        // działa, więc bez indeksu w bazie ten wiersz po prostu by wszedł.
        $this->wstawSurowo('Jan@Example.com');
    }

    public function test_ten_sam_adres_co_do_znaku_nadal_jest_odbijany(): void
    {
        // Stary indeks `users_email_unique` ZOSTAJE i ma dalej działać —
        // obsługuje zwykłe `where('email', ?)` z `findByLogin()`.
        User::factory()->create(['email' => 'jan@example.com']);

        $this->expectException(QueryException::class);

        $this->wstawSurowo('jan@example.com');
    }

    public function test_rozne_adresy_wchodza_bez_przeszkody(): void
    {
        // Bez tego testu indeks mógłby być tak szeroki, że blokuje wszystko —
        // asercja „coś się nie udało" sama w sobie niczego nie dowodzi.
        User::factory()->create(['email' => 'jan@example.com']);

        $this->wstawSurowo('Janina@Example.com');

        $this->assertDatabaseCount('users', 2);
    }

    public function test_rejestracja_przez_model_dalej_normalizuje_adres(): void
    {
        // Indeks pilnuje bazy, ale zapisany adres ma zostać mały — inaczej
        // przy każdym logowaniu porównywalibyśmy dwie różne wartości.
        $user = User::factory()->create(['email' => 'Jan@Example.COM']);

        $this->assertSame('jan@example.com', $user->refresh()->email);
        $this->assertTrue(User::findByLogin('JAN@example.com')?->is($user) ?? false);
    }

    public function test_baza_ma_indeks_funkcyjny_a_nie_tylko_zwykly(): void
    {
        // Asercja na samym wyjątku wyżej przeszłaby też wtedy, gdyby ktoś
        // rozwiązał to triggerem albo CHECK-iem. Ten test mówi wprost, CZEGO
        // oczekujemy — i jest miejscem, w którym widać nazwę indeksu, gdy
        // trzeba go poszukać w `psql`.
        $indeksy = collect(DB::select(
            'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            ['users', 'users_email_lower_unique'],
        ));

        $this->assertCount(1, $indeksy, 'Brak indeksu users_email_lower_unique.');
        $this->assertStringContainsString('UNIQUE', (string) $indeksy->first()->indexdef);
        $this->assertStringContainsString('lower', (string) $indeksy->first()->indexdef);
    }

    private function wstawSurowo(string $email): void
    {
        DB::table('users')->insert([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'password' => Hash::make('tajne-haslo-123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
