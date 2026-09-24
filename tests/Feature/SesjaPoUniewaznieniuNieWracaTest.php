<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * #1046: żądanie rozpoczęte PRZED unieważnieniem sesji nie może jej odtworzyć.
 *
 * DWA PRAWDZIWE PROCESY, NIE SYMULACJA ZAPISU. Wolne żądanie to osobny proces
 * PHP z realnym kernelem, database sessions i zaszyfrowanymi cookies
 * (`tests/Support/remember-request.php`). Zatrzymuje się na barierze PO
 * kontrolerze, a PRZED zapisem sesji przez `StartSession` — w tym czasie
 * drugi proces wykonuje operację bezpieczeństwa, potem bariera puszcza
 * i wolne żądanie kończy się zapisem sesji wczytanej przed odwołaniem.
 *
 * KTÓRE ŻĄDANIE NAPRAWDĘ WSKRZESZA. Zwykły GET z istniejącą sesją zapisuje
 * się `UPDATE`-em (`DatabaseSessionHandler::write()`, `exists = true`), więc
 * po skasowaniu wiersza trafia w zero wierszy. Wiersz wraca `INSERT`-em, gdy
 * żądanie w trakcie NADAJE NOWY identyfikator: logowanie z ciasteczka
 * „zapamiętaj mnie” (`SessionGuard::updateSession()` → `migrate(true)`),
 * logowanie hasłem, linkiem, 2FA. Dlatego wolne żądanie niesie tu SAMO
 * ciasteczko zapamiętania — tak wygląda obca przeglądarka, której sesja
 * wygasła, a recaller jeszcze nie.
 */
class SesjaPoUniewaznieniuNieWracaTest extends TestCase
{
    use DatabaseMigrations;

    private const HASLO = 'haslo-testowe-123';

    private const NOWE_HASLO = 'nowe-bezpieczne-haslo-1046';

    private function konfiguracja(): array
    {
        return [
            'database.default' => 'pgsql', 'database.connections.pgsql' => config('database.connections.pgsql'),
            'app.key' => config('app.key'), 'app.debug' => false,
            'session.driver' => 'database', 'session.secure' => false,
            'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
            'kuking.turnstile.klucz_publiczny' => '', 'kuking.turnstile.sekret' => '',
        ];
    }

    private function proces(array $jar, string $method, string $path, array $data = [], array $dodatkowe = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/remember-request.php')], base_path(), null, null, 60);
        $process->setInput(json_encode([
            'cookies' => $jar, 'method' => $method, 'path' => $path, 'data' => $data,
            'config' => $this->konfiguracja(),
        ] + $dodatkowe, JSON_THROW_ON_ERROR));

        return $process;
    }

    private function odpowiedz(Process $process, array &$jar): array
    {
        // Nie wypisujemy odpowiedzi: zawiera cookies i token formularza.
        $this->assertSame(0, $process->getExitCode(), 'Proces HTTP zakończył się błędem.');
        $response = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $jar = array_filter(array_replace($jar, $response['cookies']), fn ($value) => $value !== null);

        return $response;
    }

    private function request(array &$jar, string $method, string $path, array $data = [], array $dodatkowe = []): array
    {
        $process = $this->proces($jar, $method, $path, $data, $dodatkowe);
        $process->run();

        return $this->odpowiedz($process, $jar);
    }

    /**
     * Start wolnego żądania: wraca dopiero, gdy proces wczytał sesję,
     * uwierzytelnił się i przeszedł kontroler — ale jeszcze niczego nie zapisał.
     *
     * @return array{Process, string}
     */
    private function wolneZadanie(array $jar): array
    {
        $katalog = sys_get_temp_dir().'/kuking-1046-'.bin2hex(random_bytes(6));
        mkdir($katalog);
        $process = $this->proces($jar, 'GET', '/ustawienia/bezpieczenstwo', [], ['bariera' => $katalog]);
        $process->start();

        $koniec = microtime(true) + 30;
        while (! file_exists($katalog.'/wczytane') && $process->isRunning() && microtime(true) < $koniec) {
            usleep(20_000);
        }
        $this->assertFileExists($katalog.'/wczytane', 'Wolne żądanie nie dotarło do bariery.');

        return [$process, $katalog];
    }

    private function dokoncz(array $wolne, array &$jar): array
    {
        [$process, $katalog] = $wolne;
        touch($katalog.'/dalej');
        $process->wait();
        @unlink($katalog.'/wczytane');
        @unlink($katalog.'/dalej');
        @rmdir($katalog);

        return $this->odpowiedz($process, $jar);
    }

