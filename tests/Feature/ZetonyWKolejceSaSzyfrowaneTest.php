<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\PolecenieZadania;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\UstawienieHaslaZamiastLinku;
use App\Notifications\UstawienieNowegoHasla;
use App\Notifications\ZaproszenieDoZalozeniaKonta;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as Powiadomienie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Żetony jednorazowe nie leżą jawnie w `jobs` ani w `failed_jobs` (audyt A5-10).
 *
 * Link do logowania, reset hasła i zaproszenie idą przez kolejkę `database`.
 * Bez `ShouldBeEncrypted` żeton stał w `jobs.payload` w postaci jawnej,
 * a przy nieudanej wysyłce zostawał w `failed_jobs` — zrzut bazy dawał gotowe
 * wejście na cudze konto. Teraz ładunek jest szyfrowany kluczem aplikacji.
 *
 * Ładunek jest prawdziwy: powiadomienie idzie na prawdziwą kolejkę `database`,
 * test niczego nie wpisuje ręcznie (patrz `MartweZadaniaTest`).
 */
class ZetonyWKolejceSaSzyfrowaneTest extends TestCase
{
    use RefreshDatabase;

    private const ZETON = 'ZETONDOTESTUA510x7c2e';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /** @return array<string, array{0: \Closure(string): Powiadomienie}> */
    public static function powiadomieniaZZetonem(): array
    {
        return [
            'link do logowania' => [fn (string $z): Powiadomienie => new LinkDoLogowania($z)],
            'zaproszenie' => [fn (string $z): Powiadomienie => new ZaproszenieDoZalozeniaKonta($z)],
            'ustaw nowe hasło' => [fn (string $z): Powiadomienie => new UstawienieNowegoHasla($z)],
            'hasło zamiast linku' => [fn (string $z): Powiadomienie => new UstawienieHaslaZamiastLinku($z)],
        ];
    }

    #[DataProvider('powiadomieniaZZetonem')]
    public function test_zeton_nie_lezy_jawnie_w_ladunku_kolejki(\Closure $zrob): void
    {
        $powiadomienie = $zrob(self::ZETON);

        $this->assertInstanceOf(ShouldBeEncrypted::class, $powiadomienie);

        Notification::route('mail', 'maria@przyklad.pl')->notify($powiadomienie);

        $ladunek = (string) DB::table('jobs')->value('payload');

        $this->assertNotSame('', $ladunek, 'Powiadomienie miało trafić do kolejki `database`.');
        $this->assertStringNotContainsString(self::ZETON, $ladunek);

        // KONTROLA DODATNIA: żeton naprawdę jest w ładunku — po odszyfrowaniu.
        // Bez tego asercja wyżej przechodziłaby także przy pustym ładunku.
        $polecenie = (string) (json_decode($ladunek, true)['data']['command'] ?? '');
        $this->assertStringContainsString(self::ZETON, PolecenieZadania::zserializowane($polecenie));
    }

    public function test_zaszyfrowane_zadanie_dalej_wysyla_list(): void
    {
        config(['mail.default' => 'array']);

        $uzytkownik = User::factory()->create(['email' => 'maria@przyklad.pl']);
        Profile::query()->updateOrCreate(
            ['user_id' => $uzytkownik->getKey()],
            ['username' => 'maria_a510', 'display_name' => 'Maria'],
        );

        // Żeton musi istnieć, bo `UstawienieNowegoHasla::shouldSend` sprawdza go
        // w brokerze — inaczej list nie wyszedłby z powodu, który tu nie jest mierzony.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $uzytkownik->email],
            ['token' => Hash::make(self::ZETON), 'created_at' => now()],
        );

        $uzytkownik->notify(new UstawienieNowegoHasla(self::ZETON));

        // Listy wejścia idą na `high` (B8-05) — worker czyta ją jak na produkcji.
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'high,default', '--once' => true, '--tries' => 1]);

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Worker nie odczytał zaszyfrowanego zadania.');
        $this->assertSame(0, DB::table('jobs')->count());

        $wyslane = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $wyslane);
        $this->assertStringContainsString(self::ZETON, $wyslane->first()->toString());
    }
}