    private function token(array $response): string
    {
        $this->assertSame(200, $response['status']);
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $response['body'], $matches));

        return $matches[1];
    }

    private function login(User $user): array
    {
        $jar = [];
        $token = $this->token($this->request($jar, 'GET', '/login'));
        $this->assertSame(302, $this->request($jar, 'POST', '/login', ['_token' => $token, 'login' => $user->email, 'password' => self::HASLO])['status']);
        $this->assertSame(200, $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        return $jar;
    }

    /** Sama „zapamiętana” przeglądarka: sesja wygasła, recaller jeszcze nie. */
    private function tylkoRecaller(array $jar): array
    {
        $remembered = array_filter($jar, fn ($name) => str_starts_with($name, 'remember_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $remembered);

        return $remembered;
    }

    private function kodStrony(array &$jar, array $dodatkowe = []): int
    {
        return $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo', [], $dodatkowe)['status'];
    }

    /** Operacja bezpieczeństwa wykonana w trakcie wolnego żądania. */
    private function odwolaj(string $operacja, User $user, array &$wlasciciel): void
    {
        switch ($operacja) {
            case 'wyloguj-inne':
                $token = $this->token($this->request($wlasciciel, 'GET', '/ustawienia/bezpieczenstwo'));
                $this->assertSame(302, $this->request($wlasciciel, 'POST', '/ustawienia/bezpieczenstwo/wyloguj-inne', ['_token' => $token, 'password' => self::HASLO])['status']);
                break;
            case 'zmiana-hasla':
                $token = $this->token($this->request($wlasciciel, 'GET', '/ustawienia/bezpieczenstwo'));
                $this->assertSame(302, $this->request($wlasciciel, 'PUT', '/ustawienia/bezpieczenstwo/haslo', ['_token' => $token, 'current_password' => self::HASLO, 'password' => self::NOWE_HASLO, 'password_confirmation' => self::NOWE_HASLO])['status']);
                break;
            case 'reset-hasla':
                $reset = Password::createToken($user);
                $gosc = [];
                $token = $this->token($this->request($gosc, 'GET', '/login'));
                $response = $this->request($gosc, 'POST', '/nowe-haslo', ['_token' => $token, 'token' => $reset, 'email' => $user->email, 'password' => self::NOWE_HASLO, 'password_confirmation' => self::NOWE_HASLO]);
                $this->assertSame('http://localhost/login', $response['location']);
                break;
            default:
                // ban / suspend / markForDeletion — moderator albo automat, nie
                // właściciel. Konto wraca potem do `active`, żeby status nie
                // maskował powrotu sesji (ten sam chwyt co w #584).
                $user->{$operacja}();
                $operacja === 'markForDeletion' ? $user->cancelDeletion() : $user->reinstate();
        }
    }

    public static function operacje(): array
    {
        return [
            'wyloguj inne urządzenia' => ['wyloguj-inne', true],
            'zmiana hasła' => ['zmiana-hasla', true],
            'reset hasła' => ['reset-hasla', false],
            'ban' => ['ban', false],
            'zawieszenie' => ['suspend', false],
            'zgłoszenie usunięcia' => ['markForDeletion', false],
        ];
    }

    #[DataProvider('operacje')]
    public function test_wolne_zadanie_nie_odtwarza_odwolanej_sesji(string $operacja, bool $zachowujeBiezaca): void
    {
        $user = $this->user();
        $wlasciciel = $this->login($user);
        $obca = $this->tylkoRecaller($this->login($user));
        $tokenPrzed = $user->fresh()->getRememberToken();

        $wolne = $this->wolneZadanie($obca);
        $this->odwolaj($operacja, $user, $wlasciciel);
        $koniec = $this->dokoncz($wolne, $obca);

        // Kontrola dodatnia, że wyścig naprawdę zaszedł: wolne żądanie
        // przeszło kontroler zalogowane i ZAPISAŁO świeży wiersz sesji
        // z `user_id` po operacji, która sesje tego konta skasowała.
        $this->assertSame(200, $koniec['status']);
        $this->assertTrue(
            DB::table('sessions')->where('user_id', $user->getKey())->count() >= ($zachowujeBiezaca ? 2 : 1),
            'Wolne żądanie nie zapisało sesji — test niczego nie zmierzył.',
        );

        $this->assertSame(302, $this->kodStrony($obca), 'Odwołana sesja wróciła po zapisie wolnego żądania.');
        $this->assertSame(302, $this->kodStrony($obca), 'Odwołana sesja wróciła przy kolejnym żądaniu.');
        $this->assertFalse(hash_equals($tokenPrzed, (string) $user->fresh()->getRememberToken()), 'Rotacja remember_token przestała działać.');

        if ($zachowujeBiezaca) {
            $this->assertSame(200, $this->kodStrony($wlasciciel), 'Bieżąca sesja właściciela nie przetrwała.');
            $this->assertSame(1, DB::table('sessions')->where('user_id', $user->getKey())->count(), 'Poza bieżącą przetrwała inna sesja.');
        }
    }

    /**
     * Kontrola dodatnia: bez odwołania wolne i zwykłe żądania tej samej
     * osoby działają RÓWNOLEGLE — poprawka niczego nie serializuje.
     */
    public function test_bez_odwolania_rownolegle_zadania_dzialaja(): void
    {
        $user = $this->user();
        $wlasciciel = $this->login($user);
        $obca = $this->tylkoRecaller($this->login($user));

        $wolne = $this->wolneZadanie($obca);
        // Drugie żądanie tego samego konta kończy się, gdy pierwsze stoi.
        $this->assertSame(200, $this->kodStrony($wlasciciel));
        $this->assertTrue($wolne[0]->isRunning(), 'Wolne żądanie skończyło się przed czasem — nie było równoległości.');
        $this->assertSame(200, $this->dokoncz($wolne, $obca)['status']);

        $this->assertSame(200, $this->kodStrony($obca));
        $this->assertSame(200, $this->kodStrony($wlasciciel));
    }

    /**
     * Kontrola ujemna: ten sam przebieg ze strażnikiem generacji podmienionym
     * na przepust odtwarza powrót starej sesji — czyli test mierzy błąd, a nie
     * coś, co przechodziłoby zawsze.
     */
    public function test_bez_straznika_generacji_sesja_wraca(): void
    {
        $user = $this->user();
        $wlasciciel = $this->login($user);
        $obca = $this->tylkoRecaller($this->login($user));

        $wolne = $this->wolneZadanie($obca);
        $this->odwolaj('wyloguj-inne', $user, $wlasciciel);
        $this->assertSame(200, $this->dokoncz($wolne, $obca)['status']);

        $this->assertSame(200, $this->kodStrony($obca, ['bez_straznika_generacji' => true]), 'Kontrola ujemna nie odtworzyła błędu.');
    }
}
